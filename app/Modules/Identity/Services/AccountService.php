<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Identity\Security\BreachChecker;
use WBS\Identity\Security\PasswordHasher;
use WBS\Identity\Security\PhoneNormalizer;
use WBS\Referrals\Services\SponsorResolver;
use WBS\Referrals\Services\SponsorshipService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Account lifecycle: registration, profile, consent, verification
 * (SRS FR-ID-001/002/008).
 *
 *  - Registration collects only what the purpose needs, captures consent with a
 *    policy version, and never stores raw secrets (Argon2id hash only).
 *  - Email AND E.164-normalized phone are unique per organization according to
 *    the configurable identity policy; a collision is a soft error, never a
 *    silent overwrite/merge (merge is a separate audited review workflow).
 *  - Minor/age gating is enforced per jurisdiction (country_code) from policy.
 *  - Social-only accounts may have a null password.
 */
final class AccountService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly PasswordHasher $hasher,
        private readonly PhoneNormalizer $phone,
        private readonly IdentityPolicyService $policies,
        private readonly ?BreachChecker $breachChecker = null,
        private readonly ?SponsorResolver $sponsorResolver = null,
        private readonly ?SponsorshipService $sponsorships = null,
        private readonly ?JourneySignalPort $journeySignals = null,
    ) {
    }

    /**
     * Register a new account.
     *
     * Every new registration is given a SPONSOR (upline): the caller's explicit
     * `sponsor_id` (a referral referrer / ?sponsor=) when valid, else the
     * hierarchical leader — or delegate — of the target `group_id`, resolved by
     * walking UP the group hierarchy ({@see SponsorResolver}). The edge is
     * written through {@see SponsorshipService::assign()} so acyclicity and the
     * single-active invariant hold. Sponsor linking is best-effort: it never
     * fails or rolls back a successful account creation, and is a no-op when the
     * referrals collaborators are not wired or no eligible leader exists yet
     * (e.g. the organization's very first account).
     *
     * @param array<string,mixed> $data email, phone, phone_region, password,
     *   display_name, locale, timezone, date_of_birth, country_code,
     *   consents[[purpose,policy_version]], sponsor_id, group_id
     */
    public function register(string $organizationId, array $data): Result
    {
        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : null;
        if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Result::fail('INVALID_EMAIL', 'identity.invalid_email', 422);
        }
        $email = ($email === '') ? null : $email;
        if ($email === null && empty($data['social']) && empty($data['phone'])) {
            return Result::fail('EMAIL_REQUIRED', 'identity.email_required', 422);
        }

        // Resolve the effective identity policy for this jurisdiction.
        $country = isset($data['country_code']) ? strtoupper(trim((string) $data['country_code'])) : null;
        $policy  = $this->policies->resolve($organizationId, $country);

        // FR-ID-002: normalize the telephone number to E.164 before any
        // uniqueness comparison. National numbers use the policy default region.
        $phone       = null;
        $phoneInput  = null;
        $phoneRegion = isset($data['phone_region']) && $data['phone_region'] !== ''
            ? strtoupper((string) $data['phone_region'])
            : ($policy['phone_default_region'] ?? null);
        if (! empty($data['phone'])) {
            $phoneInput = (string) $data['phone'];
            $phone      = $this->phone->normalize($phoneInput, $phoneRegion);
            if ($phone === null) {
                return Result::fail('INVALID_PHONE', 'identity.invalid_phone', 422);
            }
        }

        // FR-ID-002: minor / age gating per jurisdiction.
        [$dob, $isMinor, $ageError] = $this->evaluateAge($data['date_of_birth'] ?? null, $policy);
        if ($ageError !== null) {
            return Result::fail('AGE_POLICY', $ageError, 422);
        }

        // FR-ID-002: configurable uniqueness. A collision is a soft error, never
        // a silent merge. The controller maps these to a generic public message.
        if ($email !== null && ! empty($policy['require_email_unique'])) {
            $exists = $this->db->table('users')
                ->where('organization_id', $organizationId)->where('email', $email)
                ->countAllResults() > 0;
            if ($exists) {
                return Result::fail('EMAIL_TAKEN', 'identity.email_taken', 409);
            }
        }
        if ($phone !== null && ! empty($policy['require_phone_unique'])) {
            $exists = $this->db->table('users')
                ->where('organization_id', $organizationId)->where('phone', $phone)
                ->countAllResults() > 0;
            if ($exists) {
                return Result::fail('PHONE_TAKEN', 'identity.phone_taken', 409);
            }
        }

        $passwordHash = null;
        if (! empty($data['password'])) {
            $pwError = $this->passwordPolicy((string) $data['password']);
            if ($pwError !== null) {
                return Result::fail('WEAK_PASSWORD', $pwError, 422);
            }
            $passwordHash = $this->hasher->hash((string) $data['password']);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();

        // users-writer parity (field-sync): absent locale/timezone fall back to
        // the ORG's defaults (same source the group-join and contact-promotion
        // writers use), then platform 'en'/'UTC'.
        $org = $this->db->table('organizations')
            ->where('id', $organizationId)->get()->getRowArray();

        // FR-ID-009 lifecycle: a staff-captured lead starts as `prospect`; a
        // self-registration starts as `pending_verification` and is promoted to
        // `active` once a channel is verified (markEmailVerified). An explicit
        // `initial_status` (e.g. social sign-in) may override.
        $status = 'pending_verification';
        if (! empty($data['prospect'])) {
            $status = 'prospect';
        }
        if (! empty($data['initial_status']) && in_array((string) $data['initial_status'], ['prospect', 'pending_verification', 'active'], true)) {
            $status = (string) $data['initial_status'];
        }

        $this->db->transStart();
        try {
            $this->db->table('users')->insert([
                'id'                => $id,
                'organization_id'   => $organizationId,
                'email'             => $email,
                'email_verified'    => 0,
                'phone'             => $phone,
                'phone_verified'    => 0,
                'phone_input'       => $phoneInput,
                'phone_region'      => $phoneRegion,
                'date_of_birth'     => $dob,
                'is_minor'          => $isMinor ? 1 : 0,
                'country_code'      => $country ?: null,
                'password_hash'     => $passwordHash,
                'display_name'      => $data['display_name'] ?? null,
                'status'            => $status,
                'status_reason'     => 'registration',
                'status_changed_at' => $now,
                'locale'            => $data['locale'] ?? (is_array($org) ? (string) ($org['default_locale'] ?? 'en') : 'en'),
                'timezone'          => $data['timezone'] ?? (is_array($org) ? (string) ($org['timezone'] ?? 'UTC') : 'UTC'),
                'mfa_enabled'       => 0,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            // FR-ID-009: seed the append-only transition evidence.
            $this->db->table('account_state_transitions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'user_id'         => $id,
                'from_status'     => null,
                'to_status'       => $status,
                'reason'          => 'registration',
                'actor_id'        => $data['actor_id'] ?? null,
                'approval_ref'    => null,
                'evidence'        => null,
                'created_at'      => $this->clock->nowUtcMicro(),
            ]);

            foreach (($data['consents'] ?? []) as $consent) {
                if (empty($consent['purpose']) || empty($consent['policy_version'])) {
                    continue;
                }
                $this->db->table('user_consents')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $organizationId,
                    'user_id'         => $id,
                    'purpose'         => $consent['purpose'],
                    'policy_version'  => $consent['policy_version'],
                    'granted'         => 1,
                    'granted_at'      => $now,
                    'created_at'      => $now,
                ]);
            }
        } catch (Throwable) {
            $this->db->transComplete();

            return Result::fail('REGISTER_FAILED', 'identity.register_failed', 409);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('REGISTER_FAILED', 'identity.register_failed', 500);
        }

        // FR-MEM-001: give the new member a sponsor (upline). Best-effort and
        // OUTSIDE the account transaction — a sponsor-linking hiccup must never
        // undo a valid registration.
        $sponsorId = $this->linkSponsor($organizationId, $id, $data);

        // M4: open the new member's membership journey (the "one open seam").
        // Rule-driven — emit a signal so a `membership`-facet ENTRY rule opens the
        // journey at the first ladder stage, instead of it only materialising on
        // the member's first tracked action (which under-reports first-stage
        // pipeline counts). Best-effort + fault-isolated, exactly like sponsor
        // linking, and OUTSIDE the account transaction.
        $this->openJourneySignal($organizationId, $id, $data);

        return Result::created([
            'user_id'    => $id,
            'email'      => $email,
            'phone'      => $phone,
            'status'     => $status,
            'is_minor'   => $isMinor,
            'sponsor_id' => $sponsorId,
        ]);
    }

    /**
     * Resolve and persist the new member's automatic sponsor. Returns the
     * sponsor id that was linked, or null when linking is unavailable/not
     * applicable. Never throws.
     *
     * @param array<string,mixed> $data
     */
    private function linkSponsor(string $organizationId, string $newUserId, array $data): ?string
    {
        if ($this->sponsorResolver === null || $this->sponsorships === null) {
            return null; // referrals not wired (e.g. isolated unit context)
        }

        try {
            $sponsorId = $this->sponsorResolver->resolve($organizationId, [
                'explicit_sponsor_id' => isset($data['sponsor_id']) ? (string) $data['sponsor_id'] : null,
                'group_id'            => isset($data['group_id']) ? (string) $data['group_id'] : null,
                'exclude_user_id'     => $newUserId,
            ]);
            if ($sponsorId === null || $sponsorId === $newUserId) {
                return null;
            }

            $res = $this->sponsorships->assign($organizationId, $newUserId, $sponsorId, 'registration');

            return $res->ok ? $sponsorId : null;
        } catch (Throwable) {
            return null; // sponsorships table absent / any transient issue
        }
    }

    /**
     * Emit the `journey.signal.member.registered` entry signal for a new account
     * (M4). Never throws — a journey hiccup must not undo a valid registration —
     * and is a no-op when the Journey seam is not wired (e.g. isolated unit
     * context). The signal opens the org-wide journey (group_id=null); a matching
     * entry rule chooses the stage.
     *
     * @param array<string,mixed> $data
     */
    private function openJourneySignal(string $organizationId, string $newUserId, array $data): void
    {
        if ($this->journeySignals === null) {
            return;
        }
        try {
            $this->journeySignals->ingest($organizationId, [
                'user_id'       => $newUserId,
                'action'        => 'journey.signal.member.registered',
                'group_id'      => null,
                'actor_id'      => isset($data['actor_id']) && $data['actor_id'] !== '' ? (string) $data['actor_id'] : null,
                'evidence_type' => 'registration',
                'evidence_ref'  => 'user:' . $newUserId,
                'attributes'    => [
                    'source'   => isset($data['group_id']) && $data['group_id'] !== '' ? 'group' : 'direct',
                    'group_id' => isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null,
                ],
            ]);
        } catch (Throwable) {
            // Journey automation is best-effort.
        }
    }

    /**
     * Mark an email verified (called after a verification-token flow). If the
     * account is still `pending_verification`, verifying a channel promotes it
     * to `active` (FR-ID-009) with append-only transition evidence.
     */
    public function markEmailVerified(string $userId): Result
    {
        $now  = $this->clock->nowUtcString();
        $user = $this->db->table('users')->where('id', $userId)->get()->getRowArray();

        $this->db->table('users')->where('id', $userId)->update([
            'email_verified' => 1,
            'updated_at'     => $now,
        ]);

        if ($user !== null && ($user['status'] ?? '') === 'pending_verification') {
            $this->db->table('users')->where('id', $userId)->update([
                'status'            => 'active',
                'status_reason'     => 'email_verified',
                'status_changed_at' => $now,
                'updated_at'        => $now,
            ]);
            $this->db->table('account_state_transitions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $user['organization_id'],
                'user_id'         => $userId,
                'from_status'     => 'pending_verification',
                'to_status'       => 'active',
                'reason'          => 'email_verified',
                'actor_id'        => null,
                'approval_ref'    => null,
                'evidence'        => null,
                'created_at'      => $this->clock->nowUtcMicro(),
            ]);
        }

        return Result::ok(['user_id' => $userId, 'email_verified' => true]);
    }

    /** Change a password after policy checks (used by reset/step-up flows). */
    public function setPassword(string $userId, string $newPassword): Result
    {
        $pwError = $this->passwordPolicy($newPassword);
        if ($pwError !== null) {
            return Result::fail('WEAK_PASSWORD', $pwError, 422);
        }
        $this->db->table('users')->where('id', $userId)->update([
            'password_hash' => $this->hasher->hash($newPassword),
            'updated_at'    => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['user_id' => $userId, 'password_set' => true]);
    }

    /** @param array<string,mixed> $data */
    public function updateProfile(string $userId, array $data): Result
    {
        $fields = array_filter([
            'display_name' => $data['display_name'] ?? null,
            'locale'       => $data['locale'] ?? null,
            'timezone'     => $data['timezone'] ?? null,
        ], static fn ($v) => $v !== null);
        if ($fields !== []) {
            $fields['updated_at'] = $this->clock->nowUtcString();
            $this->db->table('users')->where('id', $userId)->update($fields);
        }

        return Result::ok(['user_id' => $userId]);
    }

    /**
     * Set (or replace) a member's profile photo URL. The URL must be an http(s)
     * or a self-contained `data:` image URI — never a javascript:/other scheme.
     * When absent, callers fall back to the deterministic initials avatar
     * ({@see \WBS\Shared\Support\Avatar}), so a photo is always OPTIONAL.
     */
    public function setProfilePhoto(string $userId, string $url, string $source = 'url'): Result
    {
        $url = trim($url);
        if ($url === '') {
            return Result::fail('PHOTO_URL_REQUIRED', 'identity.photo_url_required', 422);
        }
        $isHttp = (bool) preg_match('#^https?://#i', $url);
        $isData = (bool) preg_match('#^data:image/#i', $url);
        if (! $isHttp && ! $isData) {
            return Result::fail('PHOTO_URL_INVALID', 'identity.photo_url_invalid', 422);
        }
        if (strlen($url) > 512) {
            return Result::fail('PHOTO_URL_TOO_LONG', 'identity.photo_url_too_long', 422);
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('users')->where('id', $userId)->update([
            'profile_photo_url'        => $url,
            'profile_photo_source'     => in_array($source, ['url', 'upload'], true) ? $source : 'url',
            'profile_photo_updated_at' => $now,
            'updated_at'               => $now,
        ]);

        return Result::ok(['user_id' => $userId, 'profile_photo_url' => $url]);
    }

    /** Remove a member's photo; subsequent renders fall back to the initials avatar. */
    public function removeProfilePhoto(string $userId): Result
    {
        $now = $this->clock->nowUtcString();
        $this->db->table('users')->where('id', $userId)->update([
            'profile_photo_url'        => null,
            'profile_photo_source'     => null,
            'profile_photo_updated_at' => $now,
            'updated_at'               => $now,
        ]);

        return Result::ok(['user_id' => $userId, 'profile_photo_url' => null]);
    }

    /** @return array<string,mixed>|null */
    public function findById(string $userId): ?array
    {
        return $this->db->table('users')->where('id', $userId)->get()->getRowArray() ?: null;
    }

    /**
     * Member roster for an organization (newest first), optionally filtered by
     * status. Read-only projection for the members admin page. Selects only the
     * columns the roster needs — never password_hash or other secrets — so the
     * page is safe to render for identity administrators.
     *
     * @return list<array<string,mixed>>
     */
    public function listMembers(string $organizationId, ?string $status = null, int $limit = 200): array
    {
        $q = $this->db->table('users')
            ->select('id, display_name, email, status, locale, profile_photo_url, created_at')
            ->where('organization_id', $organizationId);
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return $q->orderBy('created_at', 'DESC')
            ->get(max(1, min(500, $limit)))
            ->getResultArray();
    }

    /**
     * Parse an optional date_of_birth and apply the jurisdiction's minor policy.
     *
     * @param array<string,mixed> $policy
     *
     * @return array{0:?string,1:bool,2:?string} [dob(Y-m-d)|null, isMinor, errorKey|null]
     */
    private function evaluateAge(mixed $dobRaw, array $policy): array
    {
        if (empty($dobRaw)) {
            return [null, false, null];
        }
        $ts = strtotime((string) $dobRaw);
        if ($ts === false) {
            return [null, false, 'identity.invalid_dob'];
        }
        $dob = date('Y-m-d', $ts);
        $age = (int) $this->clock->now()->diff(new \DateTimeImmutable($dob))->y;

        $minAge  = (int) ($policy['min_age'] ?? 13);
        $isMinor = $age < $minAge;
        if ($isMinor && empty($policy['allow_minor'])) {
            return [$dob, true, 'identity.minor_not_allowed'];
        }

        return [$dob, $isMinor, null];
    }

    private function passwordPolicy(string $password): ?string
    {
        if (strlen($password) < 12) {
            return 'identity.password_too_short';
        }
        if (! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password) || ! preg_match('/\d/', $password)) {
            return 'identity.password_complexity';
        }
        // Breach-list check (FR-ID-003). Fail-open by design: a checker that
        // cannot reach its corpus returns false, so a transient outage never
        // blocks a legitimate password change. Absent an injected checker the
        // step is simply skipped (backward compatible).
        if ($this->breachChecker !== null && $this->breachChecker->isBreached($password)) {
            return 'identity.password_breached';
        }

        return null;
    }
}
