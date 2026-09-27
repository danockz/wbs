<?php

declare(strict_types=1);

/**
 * EVENTS DASHBOARDS wiring test — proves the three net-new organizer/attendee
 * surfaces added on top of the SRS-complete Events module are fully wired,
 * resource-light, i18n-complete, and CSP-safe:
 *
 *   1. Cross-event ANALYTICS dashboard (GET /events/analytics) — a birds-eye
 *      across the whole programme (totals, RSVP→check-in conversion, ticket
 *      revenue, mode/status splits, upcoming + filling lists). Organizer-gated
 *      by report.view. AnalyticsService::dashboard is resource-light: ONE events
 *      read + grouped registration/attendance reads + ONE revenue SUM.
 *   2. Events CALENDAR (GET /events/calendar?month=YYYY-MM) — a pure-PHP month
 *      grid; EventService::listInRange does ONE bounded range read.
 *   3. "My events" HUB (GET /events/mine) — the signed-in member's own
 *      registrations/attendance/certificates/tickets; auth-guarded and always
 *      scoped to the SESSION user. RegistrationService::myEvents batches reads.
 *
 * Each view is SELF-CONTAINED (own <html> + _locale.php), no-JS, CSP-clean, and
 * renders correctly in every locale with English fallback. i18n parity for the
 * new Events.analytics.* / Events.calendar.* / Events.myEvents.* blocks across
 * all 6 locales is asserted, plus the App.menuItems.events_* labels.
 *
 *   php app/Modules/Events/Views/tests/events_dashboards_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Events/Language';
$appLangDir = $root . '/app/Language';
$viewDir    = $root . '/app/Modules/Events/Views';
$controller = $root . '/app/Modules/Events/Controllers/EventController.php';
$analytics  = $root . '/app/Modules/Events/Services/AnalyticsService.php';
$eventSvc   = $root . '/app/Modules/Events/Services/EventService.php';
$regSvc     = $root . '/app/Modules/Events/Services/RegistrationService.php';
$servicesF  = $root . '/app/Modules/Events/Config/Services.php';
$routesFile = $root . '/app/Config/Routes.php';
$menuFile   = $root . '/app/Modules/Shared/Navigation/CoreMenuProvider.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};

// ── 1. i18n parity for the three new blocks ──────────────────────────────────
echo "language parity (Events.{analytics,calendar,myEvents}.* — all locales)\n";
$en = require $langDir . '/en/Events.php';
foreach (['analytics', 'calendar', 'myEvents'] as $block) {
    chk("en has Events.$block block", isset($en[$block]) && is_array($en[$block]) && $en[$block] !== []);
}
$enA = $flat($en['analytics'] ?? []);
$enC = $flat($en['calendar'] ?? []);
$enM = $flat($en['myEvents'] ?? []);
chk('en analytics groupScoped keeps {0}', str_contains((string) ($en['analytics']['groupScoped'] ?? ''), '{0}'));
chk('en calendar more keeps {0}', str_contains((string) ($en['calendar']['more'] ?? ''), '{0}'));
chk('en calendar has subscribe label', ($en['calendar']['subscribe'] ?? '') !== '');
chk('en calendar months has 12 comma items', count(explode(',', (string) ($en['calendar']['months'] ?? ''))) === 12);
chk('en calendar weekdays has 7 comma items', count(explode(',', (string) ($en['calendar']['weekdays'] ?? ''))) === 7);
chk('en myEvents ticketsHeld keeps {0}', str_contains((string) ($en['myEvents']['ticketsHeld'] ?? ''), '{0}'));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr = require $langDir . "/$loc/Events.php";
    foreach (['analytics' => $enA, 'calendar' => $enC, 'myEvents' => $enM] as $block => $enKeys) {
        $keys = $flat($arr[$block] ?? []);
        chk("$loc mirrors $block keys", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
            'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
    }
    // months/weekdays must have the right cardinality in every locale.
    chk("$loc calendar months has 12 items", count(explode(',', (string) ($arr['calendar']['months'] ?? ''))) === 12);
    chk("$loc calendar weekdays has 7 items", count(explode(',', (string) ($arr['calendar']['weekdays'] ?? ''))) === 7);
}

// ── 2. Menu item labels present in all 6 App.php locales ──────────────────────
echo "menu labels (App.menuItems.events_{calendar,mine,analytics})\n";
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $app = require $appLangDir . "/$loc/App.php";
    $mi  = $app['menuItems'] ?? [];
    foreach (['events_calendar', 'events_mine', 'events_analytics'] as $k) {
        chk("$loc has menuItems.$k", isset($mi[$k]) && $mi[$k] !== '');
    }
}
$menu = (string) file_get_contents($menuFile);
chk('CoreMenuProvider registers events.calendar → events/calendar', (bool) preg_match("#'events\\.calendar'.*?'events/calendar'#", $menu));
chk('CoreMenuProvider registers events.mine → events/mine', (bool) preg_match("#'events\\.mine'.*?'events/mine'#", $menu));
chk('CoreMenuProvider registers events.analytics gated by report.view', (bool) preg_match("#'events\\.analytics'.*?'events/analytics'.*?report\\.view#s", $menu));

// ── 3. Services: resource-light shapes ───────────────────────────────────────
echo "services: analytics dashboard + listInRange + myEvents\n";
$aSrc = (string) file_get_contents($analytics);
chk('AnalyticsService::dashboard present', str_contains($aSrc, 'function dashboard('));
chk('dashboard returns totals/people/revenue/by_mode/by_status/upcoming/filling',
    str_contains($aSrc, "'totals'") && str_contains($aSrc, "'people'") && str_contains($aSrc, "'revenue'")
    && str_contains($aSrc, "'by_mode'") && str_contains($aSrc, "'by_status'") && str_contains($aSrc, "'upcoming'") && str_contains($aSrc, "'filling'"));
chk('dashboard exposes conversion_pct + no_show', str_contains($aSrc, 'conversion_pct') && str_contains($aSrc, 'no_show'));
chk('dashboard registrations read is grouped (no per-row fan-out)', (bool) preg_match('/event_registrations.*?groupBy/s', $aSrc));
chk('dashboard attendance read is grouped', (bool) preg_match('/event_attendance.*?groupBy/s', $aSrc));
chk('dashboard revenue is a single paid SUM', (bool) preg_match("/event_orders.*?SUM\\(.*?paid/s", $aSrc));

$eSrc = (string) file_get_contents($eventSvc);
chk('EventService::listInRange present', str_contains($eSrc, 'function listInRange('));
chk('listInRange is a bounded range read', (bool) preg_match('/function listInRange\(.*?starts_at >=.*?starts_at <.*?limit\(/s', $eSrc));
chk('listInRange honours optional group scope', (bool) preg_match("/function listInRange\\(.*?group_id.*?groupId/s", $eSrc));
chk('EventService::feedInRange present', str_contains($eSrc, 'function feedInRange('));
chk('feedInRange only emits published events', (bool) preg_match("/function feedInRange\\(.*?where\\('status', 'published'\\)/s", $eSrc));
chk('feedInRange is a bounded forward read', (bool) preg_match('/function feedInRange\(.*?starts_at >=.*?limit\(/s', $eSrc));

$rSrc = (string) file_get_contents($regSvc);
chk('RegistrationService::myEvents present', str_contains($rSrc, 'function myEvents('));
chk('myEvents batches events via whereIn on a union of ids', (bool) preg_match('/function myEvents\(.*?whereIn\(\'id\'/s', $rSrc));
chk('myEvents splits upcoming vs past', (bool) preg_match("/function myEvents\\(.*?'upcoming'.*?'past'/s", $rSrc));
chk('myEvents scopes to the passed user (never trusts a body param)', (bool) preg_match('/function myEvents\(string \$organizationId, string \$userId/s', $rSrc));

$wire = (string) file_get_contents($servicesF);
chk('Services registers eventAnalytics() factory', (bool) preg_match('/function eventAnalytics\(.*?new AnalyticsService\(/s', $wire));
chk('eventAnalytics uses matching shared key', (bool) preg_match("/function eventAnalytics\\(.*?getSharedInstance\\('eventAnalytics'\\)/s", $wire));
chk('Services registers eventIcsFeed() factory', (bool) preg_match('/function eventIcsFeed\(.*?new IcsFeedService\(/s', $wire));
chk('eventIcsFeed uses matching shared key', (bool) preg_match("/function eventIcsFeed\\(.*?getSharedInstance\\('eventIcsFeed'\\)/s", $wire));
chk('Services registers eventFeedToken() factory', (bool) preg_match('/function eventFeedToken\(.*?new FeedTokenService\(/s', $wire));
chk('eventFeedToken uses matching shared key', (bool) preg_match("/function eventFeedToken\\(.*?getSharedInstance\\('eventFeedToken'\\)/s", $wire));
chk('eventFeedToken reads a rotatable signing key from env', str_contains($wire, 'EVENT_FEED_SIGNING_KEY'));

// FeedTokenService is a pure, storage-free HMAC signer.
$ftSrc = (string) file_get_contents($root . '/app/Modules/Events/Services/FeedTokenService.php');
chk('FeedTokenService::issue + verify present', str_contains($ftSrc, 'function issue(') && str_contains($ftSrc, 'function verify('));
chk('FeedTokenService verifies in constant time', str_contains($ftSrc, 'hash_equals('));
chk('FeedTokenService is storage-free (no DB)', ! str_contains($ftSrc, '->table(') && ! str_contains($ftSrc, 'Database::connect'));

// IcsFeedService is a pure formatter: no DB dependency in its constructor path.
$icsSrc = (string) file_get_contents($root . '/app/Modules/Events/Services/IcsFeedService.php');
chk('IcsFeedService::build present', str_contains($icsSrc, 'function build('));
chk('IcsFeedService is DB-free (no query builder / connect)', ! str_contains($icsSrc, '->table(') && ! str_contains($icsSrc, 'Database::connect'));
chk('IcsFeedService escapes TEXT + folds lines', str_contains($icsSrc, 'function escapeText') && str_contains($icsSrc, 'function fold'));

// ── 4. Controller: three actions render bespoke views + JSON ─────────────────
echo "controller: analytics() / calendar() / mine()\n";
$ctrl = (string) file_get_contents($controller);
chk('analytics() renders Views\\analytics', (bool) preg_match('/function analytics\(.*?Views\\\\analytics/s', $ctrl));
chk('analytics() calls eventAnalytics()->dashboard', (bool) preg_match('/function analytics\(.*?eventAnalytics\(\)->dashboard\(/s', $ctrl));
chk('analytics() accepts optional group_id', (bool) preg_match("/function analytics\\(.*?field\\('group_id'\\)/s", $ctrl));
chk('calendar() renders Views\\calendar', (bool) preg_match('/function calendar\(.*?Views\\\\calendar/s', $ctrl));
chk('calendar() calls listInRange', (bool) preg_match('/function calendar\(.*?listInRange\(/s', $ctrl));
chk('calendar() default month has no stray find() call', ! (bool) preg_match("/function calendar\\(.*?find\\(''\\)/s", $ctrl));
chk('mine() renders Views\\my_events', (bool) preg_match('/function mine\(.*?Views\\\\my_events/s', $ctrl));
chk('mine() scopes to currentUserId', (bool) preg_match('/function mine\(.*?currentUserId\(/s', $ctrl));
chk('mine() calls myEvents', (bool) preg_match('/function mine\(.*?myEvents\(/s', $ctrl));
chk('calendarFeed() returns text/calendar', (bool) preg_match("#function calendarFeed\\(.*?text/calendar#s", $ctrl));
chk('calendarFeed() sets an .ics filename', (bool) preg_match('/function calendarFeed\(.*?events\.ics/s', $ctrl));
chk('calendarFeed() calls feedInRange + the ICS builder', (bool) preg_match('/function calendarFeed\(.*?feedInRange\(.*?eventIcsFeed\(\)->build\(/s', $ctrl));
chk('calendarFeed() honours optional group_id', (bool) preg_match("/function calendarFeed\\(.*?field\\('group_id'\\)/s", $ctrl));
chk('mine() passes a personal feed_url to the view', (bool) preg_match('/function mine\(.*?feed_url/s', $ctrl));
chk('mineFeed() verifies a ?token= (cookie-less)', (bool) preg_match("/function mineFeed\\(.*?field\\('token'.*?eventFeedToken\\(\\)->verify\\(/s", $ctrl));
chk('mineFeed() 403s on a bad/absent token', (bool) preg_match('/function mineFeed\(.*?setStatusCode\(403\)/s', $ctrl));
chk('mineFeed() returns text/calendar via the ICS builder', (bool) preg_match('/function mineFeed\(.*?eventIcsFeed\(\)->build\(.*?text\/calendar/s', $ctrl));
chk('mineFeed() only feeds upcoming events', (bool) preg_match("/function mineFeed\\(.*?\\\$data\\['upcoming'\\]/s", $ctrl));
chk('eventFeed() returns a single-event .ics attachment', (bool) preg_match('/function eventFeed\(.*?attachment; filename="event\.ics"/s', $ctrl));
chk('eventFeed() builds ICS from the found event row', (bool) preg_match('/function eventFeed\(.*?events\(\)->find\(.*?eventIcsFeed\(\)->build\(/s', $ctrl));
chk('eventFeed() 404s an unknown event', (bool) preg_match('/function eventFeed\(.*?setStatusCode\(404\)/s', $ctrl));

// ── 5. Routes: literal reads before the (:segment) show wildcard ─────────────
echo "routes: analytics/calendar/mine placed + guarded\n";
$routes = (string) file_get_contents($routesFile);
chk('GET events/analytics gated by report.view', (bool) preg_match("#get\\('analytics'.*?EventController::analytics.*?report\\.view#s", $routes));
chk('GET events/calendar (public read)', (bool) preg_match("#get\\('calendar'.*?EventController::calendar#", $routes));
chk('GET events/mine auth-guarded', (bool) preg_match("#get\\('mine'.*?EventController::mine.*?auth#s", $routes));
chk('GET events/calendar.ics feed route', (bool) preg_match("#get\\('calendar\\.ics'.*?EventController::calendarFeed#", $routes));
chk('GET events/mine.ics personal feed route (public, token-authorized)', (bool) preg_match("#get\\('mine\\.ics'.*?EventController::mineFeed#", $routes));
chk('mine.ics route carries NO auth filter (cookie-less)', ! (bool) preg_match("#get\\('mine\\.ics'[^\\n]*'auth'#", $routes));
chk('GET events/{id}/event.ics per-event download route', (bool) preg_match("#get\\('\\(:segment\\)/event\\.ics'.*?EventController::eventFeed#", $routes));
// The per-event .ics has a segment param, so it is structurally non-page-like
// (like (:segment)/attendance) and needs no explicit menu-coverage exclusion.
$posEventIcs = strpos($routes, "get('(:segment)/event.ics'");
chk('event.ics route registered', $posEventIcs !== false);
// The .ics feeds are data endpoints, not navigation → recorded as intentional
// menu-coverage exclusions (not MenuItems).
$cov = (string) file_get_contents($root . '/app/Modules/Shared/Navigation/MenuCoverage.php');
chk('calendar.ics is a recorded menu-coverage exclusion', str_contains($cov, "'events/calendar.ics'"));
chk('mine.ics is a recorded menu-coverage exclusion', str_contains($cov, "'events/mine.ics'"));
// The three literals must precede the (:segment) show route so they are not shadowed.
$posAnalytics = strpos($routes, "get('analytics'");
$posCalendar  = strpos($routes, "get('calendar'");
$posFeed      = strpos($routes, "get('calendar.ics'");
$posMine      = strpos($routes, "get('mine'");
$posShow      = strpos($routes, "get('(:segment)', '\\WBS\\Events\\Controllers\\EventController::show");
chk('analytics route precedes show wildcard', $posAnalytics !== false && $posShow !== false && $posAnalytics < $posShow);
chk('calendar route precedes show wildcard', $posCalendar !== false && $posCalendar < $posShow);
chk('calendar.ics route precedes show wildcard', $posFeed !== false && $posFeed < $posShow);
chk('mine route precedes show wildcard', $posMine !== false && $posMine < $posShow);

// ── 6. Views: CSP-clean, self-contained, no-JS ───────────────────────────────
echo "views: self-contained, CSP-clean, no bare English heading\n";
foreach (['analytics', 'calendar', 'my_events'] as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php includes _locale.php", str_contains($src, "_locale.php"));
    chk("$v.php sets <html lang/dir>", str_contains($src, '_shell_open.php'));
    chk("$v.php uses lang('Events.", str_contains($src, "lang('Events."));
    $noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    chk("$v.php CSP-clean: no <script>", ! str_contains($noC, '<script'));
    chk("$v.php CSP-clean: no inline on* handlers", ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', $noC));
    chk("$v.php no bare <h1>Events</h1>", ! str_contains($src, '<h1>Events</h1>'));
}
// show.php gains an "Add to calendar" link, gated to published events and
// pointing at the per-event .ics download.
$showSrc = (string) file_get_contents("$viewDir/show.php");
chk('show.php links to /event.ics', str_contains($showSrc, "/event.ics"));
chk('show.php gates the calendar link to published', (bool) preg_match("/status === 'published'.*?event\\.ics/s", $showSrc));
chk('show.php uses lang for the calendar labels', str_contains($showSrc, "lang('Events.addToCalendar')") && str_contains($showSrc, "lang('Events.downloadIcs')"));

// ── 7. Render smoke (fr, with fallback) ──────────────────────────────────────
echo "render smoke (fr, self-contained)\n";
require __DIR__ . '/view_test_helpers.php';
$render = wbs_events_renderer($langDir);

$aHtml = $render("$viewDir/analytics.php", [
    'group_id' => null, 'now' => '2026-09-14 00:00:00',
    'totals' => ['events' => 12, 'upcoming' => 5, 'past' => 7, 'draft' => 2, 'published' => 8, 'cancelled' => 2, 'attended_events' => 6],
    'people' => ['registrations' => 340, 'attendance' => 210, 'unique_attendees' => 180, 'conversion_pct' => 61.8, 'no_show' => 130, 'waitlisted' => 12],
    'revenue' => ['paid_orders' => 44, 'gross_minor' => 1234500, 'currency' => 'GHS'],
    'by_mode' => [['mode' => 'hybrid', 'count' => 3]],
    'by_status' => [['status' => 'published', 'count' => 8]],
    'upcoming' => [['id' => 'e1', 'title' => 'Conférence', 'starts_at' => '2026-10-01 09:00', 'registered' => 120, 'capacity' => 200, 'fill_pct' => 60.0]],
    'filling' => [['id' => 'e3', 'title' => 'Gala', 'registered' => 95, 'capacity' => 100, 'fill_pct' => 95.0]],
], 'fr');
chk('fr analytics translated heading', str_contains($aHtml, 'Analyse des événements'), substr($aHtml, 0, 120));
chk('fr analytics shows conversion %', str_contains($aHtml, '61.8%'));
chk('fr analytics formats money', str_contains($aHtml, '12,345.00 GHS'));
chk('fr analytics translates mode hybrid → hybride', str_contains($aHtml, 'hybride'));

$aEmpty = $render("$viewDir/analytics.php", ['totals' => ['events' => 0]], 'fr');
chk('fr analytics empty state', str_contains($aEmpty, (string) (require $langDir . '/fr/Events.php')['analytics']['empty']));

$cHtml = $render("$viewDir/calendar.php", [
    'group_id' => 'grp-9', 'year' => 2026, 'month' => 9,
    'events' => [
        ['id' => 'e1', 'title' => 'Prière', 'status' => 'published', 'starts_at' => '2026-09-14 06:30:00'],
        ['id' => 'e2', 'title' => 'A', 'status' => 'draft', 'starts_at' => '2026-09-14 18:00:00'],
        ['id' => 'e3', 'title' => 'B', 'status' => 'published', 'starts_at' => '2026-09-14 19:00:00'],
        ['id' => 'e4', 'title' => 'C', 'status' => 'published', 'starts_at' => '2026-09-14 20:00:00'],
    ],
], 'fr');
chk('fr calendar translated heading', str_contains($cHtml, 'Calendrier des événements'));
chk('fr calendar shows French month label', str_contains($cHtml, 'septembre 2026'));
chk('fr calendar group scope line', str_contains($cHtml, 'Groupe grp-9'));
chk('fr calendar prev/next month links', str_contains($cHtml, 'month=2026-08') && str_contains($cHtml, 'month=2026-10'));
chk('fr calendar overflow chip (+1 de plus)', str_contains($cHtml, '+1'));

$cRoll = $render("$viewDir/calendar.php", ['group_id' => null, 'year' => 2026, 'month' => 12, 'events' => []], 'fr');
chk('fr calendar Dec→Jan year rollover', str_contains($cRoll, 'month=2027-01'));
chk('fr calendar has an .ics subscribe link', str_contains($cHtml, '/events/calendar.ics'));
chk('fr calendar empty state', str_contains($cRoll, (string) (require $langDir . '/fr/Events.php')['calendar']['empty']));

$mHtml = $render("$viewDir/my_events.php", [
    'user_id' => 'u1',
    'counts' => ['upcoming' => 2, 'past' => 1, 'attended' => 1, 'certificates' => 1, 'tickets' => 2],
    'upcoming' => [['id' => 'e1', 'title' => 'Conférence', 'status' => 'published', 'mode' => 'hybrid', 'starts_at' => '2026-10-01 09:00', 'my_registration' => 'registered', 'attended' => false, 'certificate' => null, 'tickets' => 2]],
    'past' => [['id' => 'e3', 'title' => 'Retraite', 'status' => 'completed', 'mode' => 'physical', 'starts_at' => '2026-08-01 09:00', 'my_registration' => 'registered', 'attended' => true, 'certificate' => ['verification_id' => 'VID123', 'status' => 'issued'], 'tickets' => 0]],
    'feed_url' => 'https://public.test/events/mine.ics?token=abc.def',
], 'fr');
chk('fr my_events translated heading', str_contains($mHtml, 'Mes événements'));
chk('fr my_events certificate deep-links to verify route', str_contains($mHtml, '/certificates/verify/VID123'));
chk('fr my_events shows ticket count', str_contains($mHtml, '2 billet(s)'));
chk('fr my_events attended tag', str_contains($mHtml, 'Présent'));
chk('fr my_events shows personal subscribe link', str_contains($mHtml, 'https://public.test/events/mine.ics?token=abc.def'));
chk('my_events hides subscribe link when no feed_url', ! str_contains($mEmptyNoFeed = $render("$viewDir/my_events.php", ['user_id' => 'u1', 'counts' => [], 'upcoming' => [], 'past' => []], 'fr'), 'mine.ics'));

$mEmpty = $render("$viewDir/my_events.php", ['user_id' => 'u1', 'counts' => [], 'upcoming' => [], 'past' => []], 'fr');
chk('fr my_events empty upcoming state', str_contains($mEmpty, (string) (require $langDir . '/fr/Events.php')['myEvents']['emptyUpcoming']));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
