<?php

declare(strict_types=1);

namespace WBS\Shared\Messaging;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Uuid;

/**
 * Consumer-side idempotency ledger (SRS FR-ARC-005: "consumers are idempotent").
 *
 * A handler calls {@see markProcessed()} for a (consumer, key). If the key was
 * already recorded the redelivered message is a no-op. UNIQUE(consumer, key)
 * makes the check atomic under concurrency.
 */
final class IdempotencyStore
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /** True if this (consumer,key) has already been processed. */
    public function seen(string $consumer, string $key): bool
    {
        return $this->db->table('processed_messages')
            ->where('consumer', $consumer)
            ->where('message_key', $key)
            ->countAllResults() > 0;
    }

    /**
     * Record processing. Returns true if newly recorded (proceed), false if it
     * was already processed (skip) — the atomic guard against double-handling.
     */
    public function markProcessed(string $consumer, string $key): bool
    {
        try {
            $this->db->table('processed_messages')->insert([
                'id'           => Uuid::v7(),
                'consumer'     => $consumer,
                'message_key'  => $key,
                'processed_at' => $this->clock->nowUtcMicro(),
            ]);

            return true;
        } catch (Throwable) {
            return false; // UNIQUE violation -> already processed
        }
    }
}
