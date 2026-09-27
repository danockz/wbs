<?php

declare(strict_types=1);

namespace WBS\Referrals\Support;

/**
 * The decision catalog and the REQUIRED standalone decisions of the
 * integration lifecycle (FR-REF-3b, from the SRS onboarding requirement):
 *
 *   1. salvation           — the decision for Christ (`salvation`).
 *   2. water_baptism       — water baptism (`water_baptism`); stands alone.
 *   3. holy_spirit_baptism — Holy Spirit baptism (`holy_spirit_baptism`);
 *                            stands alone (the two baptisms are NOT bundled).
 *   4. foundation_course   — enrolling in the foundation/membership course
 *                            (`foundation_course`; may be derived from a real
 *                            enrolment rather than hand-recorded).
 *
 * Each required decision maps 1:1 to a recorded type and is satisfied only by
 * ITS OWN confirmed, dated row — one baptism never covers the other.
 *
 * `rededication` and `join_group` remain recordable decision types (append-only
 * history) but are NOT required: a rededication is a re-affirmation of someone
 * already saved, and belonging is the natural consequence of the placement rule
 * (FR-REF-6), tracked via `join_group`'s own membership side effects.
 *
 * Pure and DB-free so the support layer and the fake-DB service tests share one
 * source of truth.
 */
final class IntegrationDecision
{
    /** Every decision type that may be recorded (the seeded, org-configurable set). */
    public const TYPES = [
        'salvation',
        'rededication',
        'water_baptism',
        'holy_spirit_baptism',
        'foundation_course',
        'join_group',
    ];

    /** The required decisions, each standalone, in lifecycle order. */
    public const GROUPS = [
        'salvation',
        'water_baptism',
        'holy_spirit_baptism',
        'foundation_course',
    ];

    /** The two baptism types — each is its own required group (never bundled). */
    public const BAPTISM_TYPES = [
        'water_baptism',
        'holy_spirit_baptism',
    ];

    /** Which recorded types satisfy which required decision (1:1 — they stand alone). */
    public const GROUP_TYPES = [
        'salvation'           => ['salvation'],
        'water_baptism'       => ['water_baptism'],
        'holy_spirit_baptism' => ['holy_spirit_baptism'],
        'foundation_course'   => ['foundation_course'],
    ];

    /** Every type that participates in a required decision (deduplicated). */
    public const REQUIRED_TYPES = [
        'salvation',
        'water_baptism',
        'holy_spirit_baptism',
        'foundation_course',
    ];

    /** The group a recorded type belongs to, or null when it is history-only. */
    public static function groupFor(string $type): ?string
    {
        foreach (self::GROUP_TYPES as $group => $types) {
            if (in_array($type, $types, true)) {
                return $group;
            }
        }

        return null;
    }

    public static function isRequiredType(string $type): bool
    {
        return in_array($type, self::REQUIRED_TYPES, true);
    }

    public static function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** @return list<string> the recorded types that satisfy a required decision. */
    public static function typesSatisfying(string $group): array
    {
        return self::GROUP_TYPES[$group] ?? [];
    }

    public static function groupLabelKey(string $group): string
    {
        return 'Referrals.integration.decision.' . $group;
    }

    public static function typeLabelKey(string $type): string
    {
        return 'Referrals.decision.types.' . $type;
    }

    /**
     * Fold a list of CONFIRMED decision types into the required-decisions
     * verdict. Every required decision stands alone: it is satisfied only by
     * its own type being present.
     *
     * @param list<string> $confirmedTypes
     * @param array<string,mixed> $cfg IntegrationConfig::normalize() output
     *
     * @return array{satisfied:list<string>,outstanding:list<string>,integrated:bool}
     */
    public static function integrationOf(array $confirmedTypes, array $cfg): array
    {
        $required = array_values(array_filter(
            $cfg['required_groups'] ?? self::GROUPS,
            static fn ($g) => is_string($g) && in_array($g, self::GROUPS, true),
        ));

        $satisfied   = [];
        $outstanding = [];
        foreach ($required as $group) {
            $types       = self::GROUP_TYPES[$group];
            $isSatisfied = array_intersect($types, $confirmedTypes) !== [];
            if ($isSatisfied) {
                $satisfied[] = $group;
            } else {
                $outstanding[] = $group;
            }
        }

        return [
            'satisfied'   => $satisfied,
            'outstanding' => $outstanding,
            'integrated'  => $outstanding === [],
        ];
    }
}
