<?php

declare(strict_types=1);

/**
 * CourseEnrollerAdapter — the PRODUCTION port that forwards a follow-up course
 * enrolment into Courses\EnrollmentService::enroll().
 *
 * Regression for a TypeError shipped to production:
 *   EnrollmentService::enroll()'s 4th parameter is a typed `array $opts`, but the
 *   adapter forwarded the port's nullable `?string $cohortId` straight into it —
 *   so a follow-up with NO cohort passed `null` and threw
 *   "Argument #4 ($opts) must be of type array, null given".
 *
 * The existing follow-up tests only used an in-memory Port spy, so they never
 * exercised the real adapter's translation — this drives the REAL adapter
 * against the REAL EnrollmentService over a fake DB. Proves:
 *   • enrol with NO cohort no longer throws and reaches the service (the crash);
 *   • a cohort id is forwarded as $opts['cohort_id'] and persisted;
 *   • follow-up enrolments are marked assisted → invite-only courses admit them
 *     (G5: leader/staff/follow-up-assisted is a valid invite source);
 *   • idempotency still round-trips (existing enrolment de-duplicates).
 *
 *   php app/Modules/Referrals/Services/tests/course_enroller_adapter_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows) {}
        public function getRowArray() { return $this->rows[0] ?? null; }
        public function getResultArray() { return $this->rows; }
    }

    class QB
    {
        private array $eq = [];
        private array $in = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($f, $e = null) { return $this; }
        public function where($k, $v = null) { $this->eq[trim((string) $k)] = $v; return $this; }
        public function whereIn($k, array $v) { $this->in[trim((string) $k)] = array_map('strval', $v); return $this; }
        public function orderBy($k, $d = 'ASC') { return $this; }

        public function get(): RS { return new RS($this->filtered()); }

        public function insert(array $row): bool { $this->db->rows[$this->t][] = $row; return true; }

        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) { $this->db->rows[$this->t][$i] = array_merge($r, $set); }
            }
            return true;
        }

        private function filtered(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                $rv = $r[$k] ?? null;
                if ($v === null) { if ($rv !== null) { return false; } continue; }
                if ((string) $rv !== (string) $v) { return false; }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) { return false; }
            }
            return true;
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Shared/Messaging/OutboxService.php';
require_once $root . '/app/Modules/Courses/Services/EnrollmentService.php';
require_once $root . '/app/Modules/Referrals/Services/CourseEnrollerPort.php';
require_once $root . '/app/Modules/Referrals/Services/CourseEnrollerAdapter.php';

use WBS\Courses\Services\EnrollmentService;
use WBS\Referrals\Services\CourseEnrollerAdapter;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

Clock::freeze(new DateTimeImmutable('2026-09-20 00:00:00', new DateTimeZone('UTC')));

/** A no-op OutboxService double (enroll() never publishes, but the ctor needs one). */
$outbox = (new ReflectionClass(\WBS\Shared\Messaging\OutboxService::class))->newInstanceWithoutConstructor();

$org = 'org-1';

$make = static function (string $policy) use ($outbox): array {
    $db = new \CodeIgniter\Database\BaseConnection();
    $db->rows['courses'] = [[
        'id' => 'crs-1', 'organization_id' => 'org-1', 'status' => 'published',
        'enrollment_policy' => $policy, 'prerequisites' => null,
    ]];
    $db->rows['enrollments'] = [];
    $svc = new EnrollmentService($db, new Clock(), $outbox);
    return [$db, new CourseEnrollerAdapter($svc)];
};

echo "1. enrol with NO cohort no longer throws (the production TypeError)\n";
[$db1, $adapter1] = $make('open');
$threw = false;
$res1 = null;
try {
    $res1 = $adapter1->enroll($org, 'crs-1', 'user-1', null);
} catch (\TypeError $e) {
    $threw = true;
}
chk('no TypeError on null cohort', $threw === false);
chk('enrolment created', $res1 !== null && $res1->ok);
chk('row persisted with null cohort', array_key_exists('cohort_id', $db1->rows['enrollments'][0])
    && $db1->rows['enrollments'][0]['cohort_id'] === null);
chk('status active for open policy', ($db1->rows['enrollments'][0]['status'] ?? '') === 'active');

echo "2. cohort id is forwarded as opts['cohort_id'] and persisted\n";
[$db2, $adapter2] = $make('open');
$res2 = $adapter2->enroll($org, 'crs-1', 'user-2', 'coh-9');
chk('enrolment created', $res2->ok);
chk('cohort persisted', ($db2->rows['enrollments'][0]['cohort_id'] ?? null) === 'coh-9');

echo "3. follow-up enrolment is assisted → invite-only course admits it (G5)\n";
[$db3, $adapter3] = $make('invite');
$res3 = $adapter3->enroll($org, 'crs-1', 'user-3', null);
chk('invite-only admits assisted follow-up', $res3->ok, (string) $res3->code);
chk('status active', ($db3->rows['enrollments'][0]['status'] ?? '') === 'active');

echo "4. idempotency round-trips (existing enrolment de-duplicates)\n";
[$db4, $adapter4] = $make('open');
$adapter4->enroll($org, 'crs-1', 'user-4', null);
$again = $adapter4->enroll($org, 'crs-1', 'user-4', null);
chk('second call de-duplicated', ($again->meta['deduplicated'] ?? false) === true);
chk('only one enrolment row', count($db4->rows['enrollments']) === 1, (string) count($db4->rows['enrollments']));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
