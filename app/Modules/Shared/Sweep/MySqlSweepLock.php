<?php

declare(strict_types=1);

namespace WBS\Shared\Sweep;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Production SweepLock backed by MySQL advisory locks (Theme C).
 *
 * GET_LOCK(name, 0) tries once without blocking; the lock is held for the life of
 * the DB connection and auto-releases if the worker/connection dies, so a crashed
 * runner can never wedge a sweep permanently. Lock names are namespaced with a
 * prefix and truncated to MySQL's 64-char limit.
 *
 * Any driver error (non-MySQL backend, permission) fails CLOSED — acquire()
 * returns false so the sweep is skipped rather than risking an unguarded
 * concurrent run.
 */
final class MySqlSweepLock implements SweepLock
{
    private const PREFIX = 'wbs_sweep:';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function acquire(string $name): bool
    {
        try {
            $row = $this->db->query('SELECT GET_LOCK(?, 0) AS ok', [$this->name($name)])->getRowArray();

            return (int) ($row['ok'] ?? 0) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public function release(string $name): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(?) AS released', [$this->name($name)]);
        } catch (Throwable) {
            // best-effort; the connection close will drop it anyway.
        }
    }

    private function name(string $name): string
    {
        return substr(self::PREFIX . $name, 0, 64);
    }
}
