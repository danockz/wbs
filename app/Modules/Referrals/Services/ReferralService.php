<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Cloaked referral links, click capture, and conversion attribution
 * (SRS FR-MEM-004/005, referral link threat model).
 *
 *  - Link codes are opaque and reveal nothing about the referrer.
 *  - Click logging never stores a raw IP — only a salted hash — and records
 *    consent state.
 *  - Attribution is granted ONLY after a configured conversion event and is
 *    idempotent per (converted_user, conversion_type, source_ref), so a
 *    replayed conversion can't double-credit.
 */
final class ReferralService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly string $ipSalt = 'wbs-ip-salt',
        private readonly ?FraudService $fraud = null,
        private readonly ?JourneySignalPort $journeySignals = null,
        // Field-sync (onboarding audit): a landing capture writes the SAME
        // ownership/placement columns as every other capture path — the
        // prospect belongs to the LINK'S REFERRER and lands in that mentor's
        // group. Null (older positional test callers) skips placement only.
        private readonly ?ProspectGroupResolver $groups = null,
        // Optional integration-decision input on the PUBLIC landing (onboarding
        // decision): filled only when the referrer's placement group offers the
        // type in `capture_inputs`; recorded as a SELF declaration (source=
        // landing, born PENDING). Gate failure skips silently — public path.
        private readonly ?IntegrationService $integration = null,
    ) {
    }

    /** Create a cloaked link for a referrer. */
    public function createLink(string $organizationId, string $referrerId, array $opts = []): Result
    {
        $code = $this->uniqueCode();
        $id   = Uuid::v7();
        $this->db->table('referral_links')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'referrer_id'     => $referrerId,
            'code'            => $code,
            'campaign'        => $opts['campaign'] ?? null,
            'landing'         => $opts['landing'] ?? null,
            'status'          => 'active',
            'created_at'      => $this->clock->nowUtcString(),
        ]);

        return Result::created(['id' => $id, 'code' => $code]);
    }

    /** Resolve an opaque code to its active link, or null. */
    public function resolve(string $code): ?array
    {
        $row = $this->db->table('referral_links')
            ->where('code', $code)->where('status', 'active')
            ->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Record a click. IP/UA are hashed; raw values are never persisted.
     *
     * When a FraudService is wired in, the click is assessed BEFORE insertion
     * and the verdict is stored as is_suspicious + a PII-free reason. A
     * device_hash (sha256 of a normalized client-signal bundle) is stored when
     * device signals are supplied — the raw components are never persisted.
     *
     * @param array<string,mixed> $ctx ip, user_agent, consent, device (array of
     *                                  normalized client signals)
     */
    public function recordClick(string $code, array $ctx = []): Result
    {
        $link = $this->resolve($code);
        if ($link === null) {
            return Result::notFound('referral.link_not_found', 'LINK_NOT_FOUND');
        }

        $ipHash     = isset($ctx['ip']) ? $this->hashIp((string) $ctx['ip']) : null;
        $deviceHash = $this->deviceHash($ctx['device'] ?? null);

        $verdict = $this->fraud?->assess([
            'link_id'    => (string) $link['id'],
            'ip_hash'    => $ipHash,
            'user_agent' => isset($ctx['user_agent']) ? (string) $ctx['user_agent'] : null,
            'consent'    => ! empty($ctx['consent']),
        ]);

        $this->db->table('referral_clicks')->insert([
            'id'                => Uuid::v7(),
            'link_id'           => $link['id'],
            'ip_hash'           => $ipHash,
            'ua_hash'           => isset($ctx['user_agent']) ? hash('sha256', (string) $ctx['user_agent']) : null,
            'device_hash'       => $deviceHash,
            'consent'           => ! empty($ctx['consent']) ? 1 : 0,
            'is_suspicious'     => $verdict !== null && $verdict->suspicious ? 1 : 0,
            'suspicious_reason' => $verdict !== null && $verdict->suspicious ? ($verdict->reason() ?: null) : null,
            'created_at'        => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok([
            'link_id'    => $link['id'],
            'landing'    => $link['landing'],
            'referrer'   => $link['referrer_id'],
            // The campaign label is safe to surface on a branded landing page
            // (it names a campaign, not the sponsor). Reused from the already
            // resolved row — no extra query. The sponsor id is deliberately NOT
            // rendered by the browser funnel (see ReferralController::land).
            'campaign'   => $link['campaign'] ?? null,
            'link_type'  => (string) ($link['link_type'] ?? 'member'),
            'redirect'   => $this->resolveRedirectUrl($link, $ctx['utm'] ?? []),
            'suspicious' => $verdict !== null && $verdict->suspicious,
        ]);
    }

    /**
     * Capture a consent-gated prospect from a link (the CLOAKED-LANDING path).
     *
     * Writes the SAME canonical prospect field set as createContact (the
     * onboarding field-sync decision) with a DOCUMENTED graded subset:
     *   - email is stored HASH-ONLY (privacy on a public page — never a
     *     plaintext column write here; test-enforced),
     *   - phone is OPTIONAL but carried through in clear like every path,
     *   - source='link', owner/created_by = the link's referrer (mentor),
     *   - assigned_group = the referrer's own group (placement — no choice),
     *   - full_name, journey_stage, temperature, consent, GPS flags, notes,
     *     invite context, state, timestamps — all written explicitly.
     *
     * @param array<string,mixed> $data email, display_name, phone, consent
     */
    /**
     * Capture-form decision inputs for an invite landing page: the same
     * placement resolution captureProspect uses (referral link row in,
     * allowed type list out) — owner identity stays inside the service and
     * never reaches the view layer.
     *
     * @param array<string,mixed> $link referral_links row
     * @return list<string>
     */
    public function captureInputsForLink(array $link): array
    {
        if ($this->integration === null) {
            return [];
        }
        $placement = $this->groups?->placementFor(
            (string) ($link['organization_id'] ?? ''),
            (string) ($link['referrer_id'] ?? ''),
            null,
        );

        return $this->integration->captureInputsFor($placement ?? '');
    }

    public function captureProspect(string $code, array $data): Result
    {
        $link = $this->resolve($code);
        if ($link === null) {
            return Result::notFound('referral.link_not_found', 'LINK_NOT_FOUND');
        }
        if (empty($data['consent'])) {
            return Result::fail('CONSENT_REQUIRED', 'referral.consent_required', 422);
        }
        $name = trim((string) ($data['display_name'] ?? ''));
        if ($name === '') {
            // Same name gate as every other capture path (createContact,
            // captureGuestFromInvite) — the record set stays in sync.
            return Result::fail('NAME_REQUIRED', 'contact.name_required', 422);
        }

        $ownerId  = (string) $link['referrer_id'];
        $groupId  = $this->groups?->placementFor(
            (string) $link['organization_id'],
            $ownerId,
            null,
        );
        $phone    = trim((string) ($data['phone'] ?? '')) ?: null;
        $email    = isset($data['email']) ? strtolower(trim((string) $data['email'])) : '';
        $now      = $this->clock->nowUtcString();
        $id       = Uuid::v7();
        $this->db->table('prospects')->insert([
            'id'                      => $id,
            'organization_id'         => $link['organization_id'],
            'referrer_id'             => $link['referrer_id'],
            'owner_user_id'           => $ownerId,
            'assigned_group_id'       => $groupId,
            'created_by'              => $ownerId,
            'source'                  => 'link',
            'link_id'                 => $link['id'],
            'email_hash'              => $email !== '' ? hash('sha256', $email) : null,
            // NOTE: no 'email' key — plaintext is NEVER written on this path
            // (privacy decision + referral_invite_funnel contract).
            'display_name'            => $name,
            'full_name'               => $name,
            'phone'                   => $phone,
            'address_id'              => null,
            'notes'                   => null,
            'journey_stage'           => 'prospect',
            'temperature'             => 'warm', // a self-selecting sign-up is warm (parity with guest capture)
            'next_follow_up_at'       => null,
            'invite_context_type'     => null,
            'invite_context_id'       => null,
            'coords_consent_verbal'   => 0,
            'coords_consent_confirmed' => 0,
            'coords_consent_at'       => null,
            'consent'                 => 1,
            'state'                   => 'captured',
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        // Optional integration-decision input on the public landing (onboarding
        // decision): SELF flavor — source=landing, born PENDING until the
        // owner/sponsor confirms. Only offered types are recorded; any gate or
        // service error skips silently (the capture itself already succeeded
        // and a visitor must never be punished for an optional enrichment).
        $decisionType = trim((string) ($data['decision_type'] ?? ''));
        if ($decisionType !== '' && $this->integration !== null
            && $this->integration->captureInputAllowed($groupId, $decisionType)) {
            try {
                $this->integration->declareForContact((string) $link['organization_id'], $id, [
                    'decision_type' => $decisionType,
                    'decision_date' => trim((string) ($data['decision_date'] ?? '')),
                    'note'          => $data['decision_note'] ?? null,
                    'source'        => 'landing',
                ]);
            } catch (Throwable) {
                // optional enrichment — never block the capture
            }
        }

        // The redirect target lets the browser funnel send a just-captured
        // prospect onward to the branded destination in one PRG hop (no extra
        // query — the link is already resolved). API clients ignore it.
        return Result::created([
            'id'       => $id,
            'state'    => 'captured',
            'redirect' => $this->resolveRedirectUrl($link, $data['utm'] ?? []),
        ]);
    }

    /**
     * Attribute a conversion to a referrer. Idempotent per (user, type, ref);
     * a replayed conversion returns the existing attribution as a no-op.
     */
    public function attributeConversion(
        string $organizationId,
        string $referrerId,
        string $convertedUserId,
        string $conversionType,
        string $sourceRef,
        array $opts = [],
    ): Result {
        if ($referrerId === $convertedUserId) {
            return Result::fail('SELF_REFERRAL', 'referral.self', 422);
        }

        try {
            $id = Uuid::v7();
            $this->db->table('referral_attributions')->insert([
                'id'                => $id,
                'organization_id'   => $organizationId,
                'referrer_id'       => $referrerId,
                'converted_user_id' => $convertedUserId,
                'link_id'           => $opts['link_id'] ?? null,
                'campaign'          => $opts['campaign'] ?? null,
                'conversion_type'   => $conversionType,
                'source_ref'        => $sourceRef,
                'created_at'        => $this->clock->nowUtcString(),
            ]);
        } catch (Throwable) {
            // UNIQUE(converted_user_id, conversion_type, source_ref) -> replay.
            return Result::ok(['status' => 'duplicate'], 200, ['deduplicated' => true]);
        }

        // Mark the ONE matching prospect converted (gap R2). The previous code
        // flipped EVERY still-captured prospect of this referrer in a single
        // unscoped UPDATE, corrupting the funnel and dropping real prospects out
        // of follow-up. Scope to the prospect that actually converted, identified
        // (best-first) by:
        //   1. linked_user_id == convertedUserId (the capture was linked to them),
        //   2. an explicit prospect_id opt,
        //   3. link_id + email_hash opts tying this capture to this conversion.
        // If none identifies a row, convert NOTHING rather than everything.
        $q = $this->db->table('prospects')
            ->where('referrer_id', $referrerId)
            ->where('state', 'captured');

        if (! empty($opts['prospect_id'])) {
            $q->where('id', (string) $opts['prospect_id']);
        } elseif (! empty($opts['email_hash'])) {
            $q->where('email_hash', (string) $opts['email_hash']);
            if (! empty($opts['link_id'])) {
                $q->where('link_id', (string) $opts['link_id']);
            }
        } else {
            // Default identifier: the converting user's linked prospect row.
            $q->where('linked_user_id', $convertedUserId);
        }

        $q->update(['state' => 'converted']);

        // M4: a conversion is the moment to open the converted person's membership
        // journey (the "one open seam"). Rule-driven — emit a signal so a
        // `membership`-facet entry rule opens the journey at the right stage —
        // rather than a forked openJourney() call. Best-effort + fault-isolated:
        // a journey hiccup must never undo a recorded, deduped attribution.
        if ($this->journeySignals !== null) {
            try {
                $this->journeySignals->ingest($organizationId, [
                    'user_id'       => $convertedUserId,
                    'action'        => 'journey.signal.member.converted',
                    'group_id'      => null, // advance the org-wide journey
                    'actor_id'      => isset($opts['actor_id']) && $opts['actor_id'] !== '' ? (string) $opts['actor_id'] : null,
                    'evidence_type' => 'referral',
                    'evidence_ref'  => 'attribution:' . $id,
                    'attributes'    => ['referrer_id' => $referrerId, 'conversion_type' => $conversionType],
                ]);
            } catch (Throwable) {
                // Journey automation is best-effort.
            }
        }

        return Result::created(['id' => $id, 'conversion_type' => $conversionType]);
    }

    public function conversionCount(string $referrerId): int
    {
        return $this->db->table('referral_attributions')
            ->where('referrer_id', $referrerId)
            ->countAllResults();
    }

    // -------------------------------------------------------------------------
    // Analytics aggregates (G1) — counts and buckets only, never raw PII.
    // -------------------------------------------------------------------------

    /**
     * Aggregate click analytics for a single link, by opaque code.
     *
     * Output is PII-free: uniqueness is measured over hashes (ip_hash /
     * device_hash), and no raw referrer/IP/geo is ever emitted.
     */
    public function getLinkAnalytics(string $code, string $period = '30 days'): Result
    {
        $link = $this->resolve($code);
        if ($link === null) {
            return Result::notFound('referral.link_not_found', 'LINK_NOT_FOUND');
        }

        $since  = $this->periodStart($period);
        $clicks = $this->db->table('referral_clicks')
            ->where('link_id', $link['id'])
            ->where('created_at >=', $since)
            ->get()->getResultArray();

        return Result::ok($this->aggregateClicks($clicks) + [
            'link_id'  => $link['id'],
            'campaign' => $link['campaign'] ?? null,
            'period'   => $period,
        ]);
    }

    /**
     * Aggregate click analytics across every link owned by a referrer.
     *
     * Avoids N+1 by fetching the referrer's link ids once, then bulk-loading
     * their clicks with a single WHERE IN.
     */
    public function getReferrerAnalytics(string $referrerId, string $period = '30 days'): Result
    {
        $links = $this->db->table('referral_links')
            ->select('id, campaign')
            ->where('referrer_id', $referrerId)
            ->get()->getResultArray();

        if ($links === []) {
            return Result::ok([
                'referrer_id' => $referrerId,
                'period'      => $period,
                'links'       => 0,
                'total_clicks'    => 0,
                'unique_visitors' => 0,
                'unique_devices'  => 0,
                'suspicious_clicks' => 0,
                'clicks_by_hour'  => [],
                'top_campaigns'   => [],
            ]);
        }

        $ids    = array_column($links, 'id');
        $since  = $this->periodStart($period);
        $clicks = $this->db->table('referral_clicks')
            ->whereIn('link_id', $ids)
            ->where('created_at >=', $since)
            ->get()->getResultArray();

        // Top campaigns by click count (join clicks -> link -> campaign).
        $campaignByLink = [];
        foreach ($links as $l) {
            $campaignByLink[(string) $l['id']] = $l['campaign'] ?? null;
        }
        $campaignCounts = [];
        foreach ($clicks as $c) {
            $camp = $campaignByLink[(string) $c['link_id']] ?? null;
            if ($camp === null || $camp === '') {
                continue;
            }
            $campaignCounts[$camp] = ($campaignCounts[$camp] ?? 0) + 1;
        }
        arsort($campaignCounts);

        return Result::ok($this->aggregateClicks($clicks) + [
            'referrer_id'   => $referrerId,
            'period'        => $period,
            'links'         => count($links),
            'top_campaigns' => array_slice($campaignCounts, 0, 10, true),
        ]);
    }

    /**
     * Fold a set of raw click rows into the standard PII-free analytics shape.
     *
     * @param list<array<string,mixed>> $clicks
     * @return array<string,mixed>
     */
    private function aggregateClicks(array $clicks): array
    {
        $suspicious = array_filter($clicks, static fn ($c) => (int) ($c['is_suspicious'] ?? 0) === 1);

        $byHour = [];
        foreach ($clicks as $c) {
            $h          = (int) date('G', strtotime((string) $c['created_at']));
            $byHour[$h] = ($byHour[$h] ?? 0) + 1;
        }
        ksort($byHour);

        return [
            'total_clicks'      => count($clicks),
            'unique_visitors'   => count(array_unique(array_filter(array_column($clicks, 'ip_hash')))),
            'unique_devices'    => count(array_unique(array_filter(array_column($clicks, 'device_hash')))),
            'suspicious_clicks' => count($suspicious),
            'clicks_by_hour'    => $byHour,
        ];
    }

    // -------------------------------------------------------------------------
    // Manual review helpers (G2).
    // -------------------------------------------------------------------------

    /** Mark a recorded click as suspicious (manual review outcome). */
    public function markSuspicious(string $clickId, string $reason): Result
    {
        $updated = $this->db->table('referral_clicks')
            ->where('id', $clickId)
            ->update([
                'is_suspicious'     => 1,
                'suspicious_reason' => mb_substr($reason, 0, 255),
            ]);

        if (! $updated || $this->db->affectedRows() === 0) {
            return Result::notFound('referral.click_not_found', 'CLICK_NOT_FOUND');
        }

        return Result::ok(['click_id' => $clickId, 'is_suspicious' => true]);
    }

    /** Clear the suspicious flag after review confirms a click is legitimate. */
    public function clearSuspicious(string $clickId): Result
    {
        $updated = $this->db->table('referral_clicks')
            ->where('id', $clickId)
            ->update([
                'is_suspicious'     => 0,
                'suspicious_reason' => null,
            ]);

        if (! $updated || $this->db->affectedRows() === 0) {
            return Result::notFound('referral.click_not_found', 'CLICK_NOT_FOUND');
        }

        return Result::ok(['click_id' => $clickId, 'is_suspicious' => false]);
    }

    // -------------------------------------------------------------------------
    // Typed redirect resolution (G4).
    // -------------------------------------------------------------------------

    /**
     * Resolve a link's cloaked code to the correct platform destination based on
     * its type. No reversible payload lives in the URL — the type + resource_id
     * come from the DB row. UTM params, when supplied, are passed through.
     *
     * @param array<string,mixed> $link A resolved referral_links row.
     * @param array<string,string> $utm Optional UTM passthrough params.
     */
    public function resolveRedirectUrl(array $link, array $utm = []): string
    {
        $type       = (string) ($link['link_type'] ?? 'member');
        $resourceId = (string) ($link['resource_id'] ?? '');
        $referrer   = (string) ($link['referrer_id'] ?? '');

        $base = match ($type) {
            'event'     => base_url('events/register/' . $resourceId),
            'giving'    => base_url('givings/donate/' . $resourceId),
            'streaming' => base_url('streaming/' . $resourceId),
            'course'    => base_url('courses/' . $resourceId),
            'member'    => base_url('register?sponsor=' . rawurlencode($referrer)
                            . ($resourceId !== '' ? '&event=' . rawurlencode($resourceId) : '')),
            default     => (string) ($link['landing'] ?? base_url()),
        };

        $utm = array_filter(
            array_intersect_key($utm, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])),
            static fn ($v) => is_string($v) && $v !== '',
        );
        if ($utm === []) {
            return $base;
        }

        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($utm);
    }

    private function uniqueCode(): string
    {
        do {
            $code = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'AB'), '=');
            $code = substr($code, 0, 12);
            $exists = $this->db->table('referral_links')->where('code', $code)->countAllResults() > 0;
        } while ($exists);

        return $code;
    }

    private function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->ipSalt);
    }

    /**
     * Derive a stable, non-reversible device hash from a bundle of normalized
     * client signals (e.g. screen resolution, timezone, UA class). The raw
     * components are NEVER persisted — only this sha256 digest is. Returns null
     * when no usable signals are supplied.
     *
     * @param mixed $device Array of client signals, or null.
     */
    private function deviceHash(mixed $device): ?string
    {
        if (! is_array($device) || $device === []) {
            return null;
        }

        // Normalize: keep scalar signals, sort by key for order-independence.
        $signals = [];
        foreach ($device as $k => $v) {
            if (is_scalar($v)) {
                $signals[(string) $k] = (string) $v;
            }
        }
        if ($signals === []) {
            return null;
        }
        ksort($signals);

        return hash_hmac('sha256', json_encode($signals), $this->ipSalt);
    }

    /** Convert a human period ('30 days', '24 hours') to a UTC lower bound. */
    private function periodStart(string $period): string
    {
        $ts = strtotime('-' . ltrim($period, '-'), strtotime($this->clock->nowUtcString()));
        if ($ts === false) {
            // Fall back to 30 days on an unparseable period.
            $ts = strtotime('-30 days', strtotime($this->clock->nowUtcString()));
        }

        return date('Y-m-d H:i:s', (int) $ts);
    }
}
