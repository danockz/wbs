<?php

declare(strict_types=1);

namespace WBS\Events\Support;

/**
 * HOW MUCH the group leader oversees a committee — the configurable rung of the
 * ladder, resolved from HIERARCHICAL GROUP CONFIG (capability `event_committee`,
 * key `oversight`), never from env or a global.
 *
 *  - `formation_and_major` (the default when a body turns committees on): the
 *    leader approves the committee's formation, its chair, and MAJOR decisions —
 *    money above the configured threshold, date/schedule changes, cancellation and
 *    publication. Day-to-day plan work stays with the committee.
 *  - `maker_checker_all`: every decision the committee records goes to the leader
 *    first, whatever its kind or amount.
 *  - `observe_only`: the leader is INFORMED (every decision is still recorded and
 *    visible in the oversight queue, and still audited) but nothing is blocked on
 *    approval; the committee self-manages inside its delegations.
 *
 * Approving a decision demands the SAME authority as doing the thing directly: a
 * budget decision requires `event.expense.approve`, a schedule/cancellation/
 * publication decision `event.schedule.approve`, anything else `event.create`.
 * `governance` covers the committee's own constitution — appointing or replacing
 * its chair, adding a responsibility nobody holds — which is the leader's call.
 * So the queue cannot become a back door around an existing gate, and no new
 * permission bit is needed to run it.
 *
 * Like the platform's other review queues there is NO expiry: a decision stays
 * pending until a human decides it.
 */
final class CommitteeOversight
{
    public const FORMATION_AND_MAJOR = 'formation_and_major';
    public const MAKER_CHECKER_ALL   = 'maker_checker_all';
    public const OBSERVE_ONLY        = 'observe_only';

    /** @var list<string> */
    public const ALL = [self::FORMATION_AND_MAJOR, self::MAKER_CHECKER_ALL, self::OBSERVE_ONLY];

    // Decision kinds (the fixed vocabulary of `event_committee_decisions.kind`).
    public const KIND_BUDGET     = 'budget';
    public const KIND_SCHEDULE   = 'schedule';
    public const KIND_CANCELLATION = 'cancellation';
    public const KIND_PUBLICATION  = 'publication';
    public const KIND_GOVERNANCE = 'governance';
    public const KIND_OTHER      = 'other';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_BUDGET,
        self::KIND_SCHEDULE,
        self::KIND_CANCELLATION,
        self::KIND_PUBLICATION,
        self::KIND_GOVERNANCE,
        self::KIND_OTHER,
    ];

    /** Kinds that are "major" under the default rung. @var list<string> */
    private const MAJOR_KINDS = [
        self::KIND_SCHEDULE,
        self::KIND_CANCELLATION,
        self::KIND_PUBLICATION,
        self::KIND_GOVERNANCE,
    ];

    /**
     * kind => the existing capability the APPROVER must hold over the oversight
     * group. Reusing the underlying action's own bit keeps segregation of duties
     * and the frozen permission budget intact.
     *
     * @var array<string,string>
     */
    private const APPROVAL_PERMISSIONS = [
        self::KIND_BUDGET       => 'event.expense.approve',
        self::KIND_SCHEDULE     => 'event.schedule.approve',
        self::KIND_CANCELLATION => 'event.schedule.approve',
        self::KIND_PUBLICATION  => 'event.schedule.approve',
        self::KIND_GOVERNANCE   => 'event.create',
        self::KIND_OTHER        => 'event.create',
    ];

    private function __construct()
    {
    }

    public static function isValid(?string $mode): bool
    {
        return $mode !== null && in_array($mode, self::ALL, true);
    }

    public static function normalize(?string $mode): string
    {
        $m = strtolower(trim((string) $mode));

        return self::isValid($m) ? $m : self::FORMATION_AND_MAJOR;
    }

    public static function normalizeKind(?string $kind): string
    {
        $k = strtolower(trim((string) $kind));

        return in_array($k, self::KINDS, true) ? $k : self::KIND_OTHER;
    }

    /** The capability an approver must hold for this kind of decision. */
    public static function permissionForKind(string $kind): string
    {
        return self::APPROVAL_PERMISSIONS[self::normalizeKind($kind)] ?? 'event.create';
    }

    public static function isMajor(string $kind): bool
    {
        return in_array(self::normalizeKind($kind), self::MAJOR_KINDS, true);
    }

    /**
     * Does this decision need the leader's approval under the configured rung?
     *
     * A budget decision is "major" when it carries an amount at or above the
     * configured threshold; with no threshold configured (null) EVERY budget
     * decision is major, because a body that set no limit has not said what the
     * committee may spend alone.
     *
     * @param float|null $amount    the decision's amount, when it has one
     * @param float|null $threshold config `budget_approval_threshold`
     */
    public static function requiresApproval(string $mode, string $kind, ?float $amount = null, ?float $threshold = null): bool
    {
        $mode = self::normalize($mode);
        $kind = self::normalizeKind($kind);

        if ($mode === self::OBSERVE_ONLY) {
            return false;
        }
        if ($mode === self::MAKER_CHECKER_ALL) {
            return true;
        }

        // formation_and_major
        if ($kind === self::KIND_BUDGET) {
            if ($amount === null) {
                return false;
            }

            return $threshold === null || $amount >= $threshold;
        }

        return self::isMajor($kind);
    }

    public static function labelKey(string $mode): string
    {
        return 'Events.committee.oversight' . str_replace('_', '', ucwords(self::normalize($mode), '_'));
    }

    /**
     * i18n key for a decision KIND's localized label. Kinds belong to the oversight
     * queue's own vocabulary, so they live in the `decision` group (all six locales
     * carry them) — one source of truth for the queue, the hub and the console.
     */
    public static function kindLabelKey(string $kind): string
    {
        return 'Events.decision.kind' . ucfirst(self::normalizeKind($kind));
    }

    /** i18n key for a decision STATUS's localized label. */
    public static function statusLabelKey(string $status): string
    {
        $s = strtolower(trim($status));

        return 'Events.decision.status' . ucfirst(in_array($s, ['pending', 'approved', 'rejected', 'cancelled', 'noted'], true) ? $s : 'pending');
    }
}
