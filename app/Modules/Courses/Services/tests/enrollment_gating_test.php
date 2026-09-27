<?php

declare(strict_types=1);

/**
 * Enrollment gating + ownership test (Phase 0: gaps CO1 + CO2).
 *
 * CO1 — completeLesson IDOR: the authenticated caller must OWN the enrollment;
 *       a non-owner (or missing acting id) is denied before any progress write.
 * CO2 — enroll fail-closed gating: the course must be published + in-org;
 *       prerequisites must be met; enrollment_policy decides the state
 *       (open->active, approval->pending, invite->denied unless assisted).
 *       approveEnrollment advances a pending row to active.
 *
 * Uses a tiny in-memory fake of the CI4 query builder (no DB, no framework).
 *
 *   php app/Modules/Courses/Services/tests/enrollment_gating_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public bool $txOpen = false;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function transStart(): void
        {
            $this->txOpen = true;
        }

        public function transComplete(): void
        {
            $this->txOpen = false;
        }

        public function transStatus(): bool
        {
            return true;
        }

        public function query(string $sql, array $binds = []): \Fake\RS
        {
            // Only shape used: AVG(score) over completed progress for one enrollment.
            $enrId = $binds[0] ?? null;
            $scores = [];
            foreach ($this->rows['learning_progress'] ?? [] as $r) {
                if (($r['enrollment_id'] ?? null) === $enrId && ($r['state'] ?? null) === 'completed' && isset($r['score']) && $r['score'] !== null) {
                    $scores[] = (float) $r['score'];
                }
            }
            $avg = $scores === [] ? null : array_sum($scores) / count($scores);

            return new \Fake\RS([['avg_score' => $avg]]);
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
            $this->in[trim((string) $k)] = $vals;

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

        public function insert(array $row): bool
        {
            // Emulate UNIQUE(course_id,user_id) on enrollments.
            if ($this->t === 'enrollments') {
                foreach ($this->db->rows['enrollments'] ?? [] as $r) {
                    if (($r['course_id'] ?? null) === ($row['course_id'] ?? null)
                        && ($r['user_id'] ?? null) === ($row['user_id'] ?? null)) {
                        throw new \RuntimeException('duplicate');
                    }
                }
            }
            $this->db->rows[$this->t][] = $row;

            return true;
        }

        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        private function matchingRows(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array($r[$k] ?? null, $vals, true)) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace WBS\Shared\Messaging {
    // Minimal outbox spy.
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

    // ---- harness ------------------------------------------------------------
    $passed = 0;
    $failed = 0;
    function chk(string $label, bool $cond): void
    {
        global $passed, $failed;
        if ($cond) {
            $passed++;
            // echo "  ok  {$label}\n";
        } else {
            $failed++;
            echo "  FAIL {$label}\n";
        }
    }

    $clock = new Clock();

    // Seed a fresh DB per scenario.
    $mkDb = static function (array $courses): BaseConnection {
        $db = new BaseConnection();
        $db->rows['courses'] = $courses;
        $db->rows['enrollments'] = [];
        $db->rows['learning_progress'] = [];
        $db->rows['course_completions'] = [];
        $db->rows['lessons'] = [];

        return $db;
    };

    $ORG = 'org-1';
    $publishedOpen = [
        'id' => 'c-open', 'organization_id' => $ORG, 'status' => 'published',
        'enrollment_policy' => 'open', 'prerequisites' => null, 'completion_rule' => null,
        'points_rule_code' => null, 'badge_code' => null, 'group_id' => null, 'slug' => 'open',
    ];

    // ===== CO2: course status gate ==========================================
    $draft = ['id' => 'c-draft', 'organization_id' => $ORG, 'status' => 'draft', 'enrollment_policy' => 'open', 'prerequisites' => null];
    $db = $mkDb(['x' => $draft] + [0 => $draft]);
    $db->rows['courses'] = [$draft];
    $svc = new EnrollmentService($db, $clock, new OutboxService());
    $r = $svc->enroll($ORG, 'c-draft', 'u-1');
    chk('CO2 draft course rejected', ! $r->ok && $r->code === 'COURSE_NOT_PUBLISHED');

    // wrong org
    $db = $mkDb([$publishedOpen]);
    $svc = new EnrollmentService($db, $clock, new OutboxService());
    $r = $svc->enroll('other-org', 'c-open', 'u-1');
    chk('CO2 wrong-org course not found', ! $r->ok && $r->code === 'COURSE_NOT_FOUND');

    // ===== CO2: open policy -> active =======================================
    $db = $mkDb([$publishedOpen]);
    $svc = new EnrollmentService($db, $clock, new OutboxService());
    $r = $svc->enroll($ORG, 'c-open', 'u-1');
    chk('CO2 open policy enrolls active', $r->ok && ($r->data['status'] ?? '') === 'active');
    chk('CO2 open policy persists one enrollment', count($db->rows['enrollments']) === 1);

    // dedup on second enroll
    $r2 = $svc->enroll($ORG, 'c-open', 'u-1');
    chk('CO2 enroll idempotent (dedup)', $r2->ok && ($r2->meta['deduplicated'] ?? false) === true);
    chk('CO2 enroll idempotent no dup row', count($db->rows['enrollments']) === 1);

    // ===== CO2: approval policy -> pending, then approve ====================
    $approval = ['id' => 'c-appr', 'organization_id' => $ORG, 'status' => 'published', 'enrollment_policy' => 'approval', 'prerequisites' => null];
    $db = $mkDb([$approval]);
    $svc = new EnrollmentService($db, $clock, new OutboxService());
    $r = $svc->enroll($ORG, 'c-appr', 'u-1');
    chk('CO2 approval policy -> pending', $r->ok && ($r->data['status'] ?? '') === 'pending');
    $enrId = $r->data['enrollment_id'];
    $ra = $svc->approveEnrollment($enrId, 'leader-1');
    chk('CO2 approveEnrollment -> active', $ra->ok && ($ra->data['status'] ?? '') === 'active');

    // ===== CO2: invite policy fail-closed unless assisted ===================
    $invite = ['id' => 'c-inv', 'organization_id' => $ORG, 'status' => 'published', 'enrollment_policy' => 'invite', 'prerequisites' => null];
    $db = $mkDb([$invite]);
    $svc = new EnrollmentService($db, $clock, new OutboxService());
    $r = $svc->enroll($ORG, 'c-inv', 'u-1');
    chk('CO2 invite self-service denied', ! $r->ok && $r->code === 'ENROLLMENT_INVITE_ONLY');
    chk('CO2 invite denied writes no row', count($db->rows['enrollments']) === 0);
    $r = $svc->enroll($ORG, 'c-inv', 'u-1', ['assisted' => true]);
    chk('CO2 invite assisted enrolls active', $r->ok && ($r->data['status'] ?? '') === 'active');

    // ===== CO2: prerequisites ===============================================
    $withPrereq = ['id' => 'c-adv', 'organization_id' => $ORG, 'status' => 'published', 'enrollment_policy' => 'open', 'prerequisites' => json_encode(['c-basic'])];
    $db = $mkDb([$withPrereq]);
    $svc = new EnrollmentService($db, $clock, new OutboxService());
    $r = $svc->enroll($ORG, 'c-adv', 'u-1');
    chk('CO2 prereq not met rejected', ! $r->ok && $r->code === 'PREREQUISITES_NOT_MET');
    // now give the user a completed prereq enrollment
    $db->rows['enrollments'][] = ['id' => 'e-basic', 'course_id' => 'c-basic', 'user_id' => 'u-1', 'status' => 'completed'];
    $r = $svc->enroll($ORG, 'c-adv', 'u-1');
    chk('CO2 prereq met enrolls', $r->ok && ($r->data['status'] ?? '') === 'active');

    // ===== CO1: completeLesson ownership ====================================
    $db = $mkDb([$publishedOpen]);
    $svc = new EnrollmentService($db, $clock, new OutboxService());
    $svc->enroll($ORG, 'c-open', 'owner');
    $ownEnr = $db->rows['enrollments'][0]['id'];

    // a different authenticated user tries to complete a lesson on owner's enrollment
    $r = $svc->completeLesson($ownEnr, 'lesson-1', 'attacker', 90);
    chk('CO1 non-owner denied', ! $r->ok && $r->code === 'ENROLLMENT_FORBIDDEN');
    chk('CO1 non-owner writes no progress', count($db->rows['learning_progress']) === 0);

    // missing acting id denied
    $r = $svc->completeLesson($ownEnr, 'lesson-1', null, 90);
    chk('CO1 null acting id denied', ! $r->ok && $r->code === 'ENROLLMENT_FORBIDDEN');

    // unknown enrollment -> not found
    $r = $svc->completeLesson('nope', 'lesson-1', 'owner', 90);
    chk('CO1 unknown enrollment not found', ! $r->ok && $r->code === 'ENROLLMENT_NOT_FOUND');

    // owner succeeds (no required lessons => completes; but progress recorded either way)
    $r = $svc->completeLesson($ownEnr, 'lesson-1', 'owner', 90);
    chk('CO1 owner allowed', $r->ok);
    chk('CO1 owner progress recorded', count($db->rows['learning_progress']) === 1);

    // ---- summary ------------------------------------------------------------
    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
