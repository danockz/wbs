<?php

declare(strict_types=1);

/**
 * MENU LANGUAGE-AWARENESS test. Proves the universal dynamic menu is fully
 * localized and locale-cache-correct:
 *
 *   1. Every catalog item id has an App.menuItems.<id> key in EVERY locale
 *      (en + fr/es/pt/zh/ar), with no missing and no stray keys, and the English
 *      value matches the CoreMenuProvider label (single source of truth).
 *   2. MenuItem::displayLabel() resolves the localized label and falls back to the
 *      hardcoded English when the framework/key is absent (never a raw key).
 *   3. MenuItem::toArray() and both MenuBundle shapes emit the LOCALIZED label.
 *   4. Category labels localize via MenuCategory::label() in every locale.
 *   5. Locale is part of cache identity: MenuService::etag() and both
 *      MenuBundle versions change with locale (so a language switch revalidates
 *      and a shared edge never cross-serves languages).
 *
 * Pure: no DB, no framework boot. lang()/service() are stubbed to a chosen locale.
 *
 *   php app/Modules/Shared/Navigation/tests/menu_i18n_test.php
 */

require __DIR__ . '/../PermissionBits.php';
require __DIR__ . '/../MenuCategory.php';
require __DIR__ . '/../MenuItem.php';
require __DIR__ . '/../CoreMenuProvider.php';
require __DIR__ . '/../MenuCatalog.php';
require __DIR__ . '/../RoleWordTable.php';
require __DIR__ . '/../MenuBundle.php';
require __DIR__ . '/../MenuService.php';

use WBS\Shared\Navigation\CoreMenuProvider;
use WBS\Shared\Navigation\MenuBundle;
use WBS\Shared\Navigation\MenuCatalog;
use WBS\Shared\Navigation\MenuCategory;
use WBS\Shared\Navigation\MenuService;
use WBS\Shared\Navigation\RoleWordTable;

$langRoot = __DIR__ . '/../../../../Language';
$locales  = ['fr', 'es', 'pt', 'zh', 'ar'];

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ---- Global lang()/service() stubs driven by $GLOBALS['__loc'] --------------
$GLOBALS['__app'] = [];
foreach (array_merge(['en'], $locales) as $l) {
    $GLOBALS['__app'][$l] = require "{$langRoot}/{$l}/App.php";
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $loc = $GLOBALS['__loc'] ?? 'en';
        $p   = explode('.', $key);
        if (array_shift($p) !== 'App') {
            return $key;
        }
        $v = $GLOBALS['__app'][$loc] ?? [];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key; // CI4 returns the key unchanged when missing.
            }
            $v = $v[$seg];
        }
        return $v;
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

// ---- 1. key + label parity vs the catalog, across all locales --------------
echo "menuItems key parity vs catalog + all locales\n";
$catIds = [];
foreach (CoreMenuProvider::items() as $it) {
    $catIds[str_replace('.', '_', $it->id)] = $it->label;
}
$en = $GLOBALS['__app']['en']['menuItems'] ?? [];
chk('en menuItems present', $en !== []);
chk('en has a key for every catalog item', array_diff_key($catIds, $en) === [], 'missing: ' . implode(',', array_keys(array_diff_key($catIds, $en))));
chk('en has no stray menuItems key', array_diff_key($en, $catIds) === [], 'stray: ' . implode(',', array_keys(array_diff_key($en, $catIds))));
$mismatch = [];
foreach ($catIds as $k => $lbl) {
    if (($en[$k] ?? null) !== $lbl) {
        $mismatch[] = $k;
    }
}
chk('en label == provider English (single source of truth)', $mismatch === [], 'differ: ' . implode(',', $mismatch));

foreach ($locales as $loc) {
    $items = $GLOBALS['__app'][$loc]['menuItems'] ?? [];
    chk("{$loc} mirrors all en menuItems keys", array_diff_key($en, $items) === [], 'missing: ' . implode(',', array_keys(array_diff_key($en, $items))));
    chk("{$loc} has no stray menuItems keys", array_diff_key($items, $en) === [], 'stray: ' . implode(',', array_keys(array_diff_key($items, $en))));
}

// ---- 2. displayLabel() localizes + falls back --------------------------------
echo "MenuItem::displayLabel() localization + fallback\n";
$items = CoreMenuProvider::items();
$byId  = [];
foreach ($items as $it) {
    $byId[$it->id] = $it;
}
$GLOBALS['__loc'] = 'fr';
chk('fr displayLabel(overview.dashboard) localized', $byId['overview.dashboard']->displayLabel() === 'Tableau de bord');
chk('fr displayLabel(events.create) localized', $byId['events.create']->displayLabel() === 'Créer un événement');
$GLOBALS['__loc'] = 'ar';
chk('ar displayLabel(reports.leaderboard) localized', $byId['reports.leaderboard']->displayLabel() === 'لوحات الصدارة');
$GLOBALS['__loc'] = 'en';
chk('en displayLabel matches hardcoded label (fallback path)', $byId['overview.dashboard']->displayLabel() === 'Dashboard');

// ---- 3. toArray() + bundles emit the localized label -------------------------
echo "toArray() + MenuBundle emit localized labels\n";
$GLOBALS['__loc'] = 'fr';
$arr = $byId['overview.dashboard']->toArray();
chk('toArray label localized (fr)', ($arr['label'] ?? '') === 'Tableau de bord');

$catalog = new MenuCatalog($items);
$table = new RoleWordTable(['member' => 1]);
$rb = MenuBundle::assemble($catalog, ~0, 5, 'gA', $table->version());
$lbls = array_column($rb['items'], 'label', 'id');
chk('render bundle item label localized (fr)', ($lbls['overview.dashboard'] ?? '') === 'Tableau de bord');
$catLbls = array_column($rb['categories'], 'label', 'key');
chk('render bundle category label localized (fr)', ($catLbls[MenuCategory::OVERVIEW] ?? '') === 'Aperçu');

$ab = MenuBundle::authorityBundle($catalog, $table);
$abLbls = array_column($ab['items'], 'label', 'id');
chk('authority bundle item label localized (fr)', ($abLbls['events.create'] ?? '') === 'Créer un événement');

// ---- 4. category labels localize in every locale -----------------------------
echo "category labels localize in every locale\n";
$expect = [
    'fr' => 'Aperçu', 'es' => 'Resumen', 'pt' => 'Visão geral', 'zh' => '概览', 'ar' => 'نظرة عامة',
];
foreach ($expect as $loc => $word) {
    $GLOBALS['__loc'] = $loc;
    chk("{$loc} category OVERVIEW localized", MenuCategory::label(MenuCategory::OVERVIEW) === $word);
}

// ---- 5. locale is part of cache identity -------------------------------------
echo "locale folded into ETag + bundle versions (cache correctness)\n";
$svc = new MenuService($catalog, static fn () => 0, null, null, $table);
$etEn = $svc->etag(5, 'gA', 'en');
$etFr = $svc->etag(5, 'gA', 'fr');
chk('etag differs by locale (same grant/scope/catalog)', $etEn !== $etFr);
chk('etag stable for same locale', $etEn === $svc->etag(5, 'gA', 'en'));
chk('etagFor(static) carries locale too', MenuService::etagFor(5, 'gA', 'cat1', 'fr') !== MenuService::etagFor(5, 'gA', 'cat1', 'en'));

$verEn = MenuBundle::version(5, 'gA', 'cat1', 'rtv1', 'en');
$verFr = MenuBundle::version(5, 'gA', 'cat1', 'rtv1', 'fr');
chk('render-bundle version differs by locale', $verEn !== $verFr);

$GLOBALS['__loc'] = 'en';
$abEn = MenuBundle::authorityBundle($catalog, $table)['version'];
$GLOBALS['__loc'] = 'fr';
$abFr = MenuBundle::authorityBundle($catalog, $table)['version'];
chk('authority-bundle version differs by locale (no edge cross-serve)', $abEn !== $abFr);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
