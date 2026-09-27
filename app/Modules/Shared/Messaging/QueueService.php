<?php

declare(strict_types=1);

namespace WBS\Shared\Messaging;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Uuid;

/**
 * Database-backed work queue with reservation, backoff and idempotent enqueue
 * (SRS FR-ARC-006/007). Redis fronts hot paths elsewhere; this table is the
 * durable source of truth so a broker outage cannot lose jobs.
 */
final class QueueService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Enqueue a job. When $dedupeKey is set, a repeated enqueue is a no-op
     * (UNIQUE(dedupe_key)) — safe for at-least-once outbox relays.
     *
     * @param array<string,mixed> $payload
     */
    public function enqueue(string $topic, array $payload, string $queue = 'default', ?string $dedupeKey = null, int $delaySeconds = 0): string
    {
        $now       = $this->clock->now();
        $available = $now->modify("+{$delaySeconds} seconds")->format('Y-m-d H:i:s.u');
        $id        = Uuid::v7();

        try {
            $this->db->table('queue_jobs')->insert([
                'id'           => $id,
                'queue'        => $queue,
                'topic'        => $topic,
                'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'dedupe_key'   => $dedupeKey,
                'status'       => 'ready',
                'attempts'     => 0,
                'available_at' => $available,
                'created_at'   => $now->format('Y-m-d H:i:s.u'),
            ]);
        } catch (Throwable) {
            // Duplicate dedupe_key -> already enqueued.
            $existing = $this->db->table('queue_jobs')->where('dedupe_key', $dedupeKey)->get()->getRowArray();

            return $existing !== null ? (string) $existing['id'] : $id;
        }

        return $id;
    }

    /**
     * Atomically reserve the next ready job for a worker.
     *
     * @return array<string,mixed>|null
     */
    public function reserve(string $worker, string $queue = 'default'): ?array
    {
        $now = $this->clock->nowUtcMicro();

        $this->db->transStart();
        // SELECT ... FOR UPDATE SKIP LOCKED to avoid two workers grabbing one job.
        $sql = 'SELECT id FROM queue_jobs
                WHERE queue = ? AND status = "ready" AND available_at <= ?
                ORDER BY available_at ASC
                LIMIT 1 FOR UPDATE SKIP LOCKED';
        $row = $this->db->query($sql, [$queue, $now])->getRowArray();

        if ($row === null) {
            $this->db->transComplete();

            return null;
        }

        $this->db->table('queue_jobs')
            ->where('id', $row['id'])
            ->set('attempts', 'attempts + 1', false)
            ->set([
                'status'      => 'reserved',
                'reserved_at' => $now,
                'reserved_by' => $worker,
            ])
            ->update();
        $this->db->transComplete();

        return $this->db->table('queue_jobs')->where('id', $row['id'])->get()->getRowArray();
    }

    /** Mark a reserved job done. */
    public function complete(string $jobId): void
    {
        $this->db->table('queue_jobs')->where('id', $jobId)->update([
            'status'       => 'done',
            'completed_at' => $this->clock->nowUtcMicro(),
        ]);
    }

    /** Fail a job: retry with exponential backoff, or move to dead-letter. */
    public function fail(string $jobId, string $error): void
    {
        $job = $this->db->table('queue_jobs')->where('id', $jobId)->get()->getRowArray();
        if ($job === null) {
            return;
        }

        $attempts = (int) $job['attempts'];
        if ($attempts >= (int) $job['max_attempts']) {
            $this->db->table('queue_jobs')->where('id', $jobId)->update([
                'status'     => 'dead',
                'last_error' => substr($error, 0, 500),
            ]);

            return;
        }

        $backoff   = min(3600, 2 ** min($attempts, 11)); // capped exponential seconds
        $available = $this->clock->now()->modify("+{$backoff} seconds")->format('Y-m-d H:i:s.u');
        $this->db->table('queue_jobs')->where('id', $jobId)->update([
            'status'       => 'ready',
            'available_at' => $available,
            'reserved_at'  => null,
            'reserved_by'  => null,
            'last_error'   => substr($error, 0, 500),
        ]);
    }
}
