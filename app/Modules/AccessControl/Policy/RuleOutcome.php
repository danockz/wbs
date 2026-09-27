<?php

declare(strict_types=1);

namespace WBS\AccessControl\Policy;

/**
 * The result of evaluating a facet's rules against a context (RuBAC engine).
 *
 * It is intentionally facet-agnostic so the SAME engine can drive access
 * decisions (effect allow|deny) and other facets like membership or gamification
 * (effects such as flag|adjust with effect_params). Callers interpret `effect`
 * and `matched` for their facet; the access PDP only cares about allow/deny.
 */
final class RuleOutcome
{
    /**
     * @param list<array<string,mixed>> $matched rules that fired (each: code, effect, priority, effect_params)
     */
    public function __construct(
        public readonly ?string $effect,
        public readonly array $matched = [],
        public readonly ?string $decidingCode = null,
    ) {
    }

    public static function none(): self
    {
        return new self(null, [], null);
    }

    public function denied(): bool
    {
        return $this->effect === 'deny';
    }

    public function allowed(): bool
    {
        return $this->effect === 'allow';
    }

    /** All effect_params of matched rules with a given effect (e.g. every 'adjust'). */
    public function paramsFor(string $effect): array
    {
        $out = [];
        foreach ($this->matched as $m) {
            if (($m['effect'] ?? null) === $effect && isset($m['effect_params'])) {
                $out[] = $m['effect_params'];
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'effect'        => $this->effect,
            'deciding_code' => $this->decidingCode,
            'matched'       => $this->matched,
        ];
    }
}
