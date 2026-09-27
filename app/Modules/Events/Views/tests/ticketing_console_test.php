<?php

declare(strict_types=1);

/**
 * TICKETING console — the write face of TicketingController::console + the two
 * ticketing-config write actions (create ticket type / create promo code).
 *
 * The event ticketing page (GET events/{id}/ticketing) is now a console: read
 * tables for ticket types and promo codes, plus a no-JS PRG form per write action
 * posting to a webcsrf-guarded route with the `_csrf` field. Attendee-facing
 * purchase flows (ticket-hold / checkout / pay / transfer) stay JSON/API-only and
 * are intentionally NOT part of this console. This test covers:
 *   - Events.ticketing.* key parity across all 6 locales
 *   - empty state hints; populated tables render rows (price/qty/promo value)
 *   - forms use _csrf, post to the correct routes, required fields present
 *   - RTL for Arabic; flash banners
 *   - controller: console renders the view / JSON for API; each write PRG via
 *     respondTicketing; service listPromoCodes() read helper exists
 *   - routes: GET console + both config write POSTs webcsrf-guarded; purchase
 *     routes remain un-webcsrf'd JSON
 *
 *   php app/Modules/Events/Views/tests/ticketing_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';
require $viewDir . '/tests/view_test_helpers.php';

$ctrl   = file_get_contents($root . '/app/Modules/Events/Controllers/TicketingController.php');
$svc    = file_get_contents($root . '/app/Modules/Events/Services/TicketingService.php');
$routes = file_get_contents($root . '/app/Config/Routes.php');

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }
    return $o;
};

echo "language parity (Events.ticketing.* across 6 locales)\n";
$en     = require $langDir . '/en/Events.php';
$enKeys = $flatten($en['ticketing'] ?? []);
chk('en defines Events.ticketing.*', $enKeys !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $miss = array_diff($enKeys, $flatten($m['ticketing'] ?? []));
    chk("$loc ticketing.* parity", $miss === [], implode(',', $miss));
}

$render = wbs_events_renderer($langDir);
$file   = $viewDir . '/ticketing_console.php';

echo "\nview: empty state\n";
$h = $render($file, [
    'ticketTypes' => [], 'promoCodes' => [],
    'eventId' => 'ev-1', 'csrf' => 'TKN',
], 'en');
chk('empty ticket types hint', str_contains($h, 'No ticket types yet.'));
chk('empty promo codes hint', str_contains($h, 'No promo codes yet.'));
chk('ticket-type form present in empty state', str_contains($h, 'action="/events/ev-1/ticket-types"'));
chk('promo-code form present in empty state', str_contains($h, 'action="/events/ev-1/promo-codes"'));
chk('ticket-type form uses _csrf', substr_count($h, 'name="_csrf" value="TKN"') >= 2);
chk('ticket-type required name field', str_contains($h, 'name="name" required'));
chk('ticket-type price_minor field', str_contains($h, 'name="price_minor"'));
chk('ticket-type currency field', str_contains($h, 'name="currency"'));
chk('promo required code field', str_contains($h, 'name="code" required'));
chk('promo kind select percent/amount', str_contains($h, 'value="percent"') && str_contains($h, 'value="amount"'));
chk('promo percent_bps field', str_contains($h, 'name="percent_bps"'));
chk('promo amount_minor field', str_contains($h, 'name="amount_minor"'));

echo "\nview: populated state\n";
$h = $render($file, [
    'ticketTypes' => [
        ['name' => 'General', 'price_minor' => 5000, 'currency' => 'GHS', 'quantity_total' => 200, 'status' => 'active'],
        ['name' => 'VIP', 'price_minor' => 20000, 'currency' => 'GHS', 'quantity_total' => null, 'status' => 'active'],
    ],
    'promoCodes' => [
        ['code' => 'EARLY10', 'kind' => 'percent', 'percent_bps' => 1000, 'amount_minor' => null, 'status' => 'active'],
        ['code' => 'FLAT5', 'kind' => 'amount', 'percent_bps' => null, 'amount_minor' => 500, 'status' => 'active'],
    ],
    'eventId' => 'ev-1', 'csrf' => 'TKN',
], 'en');
chk('renders ticket type name', str_contains($h, 'General') && str_contains($h, 'VIP'));
chk('renders formatted price', str_contains($h, '50.00 GHS') && str_contains($h, '200.00 GHS'));
chk('null quantity shows Unlimited', str_contains($h, 'Unlimited'));
chk('renders promo code', str_contains($h, 'EARLY10') && str_contains($h, 'FLAT5'));
chk('percent promo value rendered', str_contains($h, '10%'));
chk('amount promo value rendered', str_contains($h, '5.00'));
chk('no empty-type hint when populated', ! str_contains($h, 'No ticket types yet.'));

echo "\nview: escaping + flash + i18n\n";
$h = $render($file, [
    'ticketTypes' => [['name' => '<script>x</script>', 'price_minor' => 100, 'currency' => 'GHS', 'quantity_total' => 1, 'status' => 'active']],
    'promoCodes' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN',
], 'en');
chk('escapes ticket type name', ! str_contains($h, '<script>x</script>') && str_contains($h, '&lt;script&gt;'));

// French locale
$h = $render($file, ['ticketTypes' => [], 'promoCodes' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'fr');
chk('fr localized heading', str_contains($h, 'Billetterie'));

// Arabic RTL
$h = $render($file, ['ticketTypes' => [], 'promoCodes' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'ar');
chk('ar sets dir=rtl', str_contains($h, 'dir="rtl"'));
chk('ar sets lang=ar', str_contains($h, 'lang="ar"'));

echo "\ncontroller wiring\n";
chk('console() action exists', str_contains($ctrl, 'function console('));
chk('console renders ticketing_console view', str_contains($ctrl, 'WBS\Events\Views\ticketing_console'));
chk('console serves JSON for API', str_contains($ctrl, 'wantsJson()') && str_contains($ctrl, "'promo_codes'"));
chk('createTicketType uses respondTicketing', (bool) preg_match('/function createTicketType.*?respondTicketing/s', $ctrl));
chk('createPromoCode uses respondTicketing', (bool) preg_match('/function createPromoCode.*?respondTicketing/s', $ctrl));
chk('respondTicketing PRG redirects to /ticketing', str_contains($ctrl, "/ticketing'") || str_contains($ctrl, "'/ticketing"));
chk('respondTicketing keeps JSON for API', (bool) preg_match('/function respondTicketing.*?wantsJson\(\)/s', $ctrl));
chk('ticketTypeCreatedFlash key used', str_contains($ctrl, 'ticketTypeCreatedFlash'));
chk('promoCreatedFlash key used', str_contains($ctrl, 'promoCreatedFlash'));

echo "\nservice wiring\n";
chk('listPromoCodes() read helper exists', str_contains($svc, 'function listPromoCodes('));
chk('listTicketTypes() read helper exists', str_contains($svc, 'function listTicketTypes('));

echo "\nroutes\n";
chk('GET ticketing console route', (bool) preg_match('#get\([^\n]*ticketing[^\n]*TicketingController::console#', $routes));
chk('ticket-types POST webcsrf-guarded', (bool) preg_match("#post\('\(:segment\)/ticket-types'.*?createTicketType.*?webcsrf#s", $routes));
chk('promo-codes POST webcsrf-guarded', (bool) preg_match("#post\('\(:segment\)/promo-codes'.*?createPromoCode.*?webcsrf#s", $routes));
chk('checkout stays un-webcsrf JSON purchase', (bool) preg_match("#post\('\(:segment\)/checkout'.*?ratelimit#", $routes) && ! (bool) preg_match("#checkout.*?webcsrf#", $routes));

echo "\n" . ($fail === 0 ? "PASS" : "FAIL") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
