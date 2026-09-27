<?php

declare(strict_types=1);

/**
 * Avatar (initials fallback) unit test. Proves the resource-light member avatar:
 *   - resolves a real photo URL when present, else a self-contained data-URI;
 *   - derives up to two initials from name / email / (else '?');
 *   - is DETERMINISTIC (same seed -> same colour) and dependency-free;
 *   - escapes hostile display names so an avatar can never inject markup;
 *   - emits well-formed SVG with an aria-label and a rounded rect + centred text.
 *
 * Pure: no DB, no GD, no network, no framework boot.
 *
 *   php app/Modules/Shared/Support/tests/avatar_test.php
 */

require __DIR__ . '/../Avatar.php';

use WBS\Shared\Support\Avatar;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ---- Initials --------------------------------------------------------------
chk('two-word name -> two initials', Avatar::initialsFrom(['display_name' => 'Kwame Mensah']) === 'KM');
chk('single name -> one initial', Avatar::initialsFrom(['display_name' => 'Cher']) === 'C');
chk('email fallback', Avatar::initialsFrom(['email' => 'john@doe.com']) === 'J');
chk('empty -> question mark', Avatar::initialsFrom([]) === '?');
chk('separators split', Avatar::initialsFrom(['display_name' => 'ama.owusu']) === 'AO');
chk('unicode upper', Avatar::initialsFrom(['display_name' => 'ámbar ítalo']) === 'ÁÍ');
chk('name preferred over email', Avatar::initialsFrom(['display_name' => 'Zoe Q', 'email' => 'a@b.com']) === 'ZQ');

// ---- Seed / colour determinism --------------------------------------------
chk('seed prefers id', Avatar::seedFrom(['id' => 'u1', 'email' => 'e']) === 'u1');
chk('seed falls back to email', Avatar::seedFrom(['email' => 'e@x']) === 'e@x');
chk('colour deterministic', Avatar::colorFor('u1') === Avatar::colorFor('u1'));
chk('colour is a hex from palette', (bool) preg_match('/^#[0-9a-f]{6}$/i', Avatar::colorFor('u1')));
$distinct = Avatar::colorFor('alpha') !== Avatar::colorFor('zzzz') || Avatar::colorFor('a') !== Avatar::colorFor('b');
chk('different seeds can differ', $distinct);

// ---- resolveUrl / hasPhoto -------------------------------------------------
$withPhoto = ['id' => 'u', 'profile_photo_url' => 'https://cdn/x.png'];
chk('hasPhoto true', Avatar::hasPhoto($withPhoto) === true);
chk('hasPhoto false', Avatar::hasPhoto(['id' => 'u']) === false);
chk('resolveUrl returns real photo', Avatar::resolveUrl($withPhoto) === 'https://cdn/x.png');
chk('resolveUrl falls back to data-uri', str_starts_with(Avatar::resolveUrl(['display_name' => 'A B']), 'data:image/svg+xml;base64,'));
chk('resolveUrl blank photo -> fallback', str_starts_with(Avatar::resolveUrl(['profile_photo_url' => '   ', 'display_name' => 'A']), 'data:'));

// ---- SVG shape + safety ----------------------------------------------------
$svg = Avatar::svg('seed', 'KM', 128);
chk('svg root element', str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>'));
chk('svg has viewBox', str_contains($svg, 'viewBox="0 0 128 128"'));
chk('svg has rounded rect', str_contains($svg, '<rect') && str_contains($svg, 'rx="'));
chk('svg centres text', str_contains($svg, 'text-anchor="middle"'));
chk('svg has aria-label', str_contains($svg, 'aria-label='));
chk('svg renders initials', str_contains($svg, '>KM</text>'));

// Hostile display name must be escaped inside the SVG (no raw tag survives).
$hostileInitials = Avatar::initialsFrom(['display_name' => '<script>alert(1)</script> Boss']);
$svgH = Avatar::svg('s', $hostileInitials, 64);
chk('hostile initials sanitized', $hostileInitials === 'SB', 'got ' . $hostileInitials);
chk('no <script in svg', ! str_contains($svgH, '<script'));

// Direct hostile characters as initials are entity-escaped.
$svgLt = Avatar::svg('s', '<>', 64);
chk('angle brackets escaped in text', str_contains($svgLt, '&lt;') && ! str_contains($svgLt, '><>'));

// ---- Size clamping ---------------------------------------------------------
chk('tiny size clamped to >=16', str_contains(Avatar::svg('s', 'A', 4), 'width="16"'));
chk('huge size clamped to <=1024', str_contains(Avatar::svg('s', 'A', 9999), 'width="1024"'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
