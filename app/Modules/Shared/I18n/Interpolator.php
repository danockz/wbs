<?php

declare(strict_types=1);

namespace WBS\Shared\I18n;

/**
 * Placeholder interpolation shared by every translation source.
 *
 * The platform historically standardised on POSITIONAL {0}/{1} tokens (all the
 * file-based module catalogs use them), while DB-authored content and the
 * attached Translator convention use NAMED :key tokens. To merge translations
 * from all sources into one frontend-ready catalog we must understand BOTH, so
 * this helper applies whichever style(s) a given template contains:
 *
 *   "Season {0} · by {1}"          + [12, 'points']            => "Season 12 · by points"
 *   "Hi :name, welcome to :org"    + ['name'=>'Ama','org'=>'X'] => "Hi Ama, welcome to X"
 *   "{{event.title}} at :venue"     + ['event.title'=>'Camp', 'venue'=>'Hall']
 *
 * Rules kept deliberately simple and allocation-light (this runs per rendered
 * string): positional keys are matched by integer index into $params; named
 * keys are matched by exact name. A token whose value is absent is left LITERAL
 * so a partially-populated context never produces "null" holes or leaks the raw
 * key style to end users. No regex is compiled unless the corresponding token
 * style is actually present.
 */
final class Interpolator
{
    /**
     * @param array<int|string,scalar|null> $params positional (0,1,…) and/or
     *                                              named (:key, {{ns.field}})
     */
    public static function apply(string $template, array $params): string
    {
        if ($params === [] || $template === '') {
            return $template;
        }

        // 1) Positional {0}, {1}, … — only when braces are present.
        if (str_contains($template, '{')) {
            $template = (string) preg_replace_callback(
                '/\{(\d+)\}/',
                static function (array $m) use ($params): string {
                    $i = (int) $m[1];

                    return array_key_exists($i, $params) ? (string) $params[$i] : $m[0];
                },
                $template,
            );

            // 1b) Curly NAMED / dotted tokens {{ns.field}} or {name} — content
            // authored in templates. Left literal when no matching param exists.
            if (str_contains($template, '{')) {
                $template = (string) preg_replace_callback(
                    '/\{\{?\s*([a-zA-Z_][a-zA-Z0-9_.]*)\s*\}?\}/',
                    static function (array $m) use ($params): string {
                        $name = $m[1];

                        return array_key_exists($name, $params) ? (string) $params[$name] : $m[0];
                    },
                    $template,
                );
            }
        }

        // 2) Named :key tokens (attached Translator convention). Replace longest
        //    names first so ":user_id" is not clobbered by ":user".
        if (str_contains($template, ':')) {
            $named = [];
            foreach ($params as $k => $v) {
                if (! is_int($k)) {
                    $named[(string) $k] = (string) $v;
                }
            }
            if ($named !== []) {
                uksort($named, static fn ($a, $b) => strlen($b) <=> strlen($a));
                foreach ($named as $k => $v) {
                    // Only treat as a token when it looks like an identifier.
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $k)) {
                        $template = str_replace(':' . $k, $v, $template);
                    }
                }
            }
        }

        return $template;
    }
}
