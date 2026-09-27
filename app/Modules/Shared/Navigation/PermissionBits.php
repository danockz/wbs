<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * FROZEN permission -> bit-index map (SRS: dynamic menu, best-case complexity).
 *
 * The platform's permission universe is small and finite (P = 41 today). Because
 * P <= 64 the ENTIRE capability set of a subject fits in a single 64-bit integer
 * ("capability word"). Visibility of a menu item then reduces to one bitwise AND +
 * compare -- Theta(1), branch-predictable, zero memory traffic -- instead of a
 * multi-table PDP call per item. See docs/DYNAMIC-MENU-ALGORITHMIC.md.
 *
 * INVARIANTS (enforced by self-test + CI):
 *   - Bit indices are ASSIGNED ONCE and NEVER reused. Reusing a retired code's bit
 *     would silently mis-grant. To retire a code, leave its bit reserved (gap) and
 *     never hand it to a different code.
 *   - 0 <= bit <= 62. Bit 63 is reserved (sign bit) so the word stays a safe
 *     non-negative PHP int on 64-bit builds and survives JSON/DB round-trips.
 *   - The map is append-only: new permissions take the next free index.
 *
 * This class is pure data + pure functions: no DB, no I/O, no state. It is the
 * "hardcoded" half of the dynamic/hardcoded mixture -- the vocabulary is compiled
 * in; WHO holds which bits stays fully dynamic (computed from grants at write time).
 */
final class PermissionBits
{
    /**
     * code => bit index. APPEND-ONLY. Do not renumber. Do not reuse a gap.
     *
     * @var array<string,int>
     */
    private const MAP = [
        'access.assignment.manage'        => 0,
        'access.break_glass'              => 1,
        'access.break_glass.review'       => 2,
        'access.policy.manage'            => 3,
        'access.request.approve'          => 4,
        'access.role.manage'              => 5,
        'access.rule.manage'              => 6,
        'admin.manage'                    => 7,
        'attendance.check_in'             => 8,
        'community.moderate'              => 9,
        'contribution.manage'            => 10,
        'contribution.refund.approve'    => 11,
        'contribution.refund.request'    => 12,
        'course.completion.override'     => 13,
        'course.create'                  => 14,
        'event.certificate.manage'       => 15,
        'event.create'                   => 16,
        'event.expense.approve'          => 17,
        'event.expense.submit'           => 18,
        'event.feedback.manage'          => 19,
        'event.logistics.manage'         => 20,
        'event.media.manage'             => 21,
        'event.schedule.approve'         => 22,
        'event.tickets.manage'           => 23,
        'gamification.manage'            => 24,
        'group.change.approve'           => 25,
        'group.create'                   => 26,
        'group.move'                     => 27,
        'identity.manage'                => 28,
        'integration.connection.approve' => 29,
        'meeting.manage'                 => 30,
        'notification.broadcast.approve' => 31,
        'notification.send'              => 32,
        'provider.configure'             => 33,
        'referral.link.create'           => 34,
        'report.export'                  => 35,
        'report.view'                    => 36,
        'sponsor.reassign.approve'       => 37,
        'stream.create'                  => 38,
        'stream.moderate'                => 39,
        'venue.manage'                   => 40,
    ];

    /** Highest legal bit index for a real permission (63 = sign bit reserved). */
    public const MAX_BIT = 62;

    /**
     * Sentinel requirement mask that NO capability word can satisfy (bit 62 is
     * never assigned to a real permission). Returned for unknown codes so a typo
     * fails CLOSED -- the item is hidden, never exposed.
     */
    public const UNSATISFIABLE = 1 << self::MAX_BIT;

    /** Bit index for a code, or null if the code is unknown. */
    public static function bit(string $code): ?int
    {
        return self::MAP[$code] ?? null;
    }

    /**
     * The single-bit requirement mask for one permission code. Unknown code -> 0,
     * which (see MenuService) is treated as "never satisfiable" so a typo hides the
     * item rather than exposing it (fail-closed).
     */
    public static function mask(string $code): int
    {
        $bit = self::MAP[$code] ?? null;

        return $bit === null ? self::UNSATISFIABLE : (1 << $bit);
    }

    /**
     * Combined mask for a set of codes (AND-semantics requirement: all bits set).
     *
     * @param list<string> $codes
     */
    public static function maskAll(array $codes): int
    {
        $m = 0;
        foreach ($codes as $c) {
            $bit = self::MAP[$c] ?? null;
            if ($bit === null) {
                // An unknown code makes the whole requirement unsatisfiable (fail-closed).
                return self::UNSATISFIABLE;
            }
            $m |= (1 << $bit);
        }

        return $m;
    }

    /** Union mask of every known permission bit (for validation/debug). */
    public static function allKnownMask(): int
    {
        $m = 0;
        foreach (self::MAP as $bit) {
            $m |= (1 << $bit);
        }

        return $m;
    }

    /** Number of permissions currently mapped (P). */
    public static function count(): int
    {
        return count(self::MAP);
    }

    /** @return array<string,int> copy of the frozen map (for tooling/tests). */
    public static function all(): array
    {
        return self::MAP;
    }

    /**
     * Self-check of the frozen invariants. Cheap; call from a CI/boot assertion.
     * Returns a list of human-readable violations (empty = healthy).
     *
     * @return list<string>
     */
    public static function selfCheck(): array
    {
        $errors = [];
        $seen   = [];
        foreach (self::MAP as $code => $bit) {
            if ($bit < 0 || $bit > self::MAX_BIT) {
                $errors[] = "bit out of range [0,{$bit} > " . self::MAX_BIT . "] for {$code}";
            }
            if (isset($seen[$bit])) {
                $errors[] = "duplicate bit {$bit}: {$seen[$bit]} and {$code}";
            }
            $seen[$bit] = $code;
        }

        return $errors;
    }
}
