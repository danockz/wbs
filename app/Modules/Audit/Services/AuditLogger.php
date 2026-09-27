<?php

declare(strict_types=1);

namespace WBS\Audit\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Append-only, hash-chained audit writer (SRS NFR-SEC-007).
 *
 * Each entry chains to the previous one for its organization:
 *   entry_hash = sha256(prev_hash || canonical_json(core_fields))
 * so any later edit/removal breaks the chain. verifyChain() re-walks the log
 * and returns the first broken sequence, if any. Secrets are never stored —
 * callers pass already-redacted metadata.
 */
final class AuditLogger
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Record an audit entry. Returns the created id + entry_hash.
     *
     * @param array<string,mixed> $data action, actor_id, actor_type, object_type,
     *        object_id, outcome, metadata, ip_hash
     */
    public function record(string $organizationId, array $data): Result
    {
        $action = trim((string) ($data['action'] ?? ''));
        if ($action === '') {
            return Result::fail('ACTION_REQUIRED', 'audit.action_required', 422);
        }

        // Determine the tail (prev hash + next sequence) for this organization.
        $last = $this->db->table('audit_log')
            ->select('seq, entry_hash')
            ->where('organization_id', $organizationId)
            ->orderBy('seq', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        $prevHash = $last['entry_hash'] ?? self::GENESIS;
        $seq      = ($last !== null ? (int) $last['seq'] : 0) + 1;

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();

        $core = [
            'organization_id' => $organizationId,
            'seq'             => $seq,
            'actor_id'        => $data['actor_id'] ?? null,
            'actor_type'      => (string) ($data['actor_type'] ?? 'user'),
            'action'          => $action,
            'object_type'     => $data['object_type'] ?? null,
            'object_id'       => $data['object_id'] ?? null,
            'outcome'         => (string) ($data['outcome'] ?? 'success'),
            'metadata'        => $this->redact((array) ($data['metadata'] ?? [])),
            'created_at'      => $now,
        ];

        $entryHash = $this->hash($prevHash, $core);

        // NOTE: use array_merge (right-hand wins), NOT the `+` union operator.
        // `$core` carries `metadata` as a raw PHP array (for hashing); the row we
        // persist must store it as a JSON STRING. With `$core + [...]` the left
        // operand's `metadata` (the array) would win and MySQL would render it as
        // a row constructor `('a','b','c')` → "Operand should contain 1 column(s)".
        $this->db->table('audit_log')->insert(array_merge($core, [
            'id'         => $id,
            'metadata'   => json_encode($core['metadata']),
            'ip_hash'    => $data['ip_hash'] ?? null,
            'prev_hash'  => $prevHash,
            'entry_hash' => $entryHash,
        ]));

        return Result::created(['id' => $id, 'seq' => $seq, 'entry_hash' => $entryHash]);
    }

    /**
     * Re-walk the chain for an organization. Returns ok when intact; on
     * corruption returns the offending sequence number.
     */
    public function verifyChain(string $organizationId): Result
    {
        $rows = $this->db->table('audit_log')
            ->where('organization_id', $organizationId)
            ->orderBy('seq', 'ASC')
            ->get()->getResultArray();

        $prev = self::GENESIS;
        foreach ($rows as $row) {
            $core = [
                'organization_id' => $row['organization_id'],
                'seq'             => (int) $row['seq'],
                'actor_id'        => $row['actor_id'],
                'actor_type'      => $row['actor_type'],
                'action'          => $row['action'],
                'object_type'     => $row['object_type'],
                'object_id'       => $row['object_id'],
                'outcome'         => $row['outcome'],
                'metadata'        => $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : [],
                'created_at'      => $row['created_at'],
            ];
            $expected = $this->hash((string) $row['prev_hash'], $core);
            if (! hash_equals($expected, (string) $row['entry_hash']) || ! hash_equals($prev, (string) $row['prev_hash'])) {
                return Result::fail('CHAIN_BROKEN', 'audit.chain_broken', 409, ['at_seq' => (int) $row['seq']]);
            }
            $prev = (string) $row['entry_hash'];
        }

        return Result::ok(['verified' => true, 'entries' => count($rows)]);
    }

    /** @param array<string,mixed> $core */
    private function hash(string $prevHash, array $core): string
    {
        // Canonical JSON: sort keys recursively for a stable digest.
        return hash('sha256', $prevHash . '|' . $this->canonicalJson($core));
    }

    /** @param array<string,mixed> $data */
    private function canonicalJson(array $data): string
    {
        $this->ksortRecursive($data);

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $data */
    private function ksortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }

    /**
     * Defensive redaction: strip common secret-bearing keys so they can never
     * be persisted even if a caller passes them by mistake.
     *
     * @param array<string,mixed> $meta
     *
     * @return array<string,mixed>
     */
    private function redact(array $meta): array
    {
        $blocked = ['password', 'secret', 'token', 'api_key', 'authorization', 'card', 'cvv', 'pan', 'secret_key'];
        foreach ($meta as $k => $v) {
            if (in_array(strtolower((string) $k), $blocked, true)) {
                $meta[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $meta[$k] = $this->redact($v);
            }
        }

        return $meta;
    }
}
