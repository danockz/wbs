<?php

declare(strict_types=1);

namespace WBS\Shared\Messaging;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;

/**
 * Relays pending outbox rows to the queue exactly once (SRS FR-ARC-005).
 *
 * The outbox id is used as the queue dedupe_key, so even if the relay crashes
 * after enqueue but before marking the row dispatched, the retry cannot create
 * a duplicate job. Consumers remain idempotent as a second line of defence.
 */
final class OutboxRelay
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly QueueService $queue,
        private readonly Clock $clock,
    ) {
    }

    /** Relay up to $limit pending messages. Returns count dispatched. */
    public function relayBatch(int $limit = 100): int
    {
        $now  = $this->clock->nowUtcMicro();
        $rows = $this->db->table('outbox_messages')
            ->where('status', 'pending')
            ->where('available_at <=', $now)
            ->orderBy('available_at', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        $dispatched = 0;
        foreach ($rows as $row) {
            try {
                $payload = json_decode((string) $row['payload'], true) ?? [];
                $this->queue->enqueue(
                    $row['topic'],
                    [
                        'outbox_id'      => $row['id'],
                        'aggregate_type' => $row['aggregate_type'],
                        'aggregate_id'   => $row['aggregate_id'],
                        'organization_id' => $row['organization_id'],
                        'data'           => $payload,
                    ],
                    'default',
                    $row['id'], // dedupe_key = outbox id -> exactly once
                );

                $this->db->table('outbox_messages')->where('id', $row['id'])->update([
                    'status'        => 'dispatched',
                    'dispatched_at' => $this->clock->nowUtcMicro(),
                ]);
                $dispatched++;
            } catch (Throwable $e) {
                $this->db->table('outbox_messages')
                    ->where('id', $row['id'])
                    ->set('attempts', 'attempts + 1', false)
                    ->set('last_error', substr($e->getMessage(), 0, 500))
                    ->update();
            }
        }

        return $dispatched;
    }
}
