<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Injectable clock. All persisted timestamps are UTC with microsecond
 * precision so append-only ledgers and idempotency windows order correctly.
 * Tests can freeze() time for deterministic assertions.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public function now(): DateTimeImmutable
    {
        if (self::$frozen !== null) {
            return self::$frozen;
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** UTC 'Y-m-d H:i:s' (second precision) for typical DATETIME columns. */
    public function nowUtcString(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    /** UTC with microseconds for high-resolution ordering. */
    public function nowUtcMicro(): string
    {
        return $this->now()->format('Y-m-d H:i:s.u');
    }

    /** Freeze time for tests; pass null to resume real time. */
    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }
}
