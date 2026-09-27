<?php

declare(strict_types=1);

/**
 * TranslationRegistry + providers + Interpolator test.
 *
 * Proves the unified merge layer: file catalogs + DB sources
 * (notification_templates, JSON-column reference data) merge into one
 * English-backed catalog per locale; precedence (last provider wins) lets
 * DB-authored overrides shadow bundled defaults; both {0} positional and :name
 * named placeholders interpolate; and the resource discipline holds — each
 * provider is loaded at most once per locale, and a version-stamped cross-request
 * cache avoids rebuilds until a provider's version changes.
 *
 *   php app/Modules/Shared/I18n/tests/translation_registry_test.php
 */

$root = dirname(__DIR__, 5);
require_once $root . '/app/Modules/Shared/I18n/Interpolator.php';
require_once $root . '/app/Modules/Shared/I18n/TranslationProvider.php';
require_once $root . '/app/Modules/Shared/I18n/TranslationRegistry.php';
require_once $root . '/app/Modules/Shared/I18n/Providers/FileCatalogProvider.php';
require_once $root . '/app/Modules/Shared/I18n/Providers/NotificationTemplateProvider.php';
require_once $root . '/app/Modules/Shared/I18n/Providers/JsonColumnProvider.php';

use WBS\Shared\I18n\Interpolator;
use WBS\Shared\I18n\Providers\FileCatalogProvider;
use WBS\Shared\I18n\Providers\JsonColumnProvider;
use WBS\Shared\I18n\Providers\NotificationTemplateProvider;
use WBS\Shared\I18n\TranslationProvider;
use WBS\Shared\I18n\TranslationRegistry;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── Interpolator ────────────────────────────────────────────────────────────
echo "interpolator (both placeholder styles)\n";
chk('positional {0}/{1}', Interpolator::apply('Season {0} · by {1}', [12, 'points']) === 'Season 12 · by points');
chk('named :key', Interpolator::apply('Hi :name, welcome to :org', ['name' => 'Ama', 'org' => 'WBS']) === 'Hi Ama, welcome to WBS');
chk('mixed styles in one string', Interpolator::apply('{0} at :venue', ['0' => 'Camp', 'venue' => 'Hall']) === 'Camp at Hall');
chk('longest-name-first (:user_id vs :user)', Interpolator::apply(':user_id/:user', ['user' => 'A', 'user_id' => 'X']) === 'X/A');
chk('missing positional left literal', Interpolator::apply('a {0} {1}', [0 => 'x']) === 'a x {1}');
chk('missing named left literal', Interpolator::apply('hi :name', []) === 'hi :name');
chk('curly named {{ns.field}}', Interpolator::apply('{{event.title}}!', ['event.title' => 'Camp']) === 'Camp!');
chk('no params is identity', Interpolator::apply('plain {0} :x', []) === 'plain {0} :x');

// ── A counting fake provider (to prove one-load-per-locale) ──────────────────
$makeProvider = static function (string $name, array $byLocale, string $ver, ?array &$calls = null): TranslationProvider {
    return new class ($name, $byLocale, $ver, $calls) implements TranslationProvider {
        public function __construct(private string $n, private array $data, private string $v, private ?array &$calls) {}
        public function name(): string { return $this->n; }
        public function load(string $locale): array
        {
            if ($this->calls !== null) {
                $this->calls[] = $this->n . ':' . $locale;
            }
            return $this->data[$locale] ?? [];
        }
        public function version(): string { return $this->v; }
    };
};

// ── Merge + English fallback + precedence ────────────────────────────────────
echo "merge, fallback, precedence\n";
$calls = [];
$base  = $makeProvider('base', [
    'en' => ['brand' => 'WBS', 'greeting' => 'Hello', 'only_en' => 'EN'],
    'fr' => ['greeting' => 'Bonjour'],
], 'v1', $calls);
$override = $makeProvider('override', [
    'fr' => ['brand' => 'WBS-FR'], // shadows base 'brand' for fr
], 'v1', $calls);

$reg = new TranslationRegistry([$base, $override], 'en');
chk('fr greeting from fr layer', $reg->translate('greeting', 'fr') === 'Bonjour');
chk('fr brand overridden by later provider', $reg->translate('brand', 'fr') === 'WBS-FR');
chk('fr falls back to en for missing key', $reg->translate('only_en', 'fr') === 'EN');
chk('unknown key returns key itself', $reg->translate('does.not.exist', 'fr') === 'does.not.exist');
chk('has() true for real key', $reg->has('greeting', 'fr'));
chk('has() false for raw-key miss', ! $reg->has('nope', 'fr'));
chk('providerNames in order', $reg->providerNames() === ['base', 'override']);

// one load per provider per locale: fr build loads en (fallback) + fr for each provider
sort($calls);
$expected = ['base:en', 'base:fr', 'override:en', 'override:fr'];
chk('each provider loaded once per locale (en+fr)', $calls === $expected, implode(',', $calls));

// memoised: a second translate for fr triggers NO further loads
$before = count($calls);
$reg->translate('brand', 'fr');
chk('repeat lookup does no extra provider loads', count($calls) === $before);

// English target skips the fallback pass (loads en only, once per provider)
$calls2 = [];
$b2     = $makeProvider('b', ['en' => ['x' => 'X']], 'v1', $calls2);
$reg2   = new TranslationRegistry([$b2], 'en');
$reg2->catalog('en');
chk('english target loads en only', $calls2 === ['b:en'], implode(',', $calls2));

// ── Version-stamped cross-request cache ──────────────────────────────────────
echo "version-stamped cache\n";
$store = [];
$get   = static function (string $k) use (&$store) { return $store[$k] ?? null; };
$set   = static function (string $k, array $v, int $ttl) use (&$store) { $store[$k] = $v; };

$ver     = 'v1';
$counting = [];
$p       = new class ($ver, $counting) implements TranslationProvider {
    public int $loads = 0;
    public function __construct(private string &$ver, private array &$c) {}
    public function name(): string { return 'p'; }
    public function load(string $locale): array { $this->loads++; return ['k' => 'V-' . $this->ver]; }
    public function version(): string { return $this->ver; }
};
$regA = new TranslationRegistry([$p], 'en', $get, $set);
chk('first build populates cache', $regA->translate('k', 'en') === 'V-v1');
$loadsAfterFirst = $p->loads;

// A brand-new registry instance (simulating a new request) with the SAME cache
// and unchanged version must hit the cache and NOT reload the provider.
$regB = new TranslationRegistry([$p], 'en', $get, $set);
chk('second request served from cache', $regB->translate('k', 'en') === 'V-v1');
chk('cache hit did NOT reload provider', $p->loads === $loadsAfterFirst);

// Bump the provider version -> new cache key -> exactly one rebuild.
$ver  = 'v2';
$regC = new TranslationRegistry([$p], 'en', $get, $set);
chk('version bump invalidates cache', $regC->translate('k', 'en') === 'V-v2');
chk('rebuild happened once', $p->loads === $loadsAfterFirst + 1);

// ── DB providers ─────────────────────────────────────────────────────────────
echo "db providers\n";
$ntLoads = 0;
$nt      = new NotificationTemplateProvider(
    function (string $locale) use (&$ntLoads): array {
        $ntLoads++;
        $rows = [
            'en' => [
                ['key_name' => 'welcome', 'channel' => 'email', 'subject' => 'Welcome :name', 'body' => 'Hi :name'],
                ['key_name' => 'secret_alert', 'channel' => 'email', 'subject' => 'Alert', 'body' => 'body'],
            ],
            'fr' => [
                ['key_name' => 'welcome', 'channel' => 'email', 'subject' => 'Bienvenue :name', 'body' => 'Salut :name'],
                // secret_alert intentionally missing in fr -> should fall back to en
            ],
        ];
        return $rows[$locale] ?? [];
    },
    static fn (): string => '7',
);
$regDb = new TranslationRegistry([$nt], 'en');
chk('notif fr subject localized + named param', $regDb->translate('notif.welcome.email.subject', 'fr', ['name' => 'Ama']) === 'Bienvenue Ama');
chk('notif fr missing key falls back to en', $regDb->translate('notif.secret_alert.email.subject', 'fr') === 'Alert');
chk('notif provider version prefixed', $nt->version() === 'n7');
chk('notif loaded once per locale (en+fr = 2)', $ntLoads === 2, (string) $ntLoads);

$geo = new JsonColumnProvider(
    'geo.countries',
    static fn (): array => [
        ['id' => 'DE', 'name' => 'Germany', 'translations' => '{"fr":"Allemagne","ar":"ألمانيا"}'],
        ['id' => 'JP', 'name' => 'Japan', 'translations' => ['fr' => 'Japon']], // pre-decoded array
        ['id' => 'ZZ', 'name' => 'Nowhere'], // no translations -> name fallback
    ],
    static fn (): string => '3',
);
$regGeo = new TranslationRegistry([$geo], 'en');
chk('geo json-string translation (fr)', $regGeo->translate('geo.countries.DE', 'fr') === 'Allemagne');
chk('geo pre-decoded array translation (fr)', $regGeo->translate('geo.countries.JP', 'fr') === 'Japon');
chk('geo missing locale -> name fallback', $regGeo->translate('geo.countries.DE', 'zh') === 'Germany');
chk('geo row without translations -> name', $regGeo->translate('geo.countries.ZZ', 'fr') === 'Nowhere');

// ── FileCatalogProvider against the REAL shipped catalogs ────────────────────
echo "file catalog provider (real shipped files)\n";
$files = new FileCatalogProvider([
    $root . '/app/Language',
    ...glob($root . '/app/Modules/*/Language', GLOB_ONLYDIR),
]);
$enFlat = $files->load('en');
chk('flattens Identity.login.heading', array_key_exists('Identity.login.heading', $enFlat), 'keys=' . count($enFlat));
$frFlat = $files->load('fr');
chk('fr has a Gamification key', array_key_exists('Gamification.points', $frFlat));
$regFiles = new TranslationRegistry([$files], 'en');
chk('registry reads real fr file string', $regFiles->translate('Gamification.points', 'fr') === 'Points');
chk('registry falls back to en for locale-missing file key', is_string($regFiles->translate('Identity.login.heading', 'zh')) && $regFiles->translate('Identity.login.heading', 'zh') !== 'Identity.login.heading');

// ── Full stack: files + notif + geo, one registry ────────────────────────────
echo "full-stack merge\n";
$full = new TranslationRegistry([$files, $nt, $geo], 'en');
$cat  = $full->catalog('fr');
chk('merged catalog has file key', array_key_exists('Gamification.points', $cat));
chk('merged catalog has notif key', array_key_exists('notif.welcome.email.subject', $cat));
chk('merged catalog has geo key', array_key_exists('geo.countries.DE', $cat));
chk('merged catalog is all strings', array_filter($cat, static fn ($v) => ! is_string($v)) === []);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
