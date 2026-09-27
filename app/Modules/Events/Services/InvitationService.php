<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Event invitations + the eligibility gate that makes `registration_policy =
 * 'invite'` real (gap G5).
 *
 * An invite-only event is fail-closed: a person may register only when they are
 * ELIGIBLE, which is true when ANY of these holds —
 *
 *   1. STAFF/LEADER ASSISTED — a leader/organizer registers a person (self or on
 *      behalf, individually or in bulk, or via a follow-up). The caller is
 *      already authorized for the event (route `authorize:event.create`), so the
 *      assist itself is the invitation. Enforced by the caller passing an
 *      `assisted_by` actor into register().
 *   2. GROUP ELIGIBILITY — the event belongs to a hierarchical group and the
 *      registrant is a member of that group or any of its DESCENDANTS (the event
 *      was created for that part of the tree, so its members are invited by
 *      construction). One closure-backed membership check.
 *   3. DIRECT INVITE — an `event_invitations` row names the user, their email, or
 *      their E.164 phone (pending, unexpired). A direct email/phone invite is
 *      ALSO delivered over Notifications carrying the shareable link (below).
 *   4. SHAREABLE LINK — the ONE cloaked per-event URL (`event_invite_links`).
 *      Unlike a direct invite it is REUSABLE and broadcast (social media + direct
 *      email/SMS), bounded by a configurable mode: `expiry` (usable until a date
 *      or manual disable), `max_redemptions` (usable until a redemption cap), or
 *      `capacity` (usable while the event has seats — the registrar's own
 *      capacity gate is authoritative). The token is unguessable but, because it
 *      is deliberately published, NOT a secret; only its hash is the lookup key.
 *
 * Resource discipline: eligibility is a handful of bounded, indexed reads (never
 * a per-row scan), and the check short-circuits on the cheapest signal first
 * (assisted flag → direct user invite → group membership → email/phone → link).
 */
final class InvitationService
{
    /** Direct-invite channels (a named identity). The link is a separate concept. */
    public const CHANNELS = ['user', 'email', 'phone'];

    public const LINK_MODES = ['expiry', 'max_redemptions', 'capacity'];

    /** Default direct-invite lifetime (14 days), mirroring credential-setup TTLs. */
    private const INVITE_TTL_SECONDS = 1209600;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    // == Direct invitations (named identity) =================================

    /**
     * Issue a DIRECT invitation to a named identity for an invite-only event.
     * For the `email`/`phone` channels the caller (controller) additionally
     * delivers the shareable link over Notifications; this method only records
     * the allow-list entry.
     *
     * @param array<string,mixed> $data channel, invitee_user_id|invitee_email|
     *                                   invitee_phone, expires_at
     * @return Result data: {id, channel, invitee_email?, invitee_phone?}
     */
    public function issue(string $organizationId, string $eventId, array $data, ?string $invitedBy = null): Result
    {
        $channel = strtolower((string) ($data['channel'] ?? 'user'));
        if (! in_array($channel, self::CHANNELS, true)) {
            return Result::fail('BAD_CHANNEL', 'Events.invite.errBadChannel', 422, ['allowed' => self::CHANNELS]);
        }

        $userId = trim((string) ($data['invitee_user_id'] ?? ''));
        $email  = strtolower(trim((string) ($data['invitee_email'] ?? '')));
        $phone  = trim((string) ($data['invitee_phone'] ?? ''));

        if (($channel === 'user' && $userId === '')
            || ($channel === 'email' && $email === '')
            || ($channel === 'phone' && $phone === '')) {
            return Result::fail('INVITEE_REQUIRED', 'Events.invite.errInviteeRequired', 422);
        }

        $now     = $this->clock->nowUtcString();
        $id      = Uuid::v7();
        $expires = isset($data['expires_at']) && $data['expires_at'] !== ''
            ? (string) $data['expires_at']
            : $this->clock->now()->modify('+' . self::INVITE_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');

        try {
            $this->db->table('event_invitations')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'event_id'        => $eventId,
                'channel'         => $channel,
                'invitee_user_id' => $channel === 'user' ? $userId : null,
                'invitee_email'   => $channel === 'email' ? $email : null,
                'invitee_phone'   => $channel === 'phone' ? $phone : null,
                'status'          => 'pending',
                'invited_by'      => $invitedBy,
                'expires_at'      => $expires,
                'created_at'      => $now,
            ]);
        } catch (Throwable) {
            return Result::fail('INVITE_FAILED', 'Events.invite.errIssueFailed', 409);
        }

        return Result::created([
            'id'            => $id,
            'channel'       => $channel,
            'invitee_email' => $channel === 'email' ? $email : null,
            'invitee_phone' => $channel === 'phone' ? $phone : null,
            'expires_at'    => $expires,
        ]);
    }

    /**
     * Resolve a direct invitee's contact value to a known user id, if any, so a
     * delivered invite honours that user's notification preferences. Returns ''
     * when no account exists yet (the person can still register as a guest via
     * the shareable link).
     */
    public function resolveInviteeUserId(string $channel, string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! in_array($channel, ['email', 'phone'], true)) {
            return '';
        }
        $column = $channel === 'email' ? 'email' : 'phone';
        $match  = $channel === 'email' ? strtolower($value) : $value;
        $row    = $this->db->table('users')->select('id')->where($column, $match)->get()->getRowArray();

        return (string) ($row['id'] ?? '');
    }

    /** Mark a direct invitation as delivered (email/SMS staged). Best-effort. */
    public function markNotified(string $invitationId): void
    {
        $this->db->table('event_invitations')->where('id', $invitationId)->update([
            'notified_at' => $this->clock->nowUtcString(),
            'updated_at'  => $this->clock->nowUtcString(),
        ]);
    }

    // == Shareable per-event link ===========================================

    /**
     * Get the event's shareable invite link, if one exists.
     *
     * @return array<string,mixed>|null the link row (incl. plaintext `token`)
     */
    public function getLink(string $organizationId, string $eventId): ?array
    {
        return $this->db->table('event_invite_links')
            ->where('organization_id', $organizationId)
            ->where('event_id', $eventId)
            ->get()->getRowArray();
    }

    /**
     * Generate (or reconfigure) the ONE shareable, cloaked invite link for an
     * event. Idempotent per event: re-generating updates the mode/bounds and, if
     * `rotate` is set, mints a fresh token (invalidating links already shared).
     * The token is broadcast publicly, so it is unguessable but not secret.
     *
     * @param array<string,mixed> $opts mode (expiry|max_redemptions|capacity),
     *                                   expires_at, max_redemptions, rotate (bool)
     * @return Result data: {id, token, url_token, mode, expires_at, max_redemptions, active}
     */
    public function generateLink(string $organizationId, string $eventId, array $opts = [], ?string $createdBy = null): Result
    {
        $mode = strtolower((string) ($opts['mode'] ?? 'expiry'));
        if (! in_array($mode, self::LINK_MODES, true)) {
            return Result::fail('BAD_LINK_MODE', 'Events.invite.errBadMode', 422, ['allowed' => self::LINK_MODES]);
        }

        $maxRedemptions = null;
        if ($mode === 'max_redemptions') {
            $maxRedemptions = (int) ($opts['max_redemptions'] ?? 0);
            if ($maxRedemptions < 1) {
                return Result::fail('BAD_MAX_REDEMPTIONS', 'Events.invite.errBadMax', 422);
            }
        }

        $expires = null;
        if ($mode === 'expiry') {
            $expires = isset($opts['expires_at']) && $opts['expires_at'] !== ''
                ? (string) $opts['expires_at']
                : null; // NULL = usable until manually disabled
        }

        $now      = $this->clock->nowUtcString();
        $existing = $this->getLink($organizationId, $eventId);

        try {
            if ($existing === null) {
                $id    = Uuid::v7();
                $token = $this->mintToken();
                $this->db->table('event_invite_links')->insert([
                    'id'              => $id,
                    'organization_id' => $organizationId,
                    'event_id'        => $eventId,
                    'token'           => $token,
                    'token_hash'      => hash('sha256', $token),
                    'mode'            => $mode,
                    'max_redemptions' => $maxRedemptions,
                    'redeemed_count'  => 0,
                    'active'          => 1,
                    'expires_at'      => $expires,
                    'created_by'      => $createdBy,
                    'created_at'      => $now,
                ]);
            } else {
                $id     = (string) $existing['id'];
                $token  = (string) $existing['token'];
                $update = [
                    'mode'            => $mode,
                    'max_redemptions' => $maxRedemptions,
                    'expires_at'      => $expires,
                    'active'          => 1,
                    'updated_at'      => $now,
                ];
                if (! empty($opts['rotate'])) {
                    $token                = $this->mintToken();
                    $update['token']      = $token;
                    $update['token_hash'] = hash('sha256', $token);
                    // A rotated link starts a fresh redemption budget.
                    $update['redeemed_count'] = 0;
                }
                $this->db->table('event_invite_links')->where('id', $id)->update($update);
            }
        } catch (Throwable) {
            return Result::fail('LINK_FAILED', 'Events.invite.errLinkFailed', 409);
        }

        return Result::ok([
            'id'              => $id,
            'token'           => $token,
            'url_token'       => $token, // callers build the URL: /events/{id}?invite={url_token}
            'mode'            => $mode,
            'expires_at'      => $expires,
            'max_redemptions' => $maxRedemptions,
            'active'          => true,
        ]);
    }

    /** Enable/disable the shareable link without deleting it (manual toggle). */
    public function setLinkActive(string $organizationId, string $eventId, bool $active): Result
    {
        $link = $this->getLink($organizationId, $eventId);
        if ($link === null) {
            return Result::notFound('Events.invite.errLinkNotFound', 'LINK_NOT_FOUND');
        }
        $this->db->table('event_invite_links')->where('id', $link['id'])->update([
            'active'     => $active ? 1 : 0,
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['id' => $link['id'], 'active' => $active]);
    }

    // == Eligibility gate ====================================================

    /**
     * Is this user eligible to register for the event under its policy?
     *
     * For an `open` event this is always true; for `closed`, always false; for
     * `invite`, true when any eligibility signal holds. Returns a Result so the
     * reason is machine-readable by the caller.
     *
     * @param array<string,mixed> $event the event row (registration_policy, id, group_id, organization_id)
     * @param array<string,mixed> $opts  invite_token, assisted (bool)
     */
    public function eligibility(array $event, string $userId, array $opts = []): Result
    {
        $policy = (string) ($event['registration_policy'] ?? 'open');
        if ($policy === 'open') {
            return Result::ok(['eligible' => true, 'reason' => 'open']);
        }
        if ($policy === 'closed') {
            return Result::fail('REG_CLOSED', 'event.reg_closed', 409, ['eligible' => false]);
        }

        // ---- invite-only: fail-closed unless a signal proves eligibility ----
        $eventId = (string) ($event['id'] ?? '');
        $orgId   = (string) ($event['organization_id'] ?? '');

        // 1) Staff/leader (or follow-up) assisted: the authorized caller vouches.
        if (! empty($opts['assisted'])) {
            return Result::ok(['eligible' => true, 'reason' => 'assisted']);
        }

        // 2) Direct user invite (cheap indexed read).
        if ($userId !== '' && $this->hasUsableInvite($eventId, ['invitee_user_id' => $userId])) {
            return Result::ok(['eligible' => true, 'reason' => 'invited_user']);
        }

        // 3) Group eligibility: member of the event's group or a descendant.
        $groupId = (string) ($event['group_id'] ?? '');
        if ($groupId !== '' && $userId !== '' && $this->isGroupMemberInSubtree($orgId, $groupId, $userId)) {
            return Result::ok(['eligible' => true, 'reason' => 'group_member']);
        }

        // 4) Email / phone invite matched to the user's stored identity.
        if ($userId !== '') {
            $u     = $this->db->table('users')->select('email, phone')->where('id', $userId)->get()->getRowArray();
            $email = strtolower(trim((string) ($u['email'] ?? '')));
            $phone = trim((string) ($u['phone'] ?? ''));
            if ($email !== '' && $this->hasUsableInvite($eventId, ['invitee_email' => $email])) {
                return Result::ok(['eligible' => true, 'reason' => 'invited_email']);
            }
            if ($phone !== '' && $this->hasUsableInvite($eventId, ['invitee_phone' => $phone])) {
                return Result::ok(['eligible' => true, 'reason' => 'invited_phone']);
            }
        }

        // 5) Shareable link token (reusable, mode-bounded).
        $token = trim((string) ($opts['invite_token'] ?? ''));
        if ($token !== '' && $this->isLinkUsable($eventId, $token)) {
            return Result::ok(['eligible' => true, 'reason' => 'invite_link']);
        }

        return Result::fail('INVITE_REQUIRED', 'Events.invite.errInviteRequired', 403, ['eligible' => false]);
    }

    /**
     * Record the winning invitation's outcome AFTER a successful invite-only
     * registration. A direct invite is marked accepted (single acceptance); a
     * shareable link's redemption counter is incremented (reusable). Assisted /
     * group-member registrations have nothing to consume (no-op).
     *
     * @param array<string,mixed> $opts invite_token; and the resolved reason
     */
    public function consumeFor(string $eventId, string $userId, string $reason, array $opts = []): void
    {
        if ($reason === 'invite_link') {
            $token = trim((string) ($opts['invite_token'] ?? ''));
            if ($token !== '') {
                $link = $this->db->table('event_invite_links')
                    ->where('event_id', $eventId)
                    ->where('token_hash', hash('sha256', $token))
                    ->get()->getRowArray();
                if ($link !== null) {
                    $this->db->table('event_invite_links')->where('id', $link['id'])->update([
                        'redeemed_count' => (int) $link['redeemed_count'] + 1,
                        'updated_at'     => $this->clock->nowUtcString(),
                    ]);
                }
            }

            return;
        }

        $where = match ($reason) {
            'invited_user' => ['invitee_user_id' => $userId],
            'invited_email', 'invited_phone' => $this->identityWhere($userId, $reason),
            default => null, // assisted / group_member / open: nothing to consume
        };
        if ($where === null || $where === []) {
            return;
        }

        $row = $this->usableInviteQuery($eventId, $where)->get()->getRowArray();
        if ($row === null) {
            return;
        }
        $this->db->table('event_invitations')->where('id', $row['id'])->update([
            'status'      => 'accepted',
            'accepted_by' => $userId !== '' ? $userId : $row['accepted_by'],
            'accepted_at' => $this->clock->nowUtcString(),
            'updated_at'  => $this->clock->nowUtcString(),
        ]);
    }

    /** Revoke a direct invitation (organizer action). */
    public function revoke(string $organizationId, string $invitationId): Result
    {
        $row = $this->db->table('event_invitations')
            ->where('id', $invitationId)->where('organization_id', $organizationId)->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('Events.invite.errNotFound', 'INVITE_NOT_FOUND');
        }
        $this->db->table('event_invitations')->where('id', $invitationId)->update([
            'status'     => 'revoked',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['id' => $invitationId, 'status' => 'revoked']);
    }

    /**
     * Direct invitations for an event (organizer console). Bounded read.
     *
     * @return list<array<string,mixed>>
     */
    public function listForEvent(string $organizationId, string $eventId, int $limit = 200): array
    {
        return $this->db->table('event_invitations')
            ->select('id, channel, invitee_user_id, invitee_email, invitee_phone, status, notified_at, expires_at, created_at')
            ->where('organization_id', $organizationId)
            ->where('event_id', $eventId)
            ->orderBy('created_at', 'DESC')
            ->limit(max(1, min($limit, 500)))
            ->get()->getResultArray();
    }

    /**
     * Event-scoped invitation counts for the mobilization/expected-attendance
     * reports (gap G4). Replaces the old "count ALL org prospects" inflation with
     * figures tied to THIS event:
     *   - `direct`      : direct invitations issued (user/email/phone), excluding
     *                     revoked ones — people specifically invited;
     *   - `redemptions` : sign-ups that came through the shareable link
     *                     (its `redeemed_count`) — a broadcast link has no fixed
     *                     "invited" population, so its redemptions are what count;
     *   - `total`       : direct + redemptions, a defensible "reached by
     *                     invitation" figure for the funnel.
     * All bounded, indexed reads.
     *
     * @return array{direct:int, redemptions:int, total:int}
     */
    public function countInvitationsFor(string $eventId): array
    {
        $direct = (int) $this->db->table('event_invitations')
            ->where('event_id', $eventId)
            ->where('status !=', 'revoked')
            ->countAllResults();

        $link = $this->db->table('event_invite_links')
            ->select('redeemed_count')
            ->where('event_id', $eventId)
            ->get()->getRowArray();
        $redemptions = (int) ($link['redeemed_count'] ?? 0);

        return [
            'direct'      => $direct,
            'redemptions' => $redemptions,
            'total'       => $direct + $redemptions,
        ];
    }

    // -- internals ------------------------------------------------------------

    private function mintToken(): string
    {
        return 'wbsevt_' . bin2hex(random_bytes(24));
    }

    /**
     * Is the event's shareable link usable for this token right now? Enforces the
     * link's own mode bounds (active flag, expiry, redemption cap). The
     * `capacity` mode defers to the registrar's authoritative capacity gate, so
     * here it only requires the link to be active.
     */
    private function isLinkUsable(string $eventId, string $token): bool
    {
        $link = $this->db->table('event_invite_links')
            ->where('event_id', $eventId)
            ->where('token_hash', hash('sha256', $token))
            ->where('active', 1)
            ->get()->getRowArray();
        if ($link === null) {
            return false;
        }

        $mode = (string) ($link['mode'] ?? 'expiry');
        if ($mode === 'expiry') {
            $exp = $link['expires_at'] ?? null;

            return $exp === null || (string) $exp > $this->clock->nowUtcString();
        }
        if ($mode === 'max_redemptions') {
            return (int) $link['redeemed_count'] < (int) ($link['max_redemptions'] ?? 0);
        }

        // capacity: the registrar's atomic capacity gate is authoritative.
        return true;
    }

    /** @param array<string,mixed> $match one identifying column => value */
    private function hasUsableInvite(string $eventId, array $match): bool
    {
        return $this->usableInviteQuery($eventId, $match)->countAllResults() > 0;
    }

    /**
     * Base query for a still-usable direct invitation: pending and not expired.
     *
     * @param array<string,mixed> $match
     */
    private function usableInviteQuery(string $eventId, array $match)
    {
        $now = $this->clock->nowUtcString();
        $q   = $this->db->table('event_invitations')
            ->where('event_id', $eventId)
            ->where('status', 'pending')
            ->groupStart()
                ->where('expires_at IS NULL', null, false)
                ->orWhere('expires_at >', $now)
            ->groupEnd();
        foreach ($match as $col => $val) {
            $q->where($col, $val);
        }

        return $q;
    }

    /**
     * The identity match-clause for an email/phone reason, resolved from the
     * user's stored contact fields.
     *
     * @return array<string,mixed>
     */
    private function identityWhere(string $userId, string $reason): array
    {
        $u = $this->db->table('users')->select('email, phone')->where('id', $userId)->get()->getRowArray();
        if ($reason === 'invited_email') {
            $email = strtolower(trim((string) ($u['email'] ?? '')));

            return $email !== '' ? ['invitee_email' => $email] : [];
        }
        $phone = trim((string) ($u['phone'] ?? ''));

        return $phone !== '' ? ['invitee_phone' => $phone] : [];
    }

    /**
     * Is the user an active member of the group or any of its descendants? ONE
     * closure-backed read: the descendant set is the group plus everything under
     * it, and we look for any active membership in that set.
     */
    private function isGroupMemberInSubtree(string $organizationId, string $groupId, string $userId): bool
    {
        $subtree = $this->db->table('group_closure')
            ->select('descendant_id')
            ->where('ancestor_id', $groupId)
            ->get()->getResultArray();
        $ids = array_map(static fn (array $r): string => (string) $r['descendant_id'], $subtree);
        if ($ids === []) {
            $ids = [$groupId]; // no closure rows projected yet → just the group itself
        }

        return $this->db->table('group_members')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereIn('group_id', $ids)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }
}
