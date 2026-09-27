<?php

declare(strict_types=1);

/**
 * Duplicate-key guard for language catalogs (DB-free, single-file scope).
 *
 * The cross-locale parity sweep (catalog_parity_test.php) compares the KEY SHAPE
 * of each locale against English. It cannot see a key that is declared twice
 * WITHIN one file, because PHP silently collapses `['catering' => 'x', … ,
 * 'catering' => [ … ]]` to the last value before the parity test ever runs.
 *
 * That exact collision shipped a production fatal (2026-09-16): `Events.php` had
 * both `'catering' => 'Catering (+10%)'` (a logistics-grid label) and a nested
 * `'catering' => [ … ]` page group. The array won, so `lang('Events.catering')`
 * returned an array and the attendance view died with "Array to string
 * conversion" — and every locale had the same latent collision.
 *
 * This test parses each catalog with PHP's tokenizer and flags any array literal
 * that declares the same string key twice at the same nesting level. It is the
 * regression lock for that whole class of bug.
 *
 *   php app/Modules/Shared/I18n/tests/no_duplicate_lang_keys_test.php
 */

$root = dirname(__DIR__, 5);

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ---- discover every catalog file -------------------------------------------
$files = [];
foreach (array_merge(
    glob($root . '/app/Language/*/*.php') ?: [],
    glob($root . '/app/Modules/*/Language/*/*.php') ?: [],
) as $f) {
    $files[] = $f;
}
sort($files);
chk('discovered language catalogs (>20)', count($files) > 20);

/**
 * Scan one file's token stream for duplicate string keys within the same array
 * literal. Returns a list of "duplicate 'key' at lines a,b" messages.
 *
 * Approach: walk tokens, tracking a stack of arrays (each `[` / `array(` opens a
 * frame). A key is any T_CONSTANT_ENCAPSED_STRING immediately followed (ignoring
 * whitespace/comments) by a `=>`. Record it on the current frame; a second use
 * of the same key name in the same frame is a duplicate. `=>` inside nested
 * arrays lives in its own frame, so enum sub-groups don't cross-contaminate.
 */
function duplicateKeys(string $path): array
{
    $src    = file_get_contents($path);
    if ($src === false) {
        return ["could not read file"];
    }
    $tokens = token_get_all($src);
    $n      = count($tokens);

    $stack   = [[]]; // one frame; index 0 is the file/top scope
    $dupes   = [];

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];

        if (is_array($t)) {
            [$id, $text, $line] = [$t[0], $t[1], $t[2]];

            // Open an array frame: `array(` (T_ARRAY then `(`).
            if ($id === T_ARRAY) {
                $stack[] = [];
                continue;
            }

            // A quoted string that is a KEY (next significant token is `=>`).
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                // peek ahead to the next significant token
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j])
                    && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                }
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
                    $key   = trim($text, "'\"");
                    $frame = array_key_last($stack);
                    if (isset($stack[$frame][$key])) {
                        $dupes[] = "duplicate '{$key}' at lines "
                            . $stack[$frame][$key] . ',' . $line;
                    } else {
                        $stack[$frame][$key] = $line;
                    }
                }
            }
            continue;
        }

        // Single-char tokens: manage frame open/close for short-array syntax.
        if ($t === '[') {
            $stack[] = [];
        } elseif ($t === ']') {
            if (count($stack) > 1) {
                array_pop($stack);
            }
        } elseif ($t === ')') {
            // Close a frame only if it was opened by array(...). We approximate by
            // popping when the frame above the top scope exists; false `)` from a
            // function call would pop a spurious empty frame, which is harmless for
            // duplicate detection (frames only accumulate keys before a `=>`).
            if (count($stack) > 1) {
                array_pop($stack);
            }
        }
    }

    return $dupes;
}

foreach ($files as $f) {
    $rel   = str_replace($root . '/', '', $f);
    $dupes = duplicateKeys($f);
    chk("no duplicate keys: {$rel}", $dupes === [], implode('; ', $dupes));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
