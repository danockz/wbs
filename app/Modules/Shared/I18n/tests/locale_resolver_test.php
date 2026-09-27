<?php

declare(strict_types=1);

/**
 * LocaleResolver tests — the pure core of Phase 1 language-awareness.
 *
 *   php app/Modules/Shared/I18n/tests/locale_resolver_test.php
 */

$root = dirname(__DIR__, 5);
require_once $root . '/app/Modules/Shared/I18n/LocaleResolver.php';

use WBS\Shared\I18n\LocaleResolver;

$pass = 0;
$fail = 0;
function eq(string $label, $got, $want): void
{
    global $pass, $fail;
    $ok = $got === $want;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label
        . ($ok ? '' : ' (got ' . var_export($got, true) . ', want ' . var_export($want, true) . ')') . "\n";
    $ok ? $pass++ : $fail++;
}

$supported = ['en', 'fr', 'es', 'pt', 'zh', 'ar'];
$countries = ['FR' => 'fr', 'BR' => 'pt', 'CN' => 'zh', 'SA' => 'ar', 'GH' => 'en', 'DE' => 'de'];
$r = new LocaleResolver($supported, 'en', $countries);

echo "canonicalize / clamp\n";
eq('exact supported', $r->canonicalize('fr'), 'fr');
eq('regional variant -> base', $r->canonicalize('fr-CA'), 'fr');
eq('script+region -> base', $r->canonicalize('zh_Hans_CN'), 'zh');
eq('uppercase normalized', $r->canonicalize('AR'), 'ar');
eq('unsupported -> null', $r->canonicalize('de'), null);
eq('path traversal -> null', $r->canonicalize('../../etc/passwd'), null);
eq('empty -> null', $r->canonicalize(''), null);
eq('null -> null', $r->canonicalize(null), null);
eq('garbage digits -> null', $r->canonicalize('12'), null);
eq('isSupported true', $r->isSupported('pt-BR'), true);
eq('isSupported false', $r->isSupported('ru'), false);

echo "fromCountry (geo)\n";
eq('FR -> fr', $r->fromCountry('FR'), 'fr');
eq('lowercase br -> pt', $r->fromCountry('br'), 'pt');
eq('GH -> en', $r->fromCountry('GH'), 'en');
eq('DE maps to unsupported de -> null', $r->fromCountry('DE'), null);
eq('unknown country -> null', $r->fromCountry('JP'), null);
eq('CF unknown XX -> null', $r->fromCountry('XX'), null);
eq('CF Tor T1 -> null', $r->fromCountry('T1'), null);
eq('null -> null', $r->fromCountry(null), null);

echo "fromAcceptLanguage\n";
eq('q-weighted pick', $r->fromAcceptLanguage('fr-CA,fr;q=0.9,en;q=0.5'), 'fr');
eq('skip unsupported, take en', $r->fromAcceptLanguage('de,en;q=0.4'), 'en');
eq('highest q wins', $r->fromAcceptLanguage('en;q=0.3,es;q=0.9'), 'es');
eq('q=0 ignored', $r->fromAcceptLanguage('ar;q=0,en;q=0.1'), 'en');
eq('all unsupported -> null', $r->fromAcceptLanguage('de,ru,ja'), null);
eq('empty -> null', $r->fromAcceptLanguage(''), null);
eq('null -> null', $r->fromAcceptLanguage(null), null);

echo "resolve() precedence cascade\n";
eq('explicit beats everything', $r->resolve([
    'explicit' => 'ar', 'cookie' => 'fr', 'orgDefault' => 'es', 'country' => 'BR', 'acceptLanguage' => 'en',
]), 'ar');
eq('cookie beats org/geo/browser', $r->resolve([
    'cookie' => 'fr', 'orgDefault' => 'es', 'country' => 'BR', 'acceptLanguage' => 'en',
]), 'fr');
eq('org beats geo/browser', $r->resolve([
    'orgDefault' => 'es', 'country' => 'BR', 'acceptLanguage' => 'en',
]), 'es');
eq('geo beats browser', $r->resolve([
    'country' => 'BR', 'acceptLanguage' => 'en',
]), 'pt');
eq('browser when only signal', $r->resolve([
    'acceptLanguage' => 'zh-CN,zh;q=0.9',
]), 'zh');
eq('default when nothing', $r->resolve([]), 'en');
eq('invalid explicit falls through to cookie', $r->resolve([
    'explicit' => 'xx', 'cookie' => 'fr',
]), 'fr');
eq('invalid everything -> default', $r->resolve([
    'explicit' => 'zz', 'cookie' => '..', 'country' => 'JP', 'acceptLanguage' => 'ru',
]), 'en');

echo "resolveWithSource()\n";
eq('source=explicit', $r->resolveWithSource(['explicit' => 'fr'])['source'], 'explicit');
eq('source=cookie', $r->resolveWithSource(['cookie' => 'es'])['source'], 'cookie');
eq('source=org', $r->resolveWithSource(['orgDefault' => 'pt'])['source'], 'org');
eq('source=geo', $r->resolveWithSource(['country' => 'SA'])['source'], 'geo');
eq('source=browser', $r->resolveWithSource(['acceptLanguage' => 'zh'])['source'], 'browser');
eq('source=default', $r->resolveWithSource([])['source'], 'default');

echo "constructor robustness\n";
$empty = new LocaleResolver([], 'xx');
eq('empty allowlist -> en default', $empty->default(), 'en');
eq('empty allowlist supported=[en]', $empty->supported(), ['en']);
$r2 = new LocaleResolver(['fr', 'en'], 'de'); // default not in list
eq('default not in list -> first supported', $r2->default(), 'fr');

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
