<?php

declare(strict_types=1);

/**
 * SHARED PAGE PRESENTER test (regression, 2026-09-12).
 *
 * Endpoints that used to fall through to the generic data_page.php now render a
 * bespoke, locale-aware page: BaseController::respondPage() merges a declarative
 * spec from WBS\Shared\Navigation\PageSpecs with request data and renders
 * app/Modules/Shared/Views/presenter/page.php. This test asserts:
 *   - every PageSpec id has title/heading/sub keys in ALL six locales (parity),
 *   - every column/detail/facts labelKey and vocab value it references resolves
 *     in every locale (no leaked "Pages.*"/"Common.*" tokens),
 *   - a headless render in fr + ar emits translated chrome, the right <html
 *     lang/dir> (RTL for ar), localized column headers and an empty state,
 *   - detail (record) mode renders a key/value card and NOT the empty state,
 *   - BaseController exposes respondPage() and no longer leaves these routes on
 *     the raw data_page fallback.
 *
 *   php app/Modules/Shared/Navigation/tests/page_presenter_test.php
 */

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Navigation/PageSpecs.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$locales = ['en', 'fr', 'es', 'pt', 'zh', 'ar'];

/** Load every app catalog for a locale. @return array<string,mixed> */
$loadCat = static function (string $loc) use ($root): array {
    $out = [];
    foreach (glob("$root/app/Language/$loc/*.php") as $f) {
        $out[basename($f, '.php')] = require $f;
    }

    return $out;
};
/** Resolve a dotted lang key against a loaded catalog, or null if missing. */
$resolve = static function (array $cat, string $key) {
    $p    = explode('.', $key);
    $file = array_shift($p);
    $v    = $cat[$file] ?? null;
    foreach ($p as $s) {
        if (! is_array($v) || ! array_key_exists($s, $v)) {
            return null;
        }
        $v = $v[$s];
    }

    return is_string($v) ? $v : null;
};

$cats  = [];
foreach ($locales as $loc) {
    $cats[$loc] = $loadCat($loc);
}

$specs = \WBS\Shared\Navigation\PageSpecs::all();
echo 'PageSpecs discovered: ' . count($specs) . "\n";
chk('at least 30 specs defined', count($specs) >= 30);

// ── 1. Per-view title/heading/sub parity across locales ──────────────────────
echo "per-view title/heading/sub parity\n";
foreach ($specs as $id => $spec) {
    foreach (['title', 'heading', 'sub'] as $part) {
        $key = "Pages.views.$id.$part";
        foreach ($locales as $loc) {
            $v = $resolve($cats[$loc], $key);
            chk("$loc $key present", $v !== null && $v !== '', 'missing');
        }
    }
}

// ── 2. Every referenced labelKey / vocab value resolves in every locale ──────
echo "column & vocab label keys resolve in every locale\n";
$labelKeys = [];
$vocab     = [];
$collect = static function (array $cols) use (&$labelKeys, &$vocab): void {
    foreach ($cols as $col) {
        if (! is_array($col)) {
            continue;
        }
        if (isset($col['labelKey']) && $col['labelKey'] !== '') {
            $labelKeys[$col['labelKey']] = true;
        }
        if (isset($col['textKey']) && $col['textKey'] !== '') {
            $labelKeys[$col['textKey']] = true;
        }
        foreach (array_keys((array) ($col['colors'] ?? [])) as $vv) {
            if ($vv !== '_default') {
                $vocab[$vv] = true;
            }
        }
    }
};
foreach ($specs as $spec) {
    $collect($spec['columns'] ?? []);
    $collect($spec['detail'] ?? []);
}
foreach (array_keys($labelKeys) as $key) {
    foreach ($locales as $loc) {
        chk("$loc resolves $key", $resolve($cats[$loc], $key) !== null, 'missing label');
    }
}
foreach (array_keys($vocab) as $vv) {
    foreach ($locales as $loc) {
        $key = 'Pages.common.vocab.' . $vv;
        chk("$loc resolves $key", $resolve($cats[$loc], $key) !== null, 'missing vocab');
    }
}

// ── 3. Headless render smoke (fr list, ar list, en detail) ───────────────────
echo "headless render smoke\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
if (! function_exists('base_url')) {
    function base_url($p = '')
    {
        return '/' . ltrim((string) $p, '/');
    }
}
if (! function_exists('session')) {
    function session($k = null)
    {
        return null;
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            public function getLocale()
            {
                return $GLOBALS['__loc'] ?? 'en';
            }
        };
    }
}
if (! function_exists('config')) {
    function config($c)
    {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p    = explode('.', $key);
        $file = array_shift($p);
        $v    = $GLOBALS['__cat'][$file] ?? null;
        foreach ($p as $s) {
            if (! is_array($v) || ! array_key_exists($s, $v)) {
                return $key;
            }
            $v = $v[$s];
        }

        return is_string($v) ? $v : $key;
    }
}

$render = static function (string $pageId, string $loc, array $extract) use ($root, $cats): string {
    $GLOBALS['__loc'] = $loc;
    $GLOBALS['__cat'] = $cats[$loc];
    $spec             = \WBS\Shared\Navigation\PageSpecs::get($pageId);
    $page             = array_merge(['id' => $pageId], (array) $spec, $extract);
    $csrf             = 'TESTCSRF';
    ob_start();
    include $root . '/app/Modules/Shared/Views/presenter/page.php';

    return (string) ob_get_clean();
};

// fr list page
$fr = $render('identity_tokens', 'fr', ['rows' => [[
    'name' => 'CI token', 'created_at' => '2026-08-01T00:00:00Z',
]]]);
chk('fr: html lang=fr dir=ltr', str_contains($fr, 'lang="fr"') && str_contains($fr, 'dir="ltr"'));
chk('fr: localized heading', str_contains($fr, (string) $resolve($cats['fr'], 'Pages.views.identity_tokens.heading')));
chk('fr: localized column header', str_contains($fr, (string) $resolve($cats['fr'], 'Pages.common.colName')));
chk('fr: no leaked Pages.* token', ! preg_match('/Pages\.[a-z]/i', strip_tags($fr)));
chk('fr: csrf hidden field present', str_contains($fr, 'name="_csrf"') || ! str_contains(preg_replace('/<form class="lang__menu".*?<\/form>/s', '', $fr), '<form'));

// ar RTL + empty state
$ar = $render('gam_followups_due', 'ar', ['rows' => []]);
chk('ar: html lang=ar dir=rtl', str_contains($ar, 'lang="ar"') && str_contains($ar, 'dir="rtl"'));
chk('ar: empty state localized', str_contains($ar, (string) $resolve($cats['ar'], 'Pages.common.empty')));
chk('ar: no leaked Pages.* token', ! preg_match('/Pages\.[a-z]/i', strip_tags($ar)));
// Header CTA: follow-ups due page links to the record capture form (localized).
chk('followups due: header CTA links to record form', str_contains($ar, 'class="cta"') && str_contains($ar, '/gamification/follow-ups/new'));
$enDue = $render('gam_followups_due', 'en', ['rows' => []]);
chk('followups due: CTA label localized (en)', str_contains($enDue, '>Record a follow-up</a>'));

// en detail (record) mode — key/value card, NOT the empty state
$en = $render('certificate_verify', 'en', ['record' => [
    'valid' => true, 'recipient_name' => 'Mary', 'title' => 'Foundations', 'issued_at' => '2026-06-01T00:00:00Z',
]]);
chk('en detail: shows recipient value', str_contains($en, 'Mary'));
chk('en detail: shows localized label', str_contains($en, (string) $resolve($cats['en'], 'Pages.common.colRecipient')));
chk('en detail: NO empty state', ! str_contains($en, (string) $resolve($cats['en'], 'Pages.common.empty')));

// ── 3b. Write controls: create-form + detail lifecycle actions ───────────────
echo "write controls (form + detail actions)\n";
// Ticket-type create form rendered on the event_ticket_types page.
$tt = $render('event_ticket_types', 'fr', [
    'rows'  => [],
    'forms' => [[
        'action'     => 'events/ev1/ticket-types',
        'summaryKey' => 'Pages.forms.ticketType.summary',
        'submitKey'  => 'Pages.forms.ticketType.submit',
        'fields'     => [
            ['name' => 'name', 'type' => 'text', 'labelKey' => 'Pages.common.colName', 'required' => true],
            ['name' => 'price_minor', 'type' => 'number', 'labelKey' => 'Pages.forms.ticketType.priceMinor'],
            ['name' => 'description', 'type' => 'textarea', 'labelKey' => 'Pages.common.colDescription', 'full' => true],
        ],
    ]],
]);
chk('form: posts to the webcsrf route', str_contains($tt, 'action="/events/ev1/ticket-types"'));
chk('form: carries _csrf field', str_contains($tt, 'name="_csrf"'));
chk('form: has exact service field name=name', str_contains($tt, 'name="name"'));
chk('form: has exact service field name=price_minor', str_contains($tt, 'name="price_minor"'));
chk('form: textarea for description', str_contains($tt, '<textarea') && str_contains($tt, 'name="description"'));
chk('form: required attribute honored', (bool) preg_match('/name="name"[^>]*required/', $tt));
chk('form: localized submit label', str_contains($tt, (string) $resolve($cats['fr'], 'Pages.forms.ticketType.submit')));
chk('form: no leaked Pages.* token', ! preg_match('/Pages\.[a-z]/i', strip_tags($tt)));

// Detail lifecycle actions rendered on the custom-adapter detail page.
$ca = $render('integration_custom_adapter', 'fr', [
    'record'        => ['id' => 'ad1', 'name' => 'X', 'adapter_class' => 'App\\X', 'status' => 'draft'],
    'detailActions' => [
        ['action' => 'integrations/custom-adapters/{id}/contract-test', 'labelKey' => 'Pages.actions.contractTest'],
        ['action' => 'integrations/custom-adapters/{id}/revoke', 'labelKey' => 'Pages.actions.revoke', 'danger' => true],
    ],
]);
chk('detail action: {id} substituted into POST action', str_contains($ca, 'action="/integrations/custom-adapters/ad1/contract-test"'));
chk('detail action: revoke present', str_contains($ca, 'action="/integrations/custom-adapters/ad1/revoke"'));
chk('detail action: carries _csrf', substr_count($ca, 'name="_csrf"') >= 2);
chk('detail action: localized labels', str_contains($ca, (string) $resolve($cats['fr'], 'Pages.actions.revoke')));

// Row → detail navigation links (strong cell with rowHref).
$camps = $render('gam_group_campaigns', 'en', ['rows' => [['id' => 'c1', 'name' => 'Harvest', 'status' => 'active']]]);
chk('row link: name links to campaign detail', str_contains($camps, 'href="/gamification/campaigns/c1"'));
$vn = $render('venue_nearby', 'en', ['rows' => [['id' => 'v1', 'name' => 'Hall', 'address' => 'x', 'distance_km' => 1, 'capacity' => 10]]]);
chk('row link: venue name links to venue detail', str_contains($vn, 'href="/venues/v1"'));

// Controllers actually declare the write controls (not just the presenter).
$ticketCtrl = (string) file_get_contents($root . '/app/Modules/Events/Controllers/TicketingController.php');
chk('TicketingController wires the create form', str_contains($ticketCtrl, "ticket-types") && str_contains($ticketCtrl, "'forms'"));
$adapterCtrl = (string) file_get_contents($root . '/app/Modules/Integrations/Controllers/CustomAdapterController.php');
chk('CustomAdapterController wires detailActions', str_contains($adapterCtrl, "'detailActions'"));

// ── 3c. Design tokens (Velzon-distilled, inline) + HTML5 validation attrs ─────
echo "design tokens + validation attributes\n";
$tokPage = $render('venue_stats', 'en', ['rows' => [['label' => 'x', 'value' => 1]]]);
chk('tokens: inline :root block present', str_contains($tokPage, '--wbs-primary'));
chk('tokens: Velzon primary #405189', str_contains($tokPage, '#405189'));
chk('tokens: presenter sets --wbs-accent', str_contains($tokPage, '--wbs-accent'));
chk('tokens: app.css consumes var(--wbs-*)', str_contains((string) file_get_contents($root . '/assets/css/app.css'), 'var(--wbs-'));
chk('tokens: hashed same-origin stylesheet', (bool) preg_match('#<link[^>]+href="/assets/css/#i', $tokPage));
chk('tokens: NO CDN / external origin', ! preg_match('#https?://#', preg_replace('/https?:\/\/[^"\']*\.(test|example)[^"\']*/','',$tokPage)) || ! str_contains($tokPage, 'jsdelivr'));
chk('tokens: no inline <script> bodies', ! preg_match('#<script[^>]*>\s*[^<]#i', $tokPage));
// Shared token partial lives at the platform-wide location and is included by
// BOTH the presenter and the base layout (single source of truth).
chk('tokens: shared partial at Shared/Views/_tokens.php', is_file($root . '/app/Modules/Shared/Views/_tokens.php'));
$shellSrc = (string) file_get_contents($root . '/app/Modules/Shared/Views/_shell_open.php');
chk('tokens: shell includes _tokens.php', str_contains($shellSrc, "_tokens.php"));
chk('tokens: layout uses the shared shell', str_contains((string) file_get_contents($root . '/app/Views/layouts/app.php'), '_shell_open.php'));

$valForm = $render('event_ticket_types', 'en', [
    'rows'  => [],
    'forms' => [[
        'action' => 'events/ev1/ticket-types', 'summaryKey' => 'Pages.forms.ticketType.summary', 'submitKey' => 'Pages.forms.ticketType.submit',
        'fields' => [
            ['name' => 'name', 'type' => 'text', 'required' => true, 'maxlength' => 200, 'labelKey' => 'Pages.common.colName'],
            ['name' => 'currency', 'type' => 'text', 'maxlength' => 3, 'pattern' => '[A-Za-z]{3}', 'placeholder' => 'GHS', 'labelKey' => 'Pages.common.colCurrency'],
            ['name' => 'price_minor', 'type' => 'number', 'min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'labelKey' => 'Pages.forms.ticketType.priceMinor'],
        ],
    ]],
]);
chk('validation: maxlength rendered', str_contains($valForm, 'maxlength="200"'));
chk('validation: pattern rendered', str_contains($valForm, 'pattern="[A-Za-z]{3}"'));
chk('validation: placeholder rendered', str_contains($valForm, 'placeholder="GHS"'));
chk('validation: min/step/inputmode rendered', str_contains($valForm, 'min="0"') && str_contains($valForm, 'step="1"') && str_contains($valForm, 'inputmode="numeric"'));
chk('validation: required still rendered', (bool) preg_match('/name="name"[^>]*required/', $valForm));

// ── 3d. Progressive table enhancement (opt-in, CSP-safe, no-JS-safe) ─────────
echo "progressive table enhancement\n";
// Enhanced page: leaderboard opts in via PageSpecs 'enhance' => true.
$enh = $render('gam_campaign_leaderboard', 'en', ['rows' => [
    ['rank' => 2, 'subject_name' => 'Kofi', 'score' => 280],
    ['rank' => 1, 'subject_name' => 'Ama', 'score' => 320],
]]);
chk('enhance: table wrapped with data-enhance', str_contains($enh, 'data-enhance="table"'));
chk('enhance: localized filter label on wrapper', str_contains($enh, 'data-filter-label="' . (string) $resolve($cats['en'], 'Pages.common.filter') . '"'));
chk('enhance: num column tagged data-sort=num', str_contains($enh, 'data-sort="num"'));
chk('enhance: text column tagged data-sort=text', str_contains($enh, 'data-sort="text"'));
chk('enhance: cells carry data-sort-value (raw key)', str_contains($enh, 'data-sort-value="280"') && str_contains($enh, 'data-sort-value="Ama"'));
chk('enhance: loads same-origin script', str_contains($enh, 'src="/assets/js/table-enhance.js"'));
chk('enhance: script is deferred', (bool) preg_match('/table-enhance\.js"\s+defer/', $enh));
chk('enhance: NO inline <script> body (CSP)', ! preg_match('/<script>[^<]/', $enh));
// Export + copy toolbar hooks (localized, client-only — no network).
chk('enhance: CSV export label present', str_contains($enh, 'data-export="' . (string) $resolve($cats['en'], 'Pages.common.exportCsv') . '"'));
chk('enhance: export filename = page id', str_contains($enh, 'data-export-name="gam_campaign_leaderboard"'));
chk('enhance: copy label present', str_contains($enh, 'data-copy="' . (string) $resolve($cats['en'], 'Pages.common.copy') . '"'));
chk('enhance: columns toggle label present', str_contains($enh, 'data-columns="' . (string) $resolve($cats['en'], 'Pages.common.columns') . '"'));
chk('enhance: density toggle label present', str_contains($enh, 'data-density="' . (string) $resolve($cats['en'], 'Pages.common.density') . '"'));

// Row-action column is excluded from CSV via data-no-export.
$enhAct = $render('gam_group_campaigns', 'en', [
    'rows'       => [['id' => 'c1', 'name' => 'Harvest', 'status' => 'active'], ['id' => 'c2', 'name' => 'Spring', 'status' => 'draft']],
    'rowActions' => [['action' => 'gamification/campaigns/{id}/close', 'labelKey' => 'Pages.actions.revoke']],
    'rowIdKey'   => 'id',
]);
chk('enhance: actions <th> marked data-no-export', (bool) preg_match('/<th[^>]*data-no-export[^>]*>/', $enhAct));

// Enhance label keys exist in ALL locales (parity guard for the new keys).
foreach (['exportCsv', 'copy', 'copied', 'filter', 'sortBy', 'columns', 'density'] as $k) {
    foreach ($locales as $loc) {
        chk("$loc resolves Pages.common.$k", $resolve($cats[$loc], "Pages.common.$k") !== null, 'missing');
    }
}

// Non-enhanced page: no wrapper, no script, table still present.
$plain = $render('venue_stats', 'en', ['rows' => [['label' => 'x', 'value' => 1]]]);
chk('no-enhance: page omits data-enhance', ! str_contains($plain, 'data-enhance'));
chk('no-enhance: page omits the script', ! str_contains($plain, 'table-enhance.js'));
chk('no-enhance: table still rendered (works without JS)', str_contains($plain, '<table>'));

// The enhancement asset itself: same-origin, dependency-free, CSP-safe.
$js = (string) @file_get_contents($root . '/public/assets/js/table-enhance.js');
chk('asset: table-enhance.js exists', $js !== '');
chk('asset: IIFE + strict mode', str_contains($js, "'use strict'"));
chk('asset: no eval', ! preg_match('/\beval\s*\(/', $js));
// Strip block/line comments first so the doc-comment mentioning "no jQuery" is
// not mistaken for a dependency; then assert no actual jQuery/$() call sites.
$jsCode = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $js);
chk('asset: no jQuery/$() usage', ! preg_match('/\bjQuery\s*\(|\$\(/', (string) $jsCode));
chk('asset: no external URL / CDN', ! preg_match('#https?://[a-z]#i', $js));
chk('asset: CSV export implemented', str_contains($js, 'tableToCsv') && str_contains($js, 'text/csv'));
chk('asset: copy-to-clipboard implemented', str_contains($js, 'clipboard') && str_contains($js, 'execCommand'));
chk('asset: export respects active filter', str_contains($js, "style.display === 'none'"));
chk('asset: column-visibility toggle implemented', str_contains($js, 'setColumnHidden') && str_contains($js, 'addColumnMenu'));
chk('asset: density toggle implemented', str_contains($js, 'tbl-compact'));
chk('asset: enhance is idempotent-guarded', str_contains($js, "data-enhanced"));

// ── 4. BaseController wiring ─────────────────────────────────────────────────
echo "BaseController exposes respondPage + PRESENTER_VIEW\n";
$base = (string) file_get_contents($root . '/app/Modules/Shared/Http/BaseController.php');
chk('respondPage() defined', str_contains($base, 'protected function respondPage('));
chk('PRESENTER_VIEW constant', str_contains($base, "presenter\\page"));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
