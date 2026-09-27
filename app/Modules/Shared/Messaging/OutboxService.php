<?php

declare(strict_types=1);

namespace WBS\Shared\Messaging;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Uuid;

/**
 * Transactional outbox (SRS FR-ARC-005).
 *
 * Call {@see stage()} INSIDE the same DB transaction as the domain write, so
 * the message is persisted atomically with the state change. A relay
 * ({@see OutboxRelay}) later moves pending rows to the queue exactly once.
 */
final class OutboxService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Stage a message. MUST run within the caller's active transaction.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $headers
     */
    public function stage(
        string $aggregateType,
        string $aggregateId,
        string $topic,
        array $payload,
        ?string $organizationId = null,
        array $headers = [],
    ): string {
        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('outbox_messages')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'aggregate_type'  => $aggregateType,
            'aggregate_id'    => $aggregateId,
            'topic'           => $topic,
            'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'headers'         => $headers === [] ? null : json_encode($headers, JSON_UNESCAPED_UNICODE),
            'status'          => 'pending',
            'attempts'        => 0,
            'available_at'    => $now,
            'created_at'      => $now,
        ]);

        return $id;
    }
}
