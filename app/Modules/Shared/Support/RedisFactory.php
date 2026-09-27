<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

use Redis;
use Throwable;

/**
 * Thin factory that returns a connected phpredis client or null when Redis is
 * unavailable. Callers decide fail-open/fail-closed per policy — security and
 * financial paths must fail CLOSED when Redis is down (SRS FR-RL-005).
 */
final class RedisFactory
{
    public static function connect(
        string $host = '127.0.0.1',
        int $port = 6379,
        float $timeout = 0.5,
        ?string $password = null,
    ): ?Redis {
        if (! class_exists(Redis::class)) {
            // The phpredis EXTENSION is missing — distinct from the server being
            // down. This is the usual cause of "Redis is running but the app
            // still can't use it" (e.g. the extension isn't enabled in the PHP
            // build). Log it once so it's diagnosable; callers still fail per
            // their policy (security/financial paths fail CLOSED).
            log_message(
                'critical',
                'RedisFactory: phpredis extension NOT installed/enabled (class Redis missing). '
                . 'Enable the "redis" PHP extension and restart PHP — a running redis-server is not enough.',
            );

            return null;
        }

        try {
            $r = new Redis();
            if (! $r->connect($host, $port, $timeout)) {
                log_message(
                    'critical',
                    'RedisFactory: could not connect to redis at {host}:{port} (timeout {t}s).',
                    ['host' => $host, 'port' => (string) $port, 't' => (string) $timeout],
                );

                return null;
            }
            if ($password !== null && $password !== '') {
                $r->auth($password);
            }

            return $r;
        } catch (Throwable $e) {
            log_message(
                'critical',
                'RedisFactory: error connecting to redis at {host}:{port}: {msg}',
                ['host' => $host, 'port' => (string) $port, 'msg' => $e->getMessage()],
            );

            return null;
        }
    }
}
