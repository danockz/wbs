<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Payment webhook inbox (SRS FR-VBCS-006).
 *
 *  - Verify signature/timestamp BEFORE state change (caller passes the verified
 *    flag from the provider adapter's verifyWebhook).
 *  - Idempotent on UNIQUE(provider, provider_event_id): a replayed webhook is a
 *    no-op.
 *  - Unverified or unknown-type events are QUARANTINED (never silently applied)
 *    and flagged for alerting.
 */
final class WebhookInboxService
{
    /** Event types the platform knows how to act on. */
    public const KNOWN_TYPES = [
        'payment.succeeded',
        'payment.failed',
        'refund.succeeded',
        'charge.dispute.created',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Record an inbound webhook. Returns whether it should be processed now.
     *
     * @return Result data: {inbox_id, action: process|duplicate|quarantined}
     */
    public function receive(string $provider, string $providerEventId, string $eventType, bool $verified, ?string $payloadRef = null): Result
    {
        $now = $this->clock->nowUtcMicro();

        // Idempotency: already seen this provider event?
        $existing = $this->db->table('webhook_inbox')
            ->where('provider', $provider)->where('provider_event_id', $providerEventId)
            ->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok([
                'inbox_id' => $existing['id'],
                'action'   => 'duplicate',
                'status'   => $existing['status'],
            ], 200, ['deduplicated' => true]);
        }

        $quarantine = ! $verified || ! in_array($eventType, self::KNOWN_TYPES, true);
        $status     = $quarantine ? 'quarantined' : 'received';
        $id         = Uuid::v7();

        try {
            $this->db->table('webhook_inbox')->insert([
                'id'                => $id,
                'provider'          => $provider,
                'provider_event_id' => $providerEventId,
                'event_type'        => $eventType,
                'verified'          => $verified ? 1 : 0,
                'status'            => $status,
                'payload_ref'       => $payloadRef,
                'received_at'       => $now,
            ]);
        } catch (Throwable) {
            // Race: another worker inserted the same event first.
            return Result::ok(['action' => 'duplicate'], 200, ['deduplicated' => true]);
        }

        if ($quarantine) {
            return Result::ok([
                'inbox_id' => $id,
                'action'   => 'quarantined',
                'reason'   => ! $verified ? 'unverified' : 'unknown_type',
            ], 202);
        }

        return Result::created(['inbox_id' => $id, 'action' => 'process']);
    }

    /** Mark an inbox row processed after its handler completes. */
    public function markProcessed(string $inboxId): Result
    {
        $this->db->table('webhook_inbox')->where('id', $inboxId)->update([
            'status'       => 'processed',
            'processed_at' => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['inbox_id' => $inboxId, 'status' => 'processed']);
    }

    /**
     * Mark a received event as failed (gap C2): a KNOWN type whose handler
     * errored or is not implemented must NOT be silently `processed`. Leaving it
     * `failed` keeps an operational signal (it can be surfaced/retried) instead
     * of acking + dropping a money event.
     */
    public function markFailed(string $inboxId, string $reason = ''): Result
    {
        $this->db->table('webhook_inbox')->where('id', $inboxId)->update([
            'status'       => 'failed',
            'processed_at' => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['inbox_id' => $inboxId, 'status' => 'failed', 'reason' => $reason]);
    }
}
