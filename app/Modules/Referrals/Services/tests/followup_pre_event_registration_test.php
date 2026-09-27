<?php

declare(strict_types=1);

/**
 * FOLLOW-UP → PRE-EVENT REGISTRATION integration test.
 *
 * The standing constraint is that follow-ups (by staff, leaders and members)
 * ALSO manage the contact's event/course attendance registrations, reusing the
 * Events/Courses tables — no fork. This makes follow-up registrations FIRST-CLASS
 * in the pre-event phase: they flow through the platform's OWN registrar /
 * enroller (so the published-only + atomic-capacity + waitlist gates apply and
 * the event's change/cancel/reminder notifications pick them up), while
 * preserving the outreach provenance (source_ref=contact:{id}) and the
 * follow-up-touch bump.
 *
 * This test drives ContactBookService::recordAttendance() with in-memory ports +
 * a tiny fake DB (so no live database is needed) and asserts:
 *   - event registration is ROUTED through the registrar port (gates apply),
 *   - a failed gate (e.g. not published) is NOT recorded as a follow-up touch,
 *   - a full event's WAITLISTED result is surfaced to the follower,
 *   - course enrolment is routed through the enroller port,
 *   - provenance (source_ref=contact:{id}) is passed to the registrar,
 *   - the factory wires the real Events/Courses adapters,
 *   - the analytics dashboard exposes the follow-up-sourced KPI + i18n parity.
 *
 *   php app/Modules/Referrals/Services/tests/followup_pre_event_registration_test.php
 */

namespace CodeIgniter\Database {
    // Minimal fake connection: recordAttendance only reads `prospects` (owned +
    // ensureContactUser) and writes `prospects` (the follow-up touch). We seed a
    // single contact row and capture the prospect updates.
    class BaseConnection
    {
        public array $prospectUpdates = [];
        public array $contactRow      = [];
        private string $table         = '';

        public function table(string $t): static { $this->table = $t; return $this; }
        public function where($k, $v = null): static { return $this; }

        public function get($limit = null): object
        {
            $row = $this->table === 'prospects' ? $this->contactRow : null;

            return new class($row) {
                public function __construct(private $row) {}
                public function getRowArray() { return $this->row; }
                public function getResultArray() { return $this->row !== null ? [$this->row] : []; }
            };
        }

        public function update(array $data): bool
        {
            if ($this->table === 'prospects') { $this->prospectUpdates[] = $data; }

            return true;
        }

        public function insert(array $data): bool { return true; }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Referrals/Services/EventRegistrarPort.php';
require_once $root . '/app/Modules/Referrals/Services/CourseEnrollerPort.php';
require_once $root . '/app/Modules/Referrals/Services/ContactBookService.php';

use WBS\Referrals\Services\ContactBookService;
use WBS\Referrals\Services\CourseEnrollerPort;
use WBS\Referrals\Services\EventRegistrarPort;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;

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

// ── Fake DB seeded with one owned contact (linked user already present) ───────
$fakeDb = new \CodeIgniter\Database\BaseConnection();
$fakeDb->contactRow = [
    'id' => 'c-1', 'organization_id' => 'org-1', 'owner_user_id' => 'owner-1',
    'assigned_group_id' => 'grp-legon', 'linked_user_id' => 'u-1',
    'follow_up_count' => 2, 'email' => 'kofi@example.test', 'full_name' => 'Kofi',
];

// ── Spy ports ────────────────────────────────────────────────────────────────
$registrarSpy = new class implements EventRegistrarPort {
    public array $calls = [];
    public Result $next;
    public function __construct() { $this->next = Result::created(['registration_id' => 'reg-1', 'status' => 'registered']); }
    public function register(string $organizationId, string $eventId, string $userId, array $opts = []): Result
    {
        $this->calls[] = compact('organizationId', 'eventId', 'userId', 'opts');
        return $this->next;
    }
};
$enrollerSpy = new class implements CourseEnrollerPort {
    public array $calls = [];
    public function enroll(string $organizationId, string $courseId, string $userId, ?string $cohortId = null): Result
    {
        $this->calls[] = compact('organizationId', 'courseId', 'userId', 'cohortId');
        return Result::created(['enrollment_id' => 'enr-1', 'status' => 'active']);
    }
};

Clock::freeze(new DateTimeImmutable('2026-09-14 00:00:00', new DateTimeZone('UTC')));
$svc = new ContactBookService($fakeDb, new Clock(), null, null, null, null, $registrarSpy, $enrollerSpy);

// ── 1. Event registration is ROUTED through the registrar (gates apply) ───────
echo "event registration routes through the platform registrar\n";
$registrarSpy->calls = [];
$fakeDb->prospectUpdates = [];
$res = $svc->recordAttendance('c-1', 'owner-1', ['type' => 'event', 'target_id' => 'ev-1', 'rsvp_state' => 'yes']);
chk('recordAttendance succeeds', $res->ok, $res->message ?? '');
chk('the registrar port was called (not a direct write)', count($registrarSpy->calls) === 1);
chk('registrar got org + event + linked user', $registrarSpy->calls[0]['organizationId'] === 'org-1'
    && $registrarSpy->calls[0]['eventId'] === 'ev-1' && $registrarSpy->calls[0]['userId'] === 'u-1');
chk('provenance source_ref=contact:{id} is passed', ($registrarSpy->calls[0]['opts']['source_ref'] ?? '') === 'contact:c-1');
chk('group attribution falls back to the contact assigned group', ($registrarSpy->calls[0]['opts']['group_attribution'] ?? '') === 'grp-legon');
chk('rsvp_state forwarded', ($registrarSpy->calls[0]['opts']['rsvp_state'] ?? '') === 'yes');
chk('a successful registration bumps the follow-up touch', count($fakeDb->prospectUpdates) === 1
    && ($fakeDb->prospectUpdates[0]['follow_up_count'] ?? 0) === 3);
chk('result carries the roster status', ($res->data['status'] ?? '') === 'registered');

// ── 2. A failed pre-event gate is NOT a follow-up touch ──────────────────────
echo "a failed pre-event gate is surfaced, not silently recorded\n";
$registrarSpy->next = Result::fail('NOT_OPEN', 'event.not_open', 409, ['status' => 'draft']);
$fakeDb->prospectUpdates = [];
$res2 = $svc->recordAttendance('c-1', 'owner-1', ['type' => 'event', 'target_id' => 'ev-draft']);
chk('a draft/closed event registration FAILS', ! $res2->ok && $res2->code === 'NOT_OPEN');
chk('a failed registration does NOT bump the follow-up count', $fakeDb->prospectUpdates === []);

// ── 3. A full event's WAITLISTED result is surfaced ──────────────────────────
echo "a full event waitlists the contact (surfaced to the follower)\n";
$registrarSpy->next = Result::created(['registration_id' => 'reg-2', 'status' => 'waitlisted']);
$fakeDb->prospectUpdates = [];
$res3 = $svc->recordAttendance('c-1', 'owner-1', ['type' => 'event', 'target_id' => 'ev-full']);
chk('waitlisted registration still succeeds', $res3->ok);
chk('the waitlisted status is surfaced', ($res3->data['status'] ?? '') === 'waitlisted');
chk('a waitlisted registration IS a follow-up touch', ($fakeDb->prospectUpdates[0]['follow_up_count'] ?? 0) === 3);

// ── 4. Course enrolment routes through the enroller ──────────────────────────
echo "course enrolment routes through the platform enroller\n";
$enrollerSpy->calls = [];
$res4 = $svc->recordAttendance('c-1', 'owner-1', ['type' => 'course', 'target_id' => 'crs-1', 'cohort_id' => 'coh-1']);
chk('course enrolment succeeds', $res4->ok);
chk('the enroller port was called', count($enrollerSpy->calls) === 1
    && $enrollerSpy->calls[0]['courseId'] === 'crs-1' && $enrollerSpy->calls[0]['cohortId'] === 'coh-1');
chk('course result type is course', ($res4->data['type'] ?? '') === 'course');

// ── 5. Bad input still validated up-front ────────────────────────────────────
echo "input validation preserved\n";
chk('unknown attendance type rejected', $svc->recordAttendance('c-1', 'owner-1', ['type' => 'x', 'target_id' => 't'])->code === 'BAD_ATTENDANCE_TYPE');
chk('missing target rejected', $svc->recordAttendance('c-1', 'owner-1', ['type' => 'event'])->code === 'TARGET_REQUIRED');

// ── 6. Source wiring: service + factory + analytics ──────────────────────────
echo "source wiring (service ctor + factory + analytics)\n";
$svcSrc = (string) file_get_contents($root . '/app/Modules/Referrals/Services/ContactBookService.php');
chk('ctor accepts an EventRegistrarPort', str_contains($svcSrc, '?EventRegistrarPort $eventRegistrar'));
chk('ctor accepts a CourseEnrollerPort', str_contains($svcSrc, '?CourseEnrollerPort $courseEnroller'));
chk('recordAttendance delegates to registerForEvent', (bool) preg_match('/recordAttendance\(.*?registerForEvent\(/s', $svcSrc));
chk('registerForEvent prefers the registrar port', (bool) preg_match('/registerForEvent\(.*?eventRegistrar !== null.*?->register\(/s', $svcSrc));
chk('a failed registrar result short-circuits the touch', (bool) preg_match('/if \(! \$result->ok\) \{\s*return \$result;/s', $svcSrc));

$fac = (string) file_get_contents($root . '/app/Modules/Referrals/Config/Services.php');
chk('factory wires EventRegistrarAdapter from Events registrations', str_contains($fac, 'new EventRegistrarAdapter(EventServices::eventRegistrations())'));
chk('factory wires CourseEnrollerAdapter from Courses enrollments', str_contains($fac, 'new CourseEnrollerAdapter(CourseServices::enrollments())'));

$an = (string) file_get_contents($root . '/app/Modules/Events/Services/AnalyticsService.php');
chk('analytics counts follow-up-sourced registrations', str_contains($an, "like('source_ref', 'contact:', 'after')"));
chk('analytics exposes follow_up_sourced in people metrics', str_contains($an, "'follow_up_sourced' => \$followUpSourced"));
chk('analytics view renders the KPI', str_contains((string) file_get_contents($root . '/app/Modules/Events/Views/analytics.php'), "people['follow_up_sourced']"));

// ── 7. i18n parity for the new analytics KPI key ─────────────────────────────
echo "i18n parity (kFollowUpSourced, all locales)\n";
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr = require $root . "/app/Modules/Events/Language/$loc/Events.php";
    $keys = $flat($arr['analytics'] ?? []);
    chk("$loc has analytics.kFollowUpSourced", in_array('kFollowUpSourced', $keys, true));
}

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
