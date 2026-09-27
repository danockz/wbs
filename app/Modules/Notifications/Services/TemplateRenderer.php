<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

/**
 * Server-side template rendering with an ALLOWLISTED placeholder syntax
 * (SRS FR-NOT-001). Only `{{namespace.field}}` tokens drawn from an explicit
 * allowlist are substituted; everything else is left literal. No code or
 * expression execution is possible, and values are HTML-escaped by default for
 * non-SMS channels.
 */
final class TemplateRenderer
{
    /** Allowlisted placeholder keys (dot notation). */
    private const ALLOWED = [
        'member.preferred_name',
        'member.first_name',
        'member.last_name',
        'event.title',
        'event.start_local',
        'event.end_local',
        'event.venue',
        'group.name',
        'course.title',
        'contribution.amount',
        'contribution.reference',
        'org.name',
        'action.url',
    ];

    /**
     * Render a template body against a context.
     *
     * @param array<string,scalar|null> $context flat dot-key => value map
     */
    public function render(string $body, array $context, bool $escape = true): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+\.[a-z0-9_]+)\s*\}\}/i',
            function (array $m) use ($context, $escape): string {
                $key = strtolower($m[1]);
                if (! in_array($key, self::ALLOWED, true)) {
                    return $m[0]; // unknown placeholder left literal (never executed)
                }
                $value = $context[$key] ?? '';
                $value = (string) $value;

                return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            },
            $body,
        );
    }

    /**
     * Validate a template body: returns the list of DISALLOWED placeholders
     * found (empty list == valid).
     *
     * @return list<string>
     */
    public function invalidPlaceholders(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+\.[a-z0-9_]+)\s*\}\}/i', $body, $matches);
        $bad = [];
        foreach ($matches[1] as $token) {
            if (! in_array(strtolower($token), self::ALLOWED, true)) {
                $bad[] = $token;
            }
        }

        return array_values(array_unique($bad));
    }

    /** Fingerprint rendered content for delivery tracking (FR-NOT-007). */
    public function fingerprint(string $rendered): string
    {
        return hash('sha256', $rendered);
    }
}
