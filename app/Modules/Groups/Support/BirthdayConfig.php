<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

/**
 * Hierarchical group config for birthdays (capability `groups.birthdays`).
 *
 * Resolved through EffectiveConfigResolver like every other gated capability.
 * DEFAULT OFF / fail-closed: no row, unparseable value, or enabled≠true yields
 * the off shape — no hub, no overlay, no notices, no peers, no leaders.
 *
 * When enabled, own birthday is always in view. Peers / ancestor leaders and
 * the three surfaces (hub, in-app notify, calendar overlay) are independently
 * switchable. Windows clamp to 1..366 days.
 */
final class BirthdayConfig
{
    public const CAPABILITY         = 'groups.birthdays';
    public const DEFAULT_PEER_DAYS  = 7;
    public const DEFAULT_LEADER_DAYS = 30;

    private function __construct(
        public readonly bool $enabled,
        public readonly int $peerDays,
        public readonly int $leaderDays,
        public readonly bool $showPeers,
        public readonly bool $showLeaders,
        public readonly bool $hub,
        public readonly bool $notify,
        public readonly bool $calendar,
    ) {
    }

    public static function off(): self
    {
        return new self(false, self::DEFAULT_PEER_DAYS, self::DEFAULT_LEADER_DAYS, false, false, false, false, false);
    }

    /**
     * On with agreed defaults: peers 7d, leaders 30d, all surfaces, both audiences.
     * Used by tests and as the shape a body gets after flipping enabled:true
     * without listing every key.
     */
    public static function on(array $over = []): self
    {
        return self::fromResolved(['enabled' => true] + $over);
    }

    /** Build from a resolved capability value. Anything unparseable → off(). */
    public static function fromResolved(mixed $value): self
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($value)) {
            return self::off();
        }

        $enabled = self::bool($value['enabled'] ?? null, false);
        if (! $enabled) {
            return self::off();
        }

        return new self(
            true,
            self::days($value['peer_days'] ?? null, self::DEFAULT_PEER_DAYS),
            self::days($value['leader_days'] ?? null, self::DEFAULT_LEADER_DAYS),
            self::bool($value['show_peers'] ?? null, true),
            self::bool($value['show_leaders'] ?? null, true),
            self::bool($value['hub'] ?? null, true),
            self::bool($value['notify'] ?? null, true),
            self::bool($value['calendar'] ?? null, true),
        );
    }

    /** Hub / calendar overlay / in-app notify — all require enabled. */
    public function allows(string $surface): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return match ($surface) {
            'hub'      => $this->hub,
            'calendar' => $this->calendar,
            'notify'   => $this->notify,
            default    => false,
        };
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'enabled'      => $this->enabled,
            'peer_days'    => $this->peerDays,
            'leader_days'  => $this->leaderDays,
            'show_peers'   => $this->showPeers,
            'show_leaders' => $this->showLeaders,
            'hub'          => $this->hub,
            'notify'       => $this->notify,
            'calendar'     => $this->calendar,
        ];
    }

    private static function bool(mixed $v, bool $default): bool
    {
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (int) $v === 1;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
        }

        return $default;
    }

    private static function days(mixed $v, int $default): int
    {
        if (! is_numeric($v)) {
            return $default;
        }
        $n = (int) $v;

        return max(1, min(366, $n));
    }
}
