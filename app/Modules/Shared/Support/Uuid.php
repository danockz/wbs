<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

/**
 * UUIDv7 generator (time-ordered). Time-ordered ids keep primary keys roughly
 * monotonic, which helps InnoDB insert locality and lets us derive a rough
 * creation order from the id itself. Falls back to a compliant random layout.
 */
final class Uuid
{
    /** Generate a UUIDv7 string. */
    public static function v7(): string
    {
        $unixMs = (int) (microtime(true) * 1000);

        // 48-bit timestamp (ms), big-endian.
        $tsHex = str_pad(dechex($unixMs), 12, '0', STR_PAD_LEFT);

        $rand = random_bytes(10);
        // Set version (7) in the 7th byte high nibble.
        $rand[0] = chr((ord($rand[0]) & 0x0F) | 0x70);
        // Set variant (10xx) in the 9th byte high bits.
        $rand[2] = chr((ord($rand[2]) & 0x3F) | 0x80);

        $randHex = bin2hex($rand);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($tsHex, 0, 8),
            substr($tsHex, 8, 4),
            substr($randHex, 0, 4),
            substr($randHex, 4, 4),
            substr($randHex, 8, 12),
        );
    }
}
