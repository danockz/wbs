<?php

declare(strict_types=1);

/**
 * EnrollmentService teardown + merge consumers test (Theme B — CO6).
 *
 * Over an in-memory DB fake, proves:
 *
 *  withdrawActiveForSubject (deactivate/suspend/anonymize):
 *   - the subject's in-flight (pending/active) enrollments -> withdrawn (with a
 *     withdrawn_at stamp), other learners' rows untouched;
 *   - a `completed` enrollment (historical achievement) is NOT touched;
 *   - idempotent (re-run withdraws 0); empty inputs -> 0.
 *
 *  reassignForMerge (person merge):
 *   - a loser enrollment in a course the survivor is NOT in -> re-pointed to the
 *     survivor, and its course_completion follows;
 *   - a loser enrollment DUPLICATING a survivor course -> loser row withdrawn
 *     (survivor's kept), honouring UNIQUE(course_id,user_id);
 *   - loser == survivor / empty inputs -> all-zero no-op; idempotent re-run.
 *
 *  archived-course completion guard:
 *   - evaluateCompletion / overrideCompletion refuse to MINT a new completion on
 *     an archived course (already-recorded completions are still honoured).
 *
 *   php app/Modules/Courses/Services/tests/enrollment_teardown_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function affectedRows(): int
        {
            return $this->affected;
        }

        public function transStart(): void
        {
        }

        public function transComplete(): void
        {
        }

        public function transStatus(): bool
        {
            return true;
        }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows)
        {
        }

        public function getRowArray()
        {
            return $this->rows[0] ?? null;
        }

        public function getResultArray()
        {
            return $this->rows;
        }
    }

    class QB
    {
        private array $eq = [];
        private array $in = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function get(): RS
        {
            return new RS($this->matchingRows());
        }

        public function countAllResults(): int
        {
            return count($this->matchingRows());
        }

        public function update(array $set): bool
        {
            $n = 0;
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                    $n++;
                }
            }
            $this->db->affected = $n;

            return true;
        }

        private function matchingRows(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace WBS\Shared\Messaging {
    class OutboxService
    {
        /** @var list<array<string,mixed>> */
        public array $staged = [];

        public function stage(string $aggregateType, string $aggregateId, string $topic, array $payload, ?string $organizationId = null, array $headers = []): string
        {
            $this->staged[] = ['topic' => $topic, 'payload' => $payload];

            return 'outbox-1';
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Courses\Services\EnrollmentService;
    use WBS\Shared\Messaging\OutboxService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Courses/Services/EnrollmentService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';

    $enrById = static function (BaseConnection $db, string $id): ?array {
        foreach ($db->rows['enrollments'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    };

    // ======================================================================
    // withdrawActiveForSubject
    // ======================================================================
    $db = new BaseConnection();
    $db->rows['enrollments'] = [
        ['id' => 'e1', 'organization_id' => $ORG, 'course_id' => 'c1', 'user_id' => 'gone', 'status' => 'active', 'withdrawn_at' => null],
        ['id' => 'e2', 'organization_id' => $ORG, 'course_id' => 'c2', 'user_id' => 'gone', 'status' => 'pending', 'withdrawn_at' => null],
        ['id' => 'e3', 'organization_id' => $ORG, 'course_id' => 'c3', 'user_id' => 'gone', 'status' => 'completed', 'withdrawn_at' => null],
        ['id' => 'e4', 'organization_id' => $ORG, 'course_id' => 'c1', 'user_id' => 'other', 'status' => 'active', 'withdrawn_at' => null],
    ];
    $svc = new EnrollmentService($db, new Clock(), new OutboxService());

    $n = $svc->withdrawActiveForSubject($ORG, 'gone', 'account.deactivated');
    $chk('withdraws 2 in-flight enrollments', $n === 2, (string) $n);
    $chk('active e1 -> withdrawn', $enrById($db, 'e1')['status'] === 'withdrawn');
    $chk('pending e2 -> withdrawn', $enrById($db, 'e2')['status'] === 'withdrawn');
    $chk('withdrawn_at stamped', $enrById($db, 'e1')['withdrawn_at'] !== null);
    $chk('completed e3 untouched (historical)', $enrById($db, 'e3')['status'] === 'completed');
    $chk('other learner e4 untouched', $enrById($db, 'e4')['status'] === 'active');
    $chk('withdraw idempotent (re-run 0)', $svc->withdrawActiveForSubject($ORG, 'gone', 'x') === 0);
    $chk('withdraw empty org -> 0', $svc->withdrawActiveForSubject('', 'gone', 'x') === 0);
    $chk('withdraw empty subject -> 0', $svc->withdrawActiveForSubject($ORG, '', 'x') === 0);

    // ======================================================================
    // reassignForMerge
    // ======================================================================
    $db = new BaseConnection();
    $db->rows['enrollments'] = [
        // loser: c1 (unique), c2 (DUP - survivor already in c2), c3 completed.
        ['id' => 'le1', 'organization_id' => $ORG, 'course_id' => 'c1', 'user_id' => 'loser', 'status' => 'active', 'withdrawn_at' => null],
        ['id' => 'le2', 'organization_id' => $ORG, 'course_id' => 'c2', 'user_id' => 'loser', 'status' => 'active', 'withdrawn_at' => null],
        ['id' => 'le3', 'organization_id' => $ORG, 'course_id' => 'c3', 'user_id' => 'loser', 'status' => 'completed', 'withdrawn_at' => null],
        // survivor: already in c2.
        ['id' => 'se1', 'organization_id' => $ORG, 'course_id' => 'c2', 'user_id' => 'survivor', 'status' => 'active', 'withdrawn_at' => null],
        // unrelated learner.
        ['id' => 'ze1', 'organization_id' => $ORG, 'course_id' => 'c1', 'user_id' => 'z', 'status' => 'active', 'withdrawn_at' => null],
    ];
    $db->rows['course_completions'] = [
        ['id' => 'cc3', 'organization_id' => $ORG, 'course_id' => 'c3', 'enrollment_id' => 'le3', 'user_id' => 'loser'],
    ];
    $svc = new EnrollmentService($db, new Clock(), new OutboxService());

    $res = $svc->reassignForMerge($ORG, 'loser', 'survivor');
    $chk('merge repoints 2 non-dup enrollments (c1,c3)', $res['enrollments_repointed'] === 2, json_encode($res));
    $chk('merge withdraws 1 duplicate (c2)', $res['duplicates_withdrawn'] === 1, json_encode($res));
    $chk('merge repoints 1 completion (c3)', $res['completions_repointed'] === 1, json_encode($res));

    $chk('le1 -> survivor', $enrById($db, 'le1')['user_id'] === 'survivor');
    $chk('le3 (completed) -> survivor', $enrById($db, 'le3')['user_id'] === 'survivor');
    $chk('le3 stays completed', $enrById($db, 'le3')['status'] === 'completed');
    $chk('duplicate le2 withdrawn (loser row superseded)', $enrById($db, 'le2')['status'] === 'withdrawn'
        && $enrById($db, 'le2')['user_id'] === 'loser');
    $chk('survivor se1 kept', $enrById($db, 'se1')['status'] === 'active');
    $chk('unrelated ze1 untouched', $enrById($db, 'ze1')['user_id'] === 'z');

    $ccUser = null;
    foreach ($db->rows['course_completions'] as $r) {
        if ($r['id'] === 'cc3') {
            $ccUser = $r['user_id'];
        }
    }
    $chk('completion cc3 -> survivor', $ccUser === 'survivor');

    // idempotent re-run.
    $res2 = $svc->reassignForMerge($ORG, 'loser', 'survivor');
    $chk('merge idempotent: re-run repoints 0', $res2['enrollments_repointed'] === 0, json_encode($res2));

    // guards.
    $zero = ['enrollments_repointed' => 0, 'duplicates_withdrawn' => 0, 'completions_repointed' => 0];
    $chk('merge loser==survivor -> no-op', $svc->reassignForMerge($ORG, 'x', 'x') === $zero);
    $chk('merge empty loser -> no-op', $svc->reassignForMerge($ORG, '', 'survivor') === $zero);
    $chk('merge empty survivor -> no-op', $svc->reassignForMerge($ORG, 'loser', '') === $zero);

    // ======================================================================
    // archived-course completion guard
    // ======================================================================
    $db = new BaseConnection();
    $db->rows['enrollments'] = [
        ['id' => 'ae1', 'organization_id' => $ORG, 'course_id' => 'arch', 'user_id' => 'u1', 'status' => 'active', 'withdrawn_at' => null],
    ];
    $db->rows['courses'] = [
        ['id' => 'arch', 'organization_id' => $ORG, 'status' => 'archived', 'completion_rule' => null, 'slug' => 'a', 'group_id' => null, 'points_rule_code' => null, 'badge_code' => null],
    ];
    $db->rows['course_completions'] = [];
    $db->rows['lessons'] = [];
    $db->rows['learning_progress'] = [];
    $svc = new EnrollmentService($db, new Clock(), new OutboxService());

    $r = $svc->evaluateCompletion('ae1');
    $chk('evaluateCompletion on archived course -> fail', $r->ok === false);
    $chk('archived evaluate error code', ($r->code ?? '') === 'COURSE_ARCHIVED', (string) ($r->code ?? ''));
    $chk('archived evaluate mints NO completion', count($db->rows['course_completions']) === 0);

    $r2 = $svc->overrideCompletion('ae1', 'reviewer-1', 'special case');
    $chk('overrideCompletion on archived course -> fail', $r2->ok === false);
    $chk('archived override error code', ($r2->code ?? '') === 'COURSE_ARCHIVED');
    $chk('archived override mints NO completion', count($db->rows['course_completions']) === 0);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
