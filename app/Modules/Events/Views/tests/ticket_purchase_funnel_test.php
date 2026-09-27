<?php

declare(strict_types=1);

/**
 * TICKET PURCHASE FUNNEL wiring test — closes the section-C member-facing gap
 * where paid ticketing was a JSON-only two-step hold→checkout API with NO
 * browser page and NO auth+webcsrf on the buy path. Proves the event now has an
 * attendee-facing, no-JS, CSP-safe self-service ticket purchase funnel:
 *
 *   - TicketingService::purchaseView returns a buyer snapshot (on-sale ticket
 *     types + remaining availability + the buyer's live hold + their paid
 *     tickets); resource-light (bounded reads, no per-row fan-out);
 *   - TicketingService::purchase collapses hold+checkout into ONE call and
 *     confirms a FREE / zero-total order immediately (markPaid), leaving paid
 *     orders pending; requires a signed-in buyer;
 *   - TicketingController::tickets (GET) is buyer-aware and renders the page;
 *     purchase (POST) buys as the SESSION user and PRGs back with a
 *     confirmed / payment-required flash; JSON kept for API;
 *   - POST /events/{id}/tickets carries auth + webcsrf (+ ratelimit); the raw
 *     hold/checkout JSON endpoints stay un-webcsrf'd for API two-step flows;
 *   - the view renders per-tier buy forms only for available tiers to a
 *     signed-in buyer — CSP-clean, csrf-bound — with sign-in / closed fallbacks;
 *   - i18n parity for the new Events.tickets.* block across all 6 locales.
 *
 *   php app/Modules/Events/Views/tests/ticket_purchase_funnel_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Events/Language';
$viewDir    = $root . '/app/Modules/Events/Views';
$controller = $root . '/app/Modules/Events/Controllers/TicketingController.php';
$service    = $root . '/app/Modules/Events/Services/TicketingService.php';
$servicesF  = $root . '/app/Modules/Events/Config/Services.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity for Events.tickets.* ──────────────────────────────────────
echo "language parity (Events.tickets.* — all locales)\n";
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};
$en     = require $langDir . '/en/Events.php';
$enKeys = $flat($en['tickets'] ?? []);
chk('en tickets block present (>= 20 keys)', count($enKeys) >= 20, (string) count($enKeys));
chk('en remaining keeps {0}', str_contains((string) ($en['tickets']['remaining'] ?? ''), '{0}'));
chk('en holdNote keeps {0}', str_contains((string) ($en['tickets']['holdNote'] ?? ''), '{0}'));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr  = require $langDir . "/$loc/Events.php";
    $keys = $flat($arr['tickets'] ?? []);
    chk("$loc mirrors tickets keys", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
        'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
}

// ── 2. Service: purchaseView + one-step purchase ─────────────────────────────
echo "service: purchaseView + purchase\n";
$svc = (string) file_get_contents($service);
chk('purchaseView() present', str_contains($svc, 'function purchaseView'));
chk('purchaseView computes remaining from sold vs total', (bool) preg_match('/quantity_total.*?quantity_sold|remaining.*?quantity_sold/s', $svc));
chk('purchaseView returns buyer signed_in + my_tickets', str_contains($svc, "'signed_in'") && str_contains($svc, "'my_tickets'"));
chk('purchase() present', str_contains($svc, 'function purchase('));
chk('purchase requires a signed-in buyer', (bool) preg_match('/function purchase\(.*?SIGN_IN_REQUIRED/s', $svc));
chk('purchase reuses the atomic hold', (bool) preg_match('/function purchase\(.*?registrations->hold\(/s', $svc));
chk('purchase runs checkout against the hold', (bool) preg_match('/function purchase\(.*?\$this->checkout\(/s', $svc));
chk('purchase confirms a zero-total order immediately', (bool) preg_match('/function purchase\(.*?\$total === 0.*?markPaid\(/s', $svc));
chk('service takes RegistrationService dep', (bool) preg_match('/__construct\(.*?RegistrationService \$registrations/s', $svc));
$wire = (string) file_get_contents($servicesF);
chk('Services wires RegistrationService into ticketing', (bool) preg_match('/new TicketingService\(.*?eventRegistrations\(\)/s', $wire));

// ── 3. Controller: buyer-aware GET + PRG purchase POST ───────────────────────
echo "controller: tickets(GET) + purchase(POST)\n";
$ctrl = (string) file_get_contents($controller);
chk('tickets() renders the purchase page', (bool) preg_match('/function tickets\(.*?Views\\\\\\\\tickets/s', $ctrl));
chk('tickets() passes purchaseView snapshot', (bool) preg_match('/function tickets\(.*?purchaseView\(/s', $ctrl));
chk('tickets() keeps JSON for API', (bool) preg_match('/function tickets\(.*?wantsJson\(\)/s', $ctrl));
chk('purchase() buys as the SESSION user', (bool) preg_match('/function purchase\(.*?currentUserId\(/s', $ctrl));
chk('purchase() calls the service purchase', (bool) preg_match('/function purchase\(.*?ticketing\(\)->purchase\(/s', $ctrl));
chk('purchase() PRGs with confirmed/paid flash', (bool) preg_match('/function purchase\(.*?purchasedFreeFlash.*?purchasedPaidFlash/s', $ctrl));
chk('purchase() keeps JSON for API', (bool) preg_match('/function purchase\(.*?wantsJson\(\)/s', $ctrl));

// ── 4. Routes: purchase page + buy POST guarded; raw API endpoints intact ────
echo "routes: tickets GET/POST guarded; hold/checkout intact\n";
$routes = (string) file_get_contents($routesFile);
chk('GET tickets page route', (bool) preg_match("#get\\('\\(:segment\\)/tickets'.*?TicketingController::tickets#", $routes));
if (preg_match("#post\\('\\(:segment\\)/tickets'.*?purchase.*#", $routes, $m)) {
    chk('POST tickets purchase route', true);
    chk('purchase POST has auth', str_contains($m[0], "'auth'"));
    chk('purchase POST has webcsrf', str_contains($m[0], 'webcsrf'));
    chk('purchase POST rate-limited', str_contains($m[0], 'ratelimit:event.checkout'));
} else {
    chk('POST tickets purchase route', false);
}
chk('raw checkout stays un-webcsrf JSON', (bool) preg_match("#post\\('\\(:segment\\)/checkout'.*?ratelimit#", $routes) && ! (bool) preg_match('#checkout.{0,80}webcsrf#', $routes));
chk('raw ticket-hold stays un-webcsrf JSON', (bool) preg_match("#post\\('\\(:segment\\)/ticket-hold'.*?ratelimit#", $routes) && ! (bool) preg_match('#ticket-hold.{0,80}webcsrf#', $routes));

// ── 5. View controls: CSP-clean, csrf-bound, no-JS ───────────────────────────
echo "tickets.php view controls\n";
$src = (string) file_get_contents("$viewDir/tickets.php");
chk('buy form posts to /events/{id}/tickets', str_contains($src, '/tickets"'));
chk('buy form carries ticket_type_id + quantity', str_contains($src, 'name="ticket_type_id"') && str_contains($src, 'name="quantity"'));
chk('buy form carries _csrf bound to token', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash', str_contains($src, "getFlashdata('success')") && str_contains($src, "getFlashdata('error')"));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input)\s*=/i', $noC));

// ── 6. Headless render smoke (fr) ────────────────────────────────────────────
echo "render smoke — on-sale/free/paid/soldout, hold, anon, closed, my-tickets (fr)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Events') { return $key; }
        $v = $GLOBALS['__tkLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$GLOBALS['__tkLang'] = require $langDir . '/fr/Events.php';
$renderer = new class {
    function extend($x) { return ''; }
    function section($x) { return ''; }
    function endSection() { return ''; }
    function render(string $file, array $data): string {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
};
$view = "$viewDir/tickets.php";
$tiers = [
    ['id' => 'tt-free', 'name' => 'Gratuit', 'description' => null, 'price_minor' => 0, 'currency' => 'GHS', 'remaining' => 50, 'is_free' => true, 'available' => true],
    ['id' => 'tt-vip', 'name' => 'VIP', 'description' => 'Premium', 'price_minor' => 5000, 'currency' => 'GHS', 'remaining' => 3, 'is_free' => false, 'available' => true],
    ['id' => 'tt-out', 'name' => 'Sold out tier', 'description' => null, 'price_minor' => 2000, 'currency' => 'GHS', 'remaining' => 0, 'is_free' => false, 'available' => false],
];

// signed-in buyer, on sale
$h = $renderer->render($view, ['eventId' => 'ev9', 'ticketTypes' => $tiers, 'hold' => null, 'myTickets' => [], 'signedIn' => true, 'onSale' => true, 'csrf' => 'TKN']);
chk('signed-in: buy form present', str_contains($h, 'action="/events/ev9/tickets"'));
chk('signed-in: free tier uses get-free button', str_contains($h, lang('Events.tickets.getFreeBtn')));
chk('signed-in: paid tier uses buy button', str_contains($h, lang('Events.tickets.buyBtn')));
chk('signed-in: paid tier shows promo input', str_contains($h, 'name="promo_code"'));
chk('signed-in: sold-out tier shows sold out', str_contains($h, lang('Events.tickets.soldOut')));
chk('signed-in: sold-out tier has no buy form for tt-out', ! str_contains($h, 'value="tt-out"'));
chk('signed-in: csrf bound', str_contains($h, 'value="TKN"'));
chk('signed-in: remaining count interpolated (3)', str_contains($h, str_replace('{0}', '3', lang('Events.tickets.remaining'))));

// live hold reminder
$h = $renderer->render($view, ['eventId' => 'ev9', 'ticketTypes' => $tiers, 'hold' => ['id' => 'h1', 'quantity' => 2, 'expires_at' => '2026-01-01 00:00:00'], 'myTickets' => [], 'signedIn' => true, 'onSale' => true, 'csrf' => 'TKN']);
chk('hold: hold reminder shown (qty 2)', str_contains($h, str_replace('{0}', '2', lang('Events.tickets.holdNote'))));

// anonymous viewer -> no buy forms, sign-in prompt
$h = $renderer->render($view, ['eventId' => 'ev9', 'ticketTypes' => $tiers, 'hold' => null, 'myTickets' => [], 'signedIn' => false, 'onSale' => true, 'csrf' => '']);
chk('anon: sign-in prompt shown', str_contains($h, lang('Events.tickets.signInPrompt')));
chk('anon: no buy form', ! str_contains($h, 'action="/events/ev9/tickets"'));

// closed / off sale
$h = $renderer->render($view, ['eventId' => 'ev9', 'ticketTypes' => $tiers, 'hold' => null, 'myTickets' => [], 'signedIn' => true, 'onSale' => false, 'csrf' => 'TKN']);
chk('closed: closed note shown', str_contains($h, lang('Events.tickets.closedNote')));
chk('closed: no buy form', ! str_contains($h, 'action="/events/ev9/tickets"'));

// my tickets table
$h = $renderer->render($view, ['eventId' => 'ev9', 'ticketTypes' => [], 'hold' => null, 'myTickets' => [['id' => 'oi1', 'ticket_name' => 'VIP', 'quantity' => 1, 'unit_price_minor' => 5000, 'currency' => 'GHS', 'item_status' => 'active', 'order_status' => 'paid']], 'signedIn' => true, 'onSale' => true, 'csrf' => 'TKN']);
chk('my-tickets: paid ticket listed', str_contains($h, 'VIP') && str_contains($h, lang('Events.tickets.ticketStatusPaid')));
chk('render: no untranslated Events.tickets keys leaked', ! str_contains($h, 'Events.tickets.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
