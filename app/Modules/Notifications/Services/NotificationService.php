<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Integrations\Services\ProviderReliabilityService;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Notification dispatch (SRS FR-NOT-003/006/007).
 *
 *  - Every send runs through the RetentionPolicyGate first; suppressed/deferred
 *    messages are recorded, not silently dropped.
 *  - A delivery snapshot (recipient + template version + content fingerprint) is
 *    persisted, and the actual provider dispatch is handed to the transactional
 *    OUTBOX so the delivery row and the queued work commit atomically.
 *  - Sends are idempotent on dedupe_key.
 */
final class NotificationService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly RetentionPolicyGate $gate,
        private readonly TemplateRenderer $renderer,
        private readonly OutboxService $outbox,
        private readonly ?ProviderReliabilityService $reliability = null,
        /** Resolves a recipient's own group when the caller does not name one. */
        private readonly ?GroupScopeResolver $groupScope = null,
        /** Optional: resolve a stored template when the caller did not pass a body. */
        private readonly ?NotificationTemplateService $templates = null,
    ) {
    }

    /** Circuit-breaker scope for a notification channel (per org). */
    private function channelScope(string $channel): string
    {
        return 'notification:' . strtolower($channel);
    }

    /**
     * Which body this message belongs to — and therefore whose provider
     * credentials and sender identity it uses (FR-INT-007).
     *
     *  1. an explicit `group_id` (a cell texting its own members, a leader
     *     acting for one body);
     *  2. the campaign's group, for campaign sends;
     *  3. the recipient's own primary group — the same membership fallback
     *     contribution attribution uses, so a person's messages belong to the
     *     body they actually sit in;
     *  4. null: no body stands behind this message. Not an error here — the
     *     transport refuses to send it on credentials nobody provided, which is
     *     the fail-closed rule, and the refusal is recorded on the delivery.
     *
     * @param array<string,mixed> $opts
     */
    private function resolveGroup(string $organizationId, string $userId, array $opts): ?string
    {
        $explicit = trim((string) ($opts['group_id'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $campaignId = isset($opts['campaign_id']) ? trim((string) $opts['campaign_id']) : '';
        if ($campaignId !== '') {
            $row = $this->db->table('notification_campaigns')
                ->where('id', $campaignId)
                ->get()->getRowArray();
            if ($row !== null && ! empty($row['group_id'])) {
                return (string) $row['group_id'];
            }
        }

        if ($this->groupScope !== null && $userId !== '') {
            return $this->groupScope->primaryMembershipGroup($organizationId, $userId);
        }

        return null;
    }

    /**
     * Send a single notification (transactional or campaign member).
     *
     * @param array<string,mixed> $opts campaign_id, template_version,
     *                                   recipient_snapshot, context, frequency_cap,
     *                                   dedupe_key, body, escape, group_id
     */
    public function send(string $organizationId, string $userId, string $channel, string $category, array $opts = []): Result
    {
        $dedupe = $opts['dedupe_key'] ?? ($organizationId . ':' . $userId . ':' . ($opts['campaign_id'] ?? 'tx') . ':' . $category);

        // Idempotency: identical send already recorded?
        $existing = $this->db->table('notification_deliveries')->where('dedupe_key', $dedupe)->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['delivery_id' => $existing['id'], 'status' => $existing['status']], 200, ['deduplicated' => true]);
        }

        $decision = $this->gate->evaluate($organizationId, $userId, $channel, $category, [
            'frequency_cap' => $opts['frequency_cap'] ?? null,
            'priority'      => $opts['priority'] ?? 'normal',
        ]);

        $groupId = $this->resolveGroup($organizationId, $userId, $opts);

        if (! isset($opts['body']) && $this->templates !== null) {
            $profile = $this->templates->recipientProfile($organizationId, $userId, $groupId);
            $key     = trim((string) ($opts['template_key'] ?? $category));
            $hit     = $this->templates->resolveForSend($organizationId, $key, $channel, [
                'group_id'        => $groupId,
                'locale'          => (string) ($opts['locale'] ?? $profile['locale']),
                'membership_role' => $profile['membership_role'],
                'platform_roles'  => $profile['platform_roles'],
            ]);
            if ($hit !== null) {
                $opts['body']             = $hit['body'];
                $opts['template_version'] = $opts['template_version'] ?? $hit['version'];
                $ctx                      = is_array($opts['context'] ?? null) ? $opts['context'] : [];
                if (! isset($ctx['name']) && $userId !== '') {
                    $u = $this->db->table('users')->select('display_name')->where('id', $userId)->get()->getRowArray();
                    if ($u !== null) {
                        $ctx['name'] = (string) ($u['display_name'] ?? '');
                    }
                }
                $opts['context'] = $ctx;
            }
        }

        $escape      = (bool) ($opts['escape'] ?? ($channel !== 'sms'));
        $rendered    = isset($opts['body'])
            ? ($this->templates !== null
                ? $this->templates->renderBody((string) $opts['body'], $opts['context'] ?? [], $escape)
                : $this->renderer->render((string) $opts['body'], $opts['context'] ?? [], $escape))
            : null;
        $fingerprint = $rendered !== null ? $this->renderer->fingerprint($rendered) : null;

        $status = match ($decision['action']) {
            'suppress' => 'suppressed',
            'defer'    => 'deferred',
            // N4 — held for the next daily/weekly digest window. Kept distinct
            // from `deferred` so the N1 release sweep never re-queues it as an
            // instant send; the digest builder bundles these instead.
            'digest'   => 'digest_pending',
            default    => 'queued',
        };

        $now        = $this->clock->nowUtcMicro();
        $deliveryId = Uuid::v7();

        // Whose credentials and sender identity this message uses, decided NOW
        // and never rewritten later (like recipient_snapshot): explicit group →
        // the campaign's group → the recipient's own group → null. A null group
        // is not an error here; the transport refuses to send on credentials no
        // body provided, which is the fail-closed rule (FR-INT-007).
        // `$groupId` was already resolved above so the template lookup and the
        // delivery stamp agree.

        $this->db->transStart();

        $this->db->table('notification_deliveries')->insert([
            'id'                  => $deliveryId,
            'organization_id'     => $organizationId,
            'group_id'            => $groupId,
            'campaign_id'         => $opts['campaign_id'] ?? null,
            'user_id'             => $userId,
            'channel'             => $channel,
            'category'            => $category,
            'template_version'    => $opts['template_version'] ?? null,
            'recipient_snapshot'  => isset($opts['recipient_snapshot']) ? json_encode($opts['recipient_snapshot'], JSON_UNESCAPED_UNICODE) : null,
            'content_fingerprint' => $fingerprint,
            'dedupe_key'          => $dedupe,
            'status'              => $status,
            // Both `deferred` (N1 quiet-hours/frequency-cap) and `digest_pending`
            // (N4 daily/weekly) carry a "not before" watermark in defer_until.
            'defer_until'         => in_array($status, ['deferred', 'digest_pending'], true) ? $decision['defer_until'] : null,
            'suppression_reason'  => $decision['reason'],
            'created_at'          => $now,
        ]);

        // Only queue actual provider work when we intend to send now.
        if ($status === 'queued') {
            $this->outbox->stage(
                'notification',
                $deliveryId,
                'notification.dispatch',
                [
                    'delivery_id' => $deliveryId,
                    'channel'     => $channel,
                    'category'    => $category,
                    'body'        => $rendered,
                    'group_id'    => $groupId,
                ],
                $organizationId,
            );
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('SEND_FAILED', 'notification.send_failed', 500);
        }

        return Result::created([
            'delivery_id' => $deliveryId,
            'status'      => $status,
            'reason'      => $decision['reason'],
            'defer_until' => $decision['defer_until'],
        ]);
    }

    /** Record a delivery state transition from a provider callback (FR-NOT-007). */
    public function updateDeliveryStatus(string $deliveryId, string $status, ?string $providerRequestId = null): Result
    {
        $allowed = ['sent', 'delivered', 'bounced', 'failed', 'complained'];
        if (! in_array($status, $allowed, true)) {
            return Result::fail('BAD_STATUS', 'notification.bad_status', 422);
        }
        $data = ['status' => $status, 'updated_at' => $this->clock->nowUtcMicro()];
        if ($providerRequestId !== null) {
            $data['provider_request_id'] = $providerRequestId;
        }
        $this->db->table('notification_deliveries')->where('id', $deliveryId)->update($data);

        // FR-INT-012: feed the per-(org,channel) circuit breaker. Provider
        // acceptance (sent/delivered) is a healthy signal; a transport 'failed'
        // is a provider fault. Recipient-side outcomes (bounced/complained) are
        // NOT the provider's fault and never affect the breaker.
        if ($this->reliability !== null && in_array($status, ['sent', 'delivered', 'failed'], true)) {
            $row = $this->db->table('notification_deliveries')
                ->select('organization_id, channel')->where('id', $deliveryId)->get()->getRowArray();
            $org = (string) ($row['organization_id'] ?? '');
            $ch  = (string) ($row['channel'] ?? '');
            if ($org !== '' && $ch !== '') {
                $scope = $this->channelScope($ch);
                if ($status === 'failed') {
                    $this->reliability->recordFailure($org, $scope, 'notification transport failed');
                } else {
                    $this->reliability->recordSuccess($org, $scope);
                }
            }
        }

        return Result::ok(['delivery_id' => $deliveryId, 'status' => $status]);
    }

    /**
     * Record a PERMANENT, per-message delivery rejection (invalid recipient,
     * hard bounce at submit, permanent provider 4xx) without re-queueing and
     * WITHOUT feeding the circuit breaker.
     *
     * This is distinct from {@see updateDeliveryStatus()} with 'failed', which
     * treats the outcome as a provider fault and trips the breaker. A bad
     * recipient is the *caller's* fault, not the provider's, so it must not open
     * the channel's circuit or loop the queue — the delivery just terminates as
     * "failed" with a reason.
     */
    public function recordTerminalRejection(string $deliveryId, string $reason): Result
    {
        $this->db->table('notification_deliveries')->where('id', $deliveryId)->update([
            'status'             => 'failed',
            'suppression_reason' => substr($reason, 0, 60),
            'updated_at'         => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['delivery_id' => $deliveryId, 'status' => 'failed', 'reason' => $reason]);
    }

    /**
     * React to an account teardown (Theme B consumer — N7).
     *
     * A deactivated / suspended / anonymized / merged-away person must stop being
     * a notification target:
     *   - cancel their still-inflight deliveries (`queued` / `deferred`) so a
     *     later relay/campaign fan-out can't send to a gone account;
     *   - add a hard `do_not_contact` suppression at scope `all` so any future
     *     send is refused by the RetentionPolicyGate (essential legal/security
     *     mail keeps its documented lawful basis, per the gate).
     *
     * SYSTEM authority; idempotent — cancel only touches inflight rows and the
     * suppression is inserted at most once per subject, so a redelivered teardown
     * event is a no-op.
     *
     * @return array{cancelled:int, suppressed:bool}
     */
    public function onAccountTornDown(string $organizationId, string $userId, string $reasonCode): array
    {
        if ($organizationId === '' || $userId === '') {
            return ['cancelled' => 0, 'suppressed' => false];
        }

        // 1) Cancel inflight deliveries.
        $this->db->table('notification_deliveries')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereIn('status', ['queued', 'deferred'])
            ->update([
                'status'             => 'cancelled',
                'suppression_reason' => substr('account_teardown:' . $reasonCode, 0, 60),
                'updated_at'         => $this->clock->nowUtcMicro(),
            ]);
        $cancelled = max(0, (int) $this->db->affectedRows());

        // 2) Hard do-not-contact suppression at scope `all` (insert-once).
        $existing = $this->db->table('notification_suppressions')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('scope', 'all')
            ->where('reason', 'do_not_contact')
            ->countAllResults() > 0;
        $suppressed = false;
        if (! $existing) {
            $this->db->table('notification_suppressions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'user_id'         => $userId,
                'endpoint_hash'   => null,
                'scope'           => 'all',
                'reason'          => 'do_not_contact',
                'created_at'      => $this->clock->nowUtcString(),
            ]);
            $suppressed = true;
        }

        return ['cancelled' => $cancelled, 'suppressed' => $suppressed];
    }

    /**
     * N1 — release DEFERRED deliveries whose `defer_until` has arrived.
     *
     * A delivery deferred for quiet-hours / frequency-cap sat forever because
     * nothing released it. This bounded, idempotent pass finds `deferred` rows
     * whose `defer_until <= now` (indexed by nd_defer_idx), flips each to
     * `queued`, and stages the `notification.dispatch` job so the transport layer
     * sends it — the same queue path a fresh send() uses. Rows with no
     * `defer_until` (legacy) are skipped, not released blindly. Idempotent: once
     * a row is `queued` it no longer matches, so a re-run releases nothing.
     *
     * @param string|null $organizationId null = every org
     * @return int number of deliveries released
     */
    public function releaseDeferred(?string $organizationId, int $limit = 500): int
    {
        $limit = max(1, min(2000, $limit));
        $now   = $this->clock->nowUtcMicro();

        $q = $this->db->table('notification_deliveries')
            ->where('status', 'deferred')
            ->where('defer_until IS NOT NULL', null, false)
            ->where('defer_until <=', $now)
            ->orderBy('defer_until', 'ASC');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $due = $q->get($limit)->getResultArray();

        $released = 0;
        foreach ($due as $row) {
            $deliveryId = (string) $row['id'];

            $this->db->transStart();
            $this->db->table('notification_deliveries')
                ->where('id', $deliveryId)
                ->where('status', 'deferred') // guard against a concurrent release
                ->update([
                    'status'      => 'queued',
                    'defer_until' => null,
                    'updated_at'  => $this->clock->nowUtcMicro(),
                ]);
            $flipped = (int) $this->db->affectedRows() === 1;

            if ($flipped) {
                $this->outbox->stage(
                    'notification',
                    $deliveryId,
                    'notification.dispatch',
                    [
                        'delivery_id'     => $deliveryId,
                        'organization_id' => (string) $row['organization_id'],
                        'channel'         => (string) $row['channel'],
                        'category'        => (string) $row['category'],
                    ],
                    (string) $row['organization_id'],
                );
            }
            $this->db->transComplete();

            if ($flipped && $this->db->transStatus() !== false) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * N4 — build + dispatch daily/weekly DIGESTS.
     *
     * A user whose `digest_frequency` is `daily`/`weekly` has each non-essential
     * message HELD by the gate as `digest_pending` (with a `defer_until` set to
     * the next window boundary) instead of sending instantly. Nothing bundled
     * them, so `daily`/`weekly` behaved exactly like `off` — held forever. This
     * bounded, idempotent pass:
     *
     *   1. finds `digest_pending` rows whose window has closed (`defer_until <=
     *      now`), oldest-first;
     *   2. groups them by (organization_id, user_id, channel) — one digest per
     *      recipient+channel bundling every category that came due;
     *   3. stages ONE `notification.dispatch` job per group carrying the bundled
     *      member list (delivery ids + categories + counts), and
     *   4. flips every bundled member to the terminal `digested` status (guarded,
     *      so a concurrent/re-run pass bundles each row exactly once).
     *
     * Rows with a NULL `defer_until` (legacy) are skipped, not swept. Idempotent:
     * once a row is `digested` it no longer matches.
     *
     * @param string|null $organizationId null = every org
     * @return array{digests:int, messages:int} digests sent + messages bundled
     */
    public function buildDigests(?string $organizationId, int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $now   = $this->clock->nowUtcMicro();

        $q = $this->db->table('notification_deliveries')
            ->where('status', 'digest_pending')
            ->where('defer_until IS NOT NULL', null, false)
            ->where('defer_until <=', $now)
            ->orderBy('defer_until', 'ASC');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $due = $q->get($limit)->getResultArray();
        if ($due === []) {
            return ['digests' => 0, 'messages' => 0];
        }

        // Group by (org, user, channel).
        $groups = [];
        foreach ($due as $row) {
            $key = ($row['organization_id'] ?? '') . '|' . ($row['user_id'] ?? '') . '|' . ($row['channel'] ?? '');
            $groups[$key][] = $row;
        }

        $digests  = 0;
        $messages = 0;

        foreach ($groups as $members) {
            $first   = $members[0];
            $orgId   = (string) $first['organization_id'];
            $userId  = (string) ($first['user_id'] ?? '');
            $channel = (string) $first['channel'];

            $items = [];
            $ids   = [];
            foreach ($members as $m) {
                $id = (string) $m['id'];
                $ids[]   = $id;
                $items[] = [
                    'delivery_id' => $id,
                    'category'    => (string) $m['category'],
                    'created_at'  => (string) ($m['created_at'] ?? ''),
                ];
            }

            $this->db->transStart();
            // Flip all members to `digested` (guarded on the pending status so a
            // concurrent pass can't double-bundle).
            $flipped = 0;
            foreach ($ids as $id) {
                $this->db->table('notification_deliveries')
                    ->where('id', $id)
                    ->where('status', 'digest_pending')
                    ->update([
                        'status'      => 'digested',
                        'defer_until' => null,
                        'updated_at'  => $this->clock->nowUtcMicro(),
                    ]);
                if ((int) $this->db->affectedRows() === 1) {
                    $flipped++;
                }
            }

            if ($flipped > 0) {
                $this->outbox->stage(
                    'notification',
                    // Aggregate id = the first member; the payload carries the full set.
                    (string) $first['id'],
                    'notification.digest.dispatch',
                    [
                        'organization_id' => $orgId,
                        'user_id'         => $userId,
                        'channel'         => $channel,
                        'count'           => $flipped,
                        'items'           => $items,
                    ],
                    $orgId,
                );
            }
            $this->db->transComplete();

            if ($flipped > 0 && $this->db->transStatus() !== false) {
                $digests++;
                $messages += $flipped;
            }
        }

        return ['digests' => $digests, 'messages' => $messages];
    }
}
