<?php

declare(strict_types=1);

/**
 * Edge-cache header CONTRACT test.
 *
 * Booting the full CI4 controller offline is heavy (Controller base, negotiator,
 * AccessControl services), so this asserts the header contract at the source level:
 * the exact Cache-Control / ETag / Vary / Surrogate-Key directives documented in
 * docs/DYNAMIC-MENU-EDGE.md must be present in MenuController. This catches the
 * common regression (someone weakens a directive and silently breaks edge caching)
 * without needing a live HTTP stack.
 */

$ctrl = file_get_contents(__DIR__ . '/../../Controllers/MenuController.php');

$p = 0; $f = 0;
function chk(string $n, bool $c): void { global $p, $f; echo ($c ? 'PASS' : 'FAIL') . " $n\n"; $c ? $p++ : $f++; }

chk('controller source loaded', is_string($ctrl) && $ctrl !== '');

// -- Per-user menu response: private + revalidatable --
chk('per-user Cache-Control is private+must-revalidate',
    str_contains($ctrl, "'Cache-Control', 'private, max-age=0, must-revalidate'"));
chk('per-user Vary includes X-Menu-Word (no cross-serve)',
    (bool) preg_match("/'Vary',\s*'[^']*X-Menu-Word[^']*'/", $ctrl));
chk('per-user sets an ETag', str_contains($ctrl, "->setHeader('ETag', \$etag)"));
chk('per-user Surrogate-Key scopes user + org',
    str_contains($ctrl, "'Surrogate-Key', 'menu-user-' . \$subjectId . ' menu-org-' . \$orgId"));

// -- Authority bundle: shareable at the edge --
chk('authority Cache-Control is public + s-maxage + SWR',
    str_contains($ctrl, "public, max-age=60, s-maxage=86400, stale-while-revalidate=86400"));
chk('authority Surrogate-Key tags authority + org',
    str_contains($ctrl, "'Surrogate-Key', 'menu-authority org-' . \$orgId"));

// -- Signed word handoff --
chk('signed word returned in X-Menu-Word response header',
    str_contains($ctrl, "->setHeader('X-Menu-Word', \$signed)"));
chk('signed word accepted from request (header or ?word=)',
    str_contains($ctrl, "getHeaderLine('X-Menu-Word')") && str_contains($ctrl, "getGet('word')"));
chk('fast path verifies signed word before deriving',
    strpos($ctrl, '->verify(') !== false
    && strpos($ctrl, '->verify(') < strpos($ctrl, 'roleCodesForSubject'));

// -- 304 path present (Tier 0) --
chk('Tier-0 304 short-circuit present',
    str_contains($ctrl, '->setStatusCode(304)->setBody(\'\')'));

// -- Both endpoints wired --
chk('index() + authority() exist',
    (bool) preg_match('/function index\(/', $ctrl) && (bool) preg_match('/function authority\(/', $ctrl));

// -- Zero-burden guard: the menu MUST NOT touch CI4 session (a DB read under
//    DatabaseHandler) — scope comes from ?scope= or a plain cookie. --
chk('controller does NOT call session() (stays stateless/edge-cacheable)',
    ! (bool) preg_match('/\bsession\s*\(/', $ctrl));
chk('active scope read from a plain cookie', str_contains($ctrl, "getCookie('wbs_menu_scope')"));

echo "\n== $p passed, $f failed ==\n";
exit($f > 0 ? 1 : 0);
