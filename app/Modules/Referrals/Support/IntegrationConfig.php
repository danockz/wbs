<?php

declare(strict_types=1);

namespace WBS\Referrals\Support;

/**
 * Hierarchical group config for the integration-decisions feature
 * (capability `referrals.integration_decisions`), resolved through
 * {@see \WBS\Admin\Services\EffectiveConfigResolver} exactly like the prospect
 * transfer policy — and **default OFF** like every gated capability.
 *
 * Fail-closed in every direction: no value / unparseable / a throwing resolver
 * all yield the OFF shape, so a body that has not explicitly switched the
 * feature on gets none of its behaviour (no journey gate, no self-declaration
 * capture, no derived rows).
 */
final class IntegrationConfig
{
    public const CAPABILITY = 'referrals.integration_decisions';

    /** @var array<string,mixed> the effective shape after normalize(). */
    public const DEFAULTS = [
        'enabled'                             => false,
        'required_groups'                     => IntegrationDecision::GROUPS,
        'foundation_course_categories'        => ['foundation', 'membership'],
        'allow_self_declaration'              => true,
        'self_declaration_requires_confirmation' => true,
        'derive_from_enrolment'               => true,
        'derive_from_completion'              => false,
        'gate_journey_advance'                => true,
        'gate_stages'                         => ['in_foundation', 'established'],
        // Which decision types are offered as OPTIONAL inputs on the
        // onboarding capture forms (member/staff add-contact, staff bulk,
        // event-guest register, cloaked-link landing). Empty = no inputs are
        // rendered or accepted on capture paths (fail-closed). This does NOT
        // restrict the member's own /my/integration self-declaration page —
        // that flow stays governed by allow_self_declaration alone.
        'capture_inputs'                      => [],
    ];

    /**
     * Parse any accepted config shape into the effective one. OFF when anything
     * is missing, malformed or not explicitly enabled.
     *
     * @return array<string,mixed>
     */
    public static function normalize(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (! is_array($value)) {
            return self::DEFAULTS;
        }

        // Explicitly enabled — a bare truthy is never accepted; the body must
        // say enabled:true (or derive it from a non-empty required_groups list).
        $enabled = array_key_exists('enabled', $value) ? (bool) $value['enabled'] : false;

        if (! $enabled) {
            return self::DEFAULTS;
        }

        $cfg = self::DEFAULTS;
        $cfg['enabled'] = true;

        $groups = $value['required_groups'] ?? self::DEFAULTS['required_groups'];
        if (is_array($groups)) {
            // Legacy shape: the old bundled `baptism` group expands to BOTH
            // standalone baptisms (each now required on its own).
            $flat = [];
            foreach ($groups as $g) {
                $g = (string) $g;
                if ($g === 'baptism') {
                    $flat[] = 'water_baptism';
                    $flat[] = 'holy_spirit_baptism';
                } else {
                    $flat[] = $g;
                }
            }
            $groups = array_values(array_unique(array_filter(
                $flat,
                static fn ($g) => in_array($g, IntegrationDecision::GROUPS, true),
            )));
            if ($groups !== []) {
                $cfg['required_groups'] = $groups;
            }
        }

        $cats = $value['foundation_course_categories'] ?? self::DEFAULTS['foundation_course_categories'];
        if (is_array($cats)) {
            $cats = array_values(array_filter(array_map(
                static fn ($c) => mb_strtolower(trim((string) $c)),
                $cats,
            )));
            if ($cats !== []) {
                $cfg['foundation_course_categories'] = $cats;
            }
        }

        foreach ([
            'allow_self_declaration',
            'self_declaration_requires_confirmation',
            'derive_from_enrolment',
            'derive_from_completion',
            'gate_journey_advance',
        ] as $flag) {
            if (array_key_exists($flag, $value)) {
                $cfg[$flag] = (bool) $value[$flag];
            }
        }

        $stages = $value['gate_stages'] ?? self::DEFAULTS['gate_stages'];
        if (is_array($stages)) {
            $stages = array_values(array_filter(array_map('strval', $stages)));
            if ($stages !== []) {
                $cfg['gate_stages'] = $stages;
            }
        }

        // Optional capture-form inputs (onboarding field-sync decision): a
        // list of decision types intersected with the catalog. An explicit
        // empty list clears the offer (nothing shows); absent -> no inputs.
        $inputs = $value['capture_inputs'] ?? self::DEFAULTS['capture_inputs'];
        if (is_array($inputs)) {
            $cfg['capture_inputs'] = array_values(array_unique(array_filter(
                array_map('strval', $inputs),
                static fn (string $t): bool => in_array($t, IntegrationDecision::TYPES, true),
            )));
        }

        return $cfg;
    }

    /**
     * Which decision types this group offers as optional CAPTURE-FORM inputs.
     * Empty unless the feature is explicitly enabled AND types are listed.
     *
     * @return list<string>
     */
    public static function captureInputsOf(array $cfg): array
    {
        if (! ($cfg['enabled'] ?? false)) {
            return [];
        }
        $inputs = $cfg['capture_inputs'] ?? [];

        return is_array($inputs) ? array_values($inputs) : [];
    }

    /** Is this course category a foundation course under this config? */
    public static function isFoundationCategory(array $cfg, string $category): bool
    {
        $needle = mb_strtolower(trim($category));

        return $needle !== '' && in_array($needle, $cfg['foundation_course_categories'], true);
    }

    /** Is advancing INTO this stage gated on integration under this config? */
    public static function isGatedStage(array $cfg, string $stageCode): bool
    {
        return in_array($stageCode, $cfg['gate_stages'], true);
    }
}
