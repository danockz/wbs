<?php

declare(strict_types=1);

namespace WBS\Events\Support;

/**
 * The `event_committee` capability, resolved for one group and normalized.
 *
 * Read through the module's existing config port (`EventConfigPort` →
 * `EffectiveConfigAdapter` → `EffectiveConfigResolver`), so the value inherits
 * down the hierarchy like every other group capability: a national default with
 * `ancestor_default_child_override` lets a region or assembly tighten or relax its
 * own committees without touching code.
 *
 * DEFAULT OFF, strictly: when nothing resolves (no row anywhere on the chain) the
 * capability is null and `enabled` is false, so no committee can be formed and an
 * event behaves exactly as it did before committees existed. A malformed value is
 * treated the same way — a bad config row must never turn the feature on.
 *
 * Recognized keys (all optional, all clamped):
 *   enabled                    bool   default false
 *   oversight                  string CommitteeOversight::ALL, default formation_and_major
 *   max_members                int    2..50, default 12
 *   chair_requires_approval    bool   default true
 *   allow_subdelegation        bool   default true  (chair may delegate on to members)
 *   grace_days                 int    0..90, default 7 (post-event hand-over window)
 *   budget_approval_threshold  number default null (null ⇒ every budget decision is major)
 *   allow_crosscut             bool   default false (delegations never reach cross-cut groups)
 */
final class CommitteeConfig
{
    public const CAPABILITY = 'event_committee';

    public const DEFAULT_MAX_MEMBERS = 12;
    public const DEFAULT_GRACE_DAYS  = 7;

    private function __construct(
        public readonly bool $enabled,
        public readonly string $oversight,
        public readonly int $maxMembers,
        public readonly bool $chairRequiresApproval,
        public readonly bool $allowSubdelegation,
        public readonly int $graceDays,
        public readonly ?float $budgetApprovalThreshold,
        public readonly bool $allowCrosscut,
    ) {
    }

    /** The feature is off — the only state a body starts in. */
    public static function off(): self
    {
        return new self(false, CommitteeOversight::FORMATION_AND_MAJOR, self::DEFAULT_MAX_MEMBERS, true, true, self::DEFAULT_GRACE_DAYS, null, false);
    }

    /**
     * Build from a resolved capability value: an array, a JSON string, or null.
     * Anything unparseable yields `off()` (fail closed).
     */
    public static function fromResolved(mixed $value): self
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($value)) {
            return self::off();
        }

        $enabled = self::bool($value['enabled'] ?? null, false);
        if (! $enabled) {
            // Off is off, whatever else the row says.
            return self::off();
        }

        $threshold = null;
        if (isset($value['budget_approval_threshold']) && is_numeric($value['budget_approval_threshold'])) {
            $t         = (float) $value['budget_approval_threshold'];
            $threshold = $t >= 0.0 ? $t : null;
        }

        return new self(
            true,
            CommitteeOversight::normalize(isset($value['oversight']) ? (string) $value['oversight'] : null),
            self::intInRange($value['max_members'] ?? null, 2, 50, self::DEFAULT_MAX_MEMBERS),
            self::bool($value['chair_requires_approval'] ?? null, true),
            self::bool($value['allow_subdelegation'] ?? null, true),
            self::intInRange($value['grace_days'] ?? null, 0, 90, self::DEFAULT_GRACE_DAYS),
            $threshold,
            self::bool($value['allow_crosscut'] ?? null, false),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'enabled'                   => $this->enabled,
            'oversight'                 => $this->oversight,
            'max_members'               => $this->maxMembers,
            'chair_requires_approval'   => $this->chairRequiresApproval,
            'allow_subdelegation'       => $this->allowSubdelegation,
            'grace_days'                => $this->graceDays,
            'budget_approval_threshold' => $this->budgetApprovalThreshold,
            'allow_crosscut'            => $this->allowCrosscut,
        ];
    }

    private static function bool(mixed $v, bool $default): bool
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v !== 0;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function intInRange(mixed $v, int $min, int $max, int $default): int
    {
        if (! is_numeric($v)) {
            return $default;
        }
        $n = (int) floor((float) $v);

        return max($min, min($max, $n));
    }
}
