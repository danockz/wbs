<?php

declare(strict_types=1);

/**
 * CauseService::reattributeGroupCauses test (Theme B group-half consumer — C6).
 *
 * When a RECEIVING group dies, its causes must stop attributing giving to a
 * phantom node. Proves over an in-memory DB fake:
 *   - MERGE (live survivor given): the dead group's causes re-point to the
 *     survivor; other groups' causes untouched; mode = 'merge';
 *   - DISSOLVE (no survivor): causes roll UP to the NEAREST LIVE ancestor,
 *     skipping a dead/archived intermediate link; mode = 'dissolve';
 *   - DISSOLVE with no live ancestor: group_id becomes NULL (org level);
 *   - MERGE whose survivor is itself dead falls back to the ancestor rule;
 *   - contribution rows are never touched (only causes.group_id moves);
 *   - idempotent re-run re-points 0;
 *   - empty org/group returns a bad-input fail with no writes.
 *
 *   php app/Modules/Contributions/Services/tests/cause_reattribution_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
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
        /** @var array<string,mixed> */
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($f)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function orderBy($a, $b = null)
        {
            return $this;
        }

        /** @return list<array<string,mixed>> */
        private function filtered(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function get(): RS
        {
            return new RS($this->filtered());
        }

        public function countAllResults(bool $reset = true): int
        {
            return count($this->filtered());
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

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                $rv = $r[$k] ?? null;
                if ($v === null) {
                    if ($rv !== null) {
                        return false;
                    }
                    continue;
                }
                if ((string) $rv !== (string) $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Contributions\Services\CauseService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Contributions/Services/CauseService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';

    // Hierarchy: root(live) <- region(live) <- area(the dead group).
    // A second chain root <- deadbranch(dissolved) <- deep(the dead group) tests
    // skipping a dead intermediate ancestor.
    $seedGroups = static function () use ($ORG): array {
        return [
            ['id' => 'root', 'organization_id' => $ORG, 'parent_id' => null, 'status' => 'active'],
            ['id' => 'region', 'organization_id' => $ORG, 'parent_id' => 'root', 'status' => 'active'],
            ['id' => 'area', 'organization_id' => $ORG, 'parent_id' => 'region', 'status' => 'active'],
            ['id' => 'survivor', 'organization_id' => $ORG, 'parent_id' => 'root', 'status' => 'active'],
            ['id' => 'deadbranch', 'organization_id' => $ORG, 'parent_id' => 'root', 'status' => 'dissolved'],
            ['id' => 'deep', 'organization_id' => $ORG, 'parent_id' => 'deadbranch', 'status' => 'active'],
            ['id' => 'orphan', 'organization_id' => $ORG, 'parent_id' => null, 'status' => 'active'],
        ];
    };
    $causeOf = static function (BaseConnection $db, string $id): array {
        foreach ($db->rows['causes'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };
    $mk = static fn (BaseConnection $db) => new CauseService($db, new Clock());

    // ---- MERGE: causes re-point to the live survivor ------------------------
    $db = new BaseConnection();
    $db->rows['groups'] = $seedGroups();
    $db->rows['causes'] = [
        ['id' => 'c-area-1', 'organization_id' => $ORG, 'group_id' => 'area', 'name' => 'A1'],
        ['id' => 'c-area-2', 'organization_id' => $ORG, 'group_id' => 'area', 'name' => 'A2'],
        ['id' => 'c-region', 'organization_id' => $ORG, 'group_id' => 'region', 'name' => 'R'], // bystander
    ];
    $db->rows['contributions'] = [
        ['id' => 'k1', 'cause_id' => 'c-area-1', 'amount_minor' => 500, 'state' => 'succeeded'],
    ];
    $svc = $mk($db);
    $res = $svc->reattributeGroupCauses($ORG, 'area', 'group.merged', 'survivor');
    $chk('merge ok', $res->ok === true, (string) ($res->code ?? ''));
    $chk('merge reattributes 2 causes', ($res->data['reattributed'] ?? -1) === 2, json_encode($res->data));
    $chk('merge mode = merge', ($res->data['mode'] ?? '') === 'merge');
    $chk('merge target = survivor', ($res->data['target_group_id'] ?? '') === 'survivor');
    $chk('c-area-1 now owned by survivor', $causeOf($db, 'c-area-1')['group_id'] === 'survivor');
    $chk('c-area-2 now owned by survivor', $causeOf($db, 'c-area-2')['group_id'] === 'survivor');
    $chk('bystander c-region untouched', $causeOf($db, 'c-region')['group_id'] === 'region');
    $chk('contribution row never rewritten', $db->rows['contributions'][0]['cause_id'] === 'c-area-1'
        && $db->rows['contributions'][0]['amount_minor'] === 500);
    $chk('merge idempotent re-run 0', ($svc->reattributeGroupCauses($ORG, 'area', 'group.merged', 'survivor')->data['reattributed'] ?? -1) === 0);

    // ---- DISSOLVE: roll up to nearest live ancestor -------------------------
    $db2 = new BaseConnection();
    $db2->rows['groups'] = $seedGroups();
    $db2->rows['causes'] = [
        ['id' => 'c-area', 'organization_id' => $ORG, 'group_id' => 'area', 'name' => 'A'],
    ];
    $svc2 = $mk($db2);
    $res2 = $svc2->reattributeGroupCauses($ORG, 'area', 'group.dissolved', null);
    $chk('dissolve mode = dissolve', ($res2->data['mode'] ?? '') === 'dissolve');
    $chk('dissolve target = region (nearest live ancestor)', ($res2->data['target_group_id'] ?? '') === 'region', (string) ($res2->data['target_group_id'] ?? 'null'));
    $chk('c-area rolled up to region', $causeOf($db2, 'c-area')['group_id'] === 'region');

    // ---- DISSOLVE: skip a DEAD intermediate ancestor ------------------------
    $db3 = new BaseConnection();
    $db3->rows['groups'] = $seedGroups(); // deep <- deadbranch(dissolved) <- root(live)
    $db3->rows['causes'] = [
        ['id' => 'c-deep', 'organization_id' => $ORG, 'group_id' => 'deep', 'name' => 'D'],
    ];
    $svc3 = $mk($db3);
    $res3 = $svc3->reattributeGroupCauses($ORG, 'deep', 'group.dissolved', null);
    $chk('dissolve skips dead intermediate -> root', ($res3->data['target_group_id'] ?? '') === 'root', (string) ($res3->data['target_group_id'] ?? 'null'));
    $chk('c-deep rolled up to root', $causeOf($db3, 'c-deep')['group_id'] === 'root');

    // ---- DISSOLVE: no live ancestor -> NULL (org level) ---------------------
    $db4 = new BaseConnection();
    $db4->rows['groups'] = $seedGroups(); // orphan has parent_id NULL
    $db4->rows['causes'] = [
        ['id' => 'c-orphan', 'organization_id' => $ORG, 'group_id' => 'orphan', 'name' => 'O'],
    ];
    $svc4 = $mk($db4);
    $res4 = $svc4->reattributeGroupCauses($ORG, 'orphan', 'group.dissolved', null);
    $chk('dissolve with no live ancestor -> null target', array_key_exists('target_group_id', $res4->data) && $res4->data['target_group_id'] === null);
    $chk('c-orphan now org-level (null group)', $causeOf($db4, 'c-orphan')['group_id'] === null);

    // ---- MERGE whose survivor is itself dead falls back to ancestor ---------
    $db5 = new BaseConnection();
    $db5->rows['groups'] = $seedGroups();
    $db5->rows['causes'] = [
        ['id' => 'c-area', 'organization_id' => $ORG, 'group_id' => 'area', 'name' => 'A'],
    ];
    $svc5 = $mk($db5);
    $res5 = $svc5->reattributeGroupCauses($ORG, 'area', 'group.merged', 'deadbranch'); // survivor is dissolved
    $chk('merge with dead survivor -> dissolve fallback mode', ($res5->data['mode'] ?? '') === 'dissolve');
    $chk('merge with dead survivor -> nearest live ancestor region', ($res5->data['target_group_id'] ?? '') === 'region');

    // ---- MERGE where survivor == dead group is treated as dissolve ----------
    $db6 = new BaseConnection();
    $db6->rows['groups'] = $seedGroups();
    $db6->rows['causes'] = [['id' => 'c-area', 'organization_id' => $ORG, 'group_id' => 'area', 'name' => 'A']];
    $svc6 = $mk($db6);
    $res6 = $svc6->reattributeGroupCauses($ORG, 'area', 'group.merged', 'area');
    $chk('survivor==dead -> dissolve fallback', ($res6->data['mode'] ?? '') === 'dissolve');

    // ---- bad input ----------------------------------------------------------
    $chk('empty org fails', $svc->reattributeGroupCauses('', 'area', 'x', 'survivor')->ok === false);
    $chk('empty group fails', $svc->reattributeGroupCauses($ORG, '', 'x', 'survivor')->ok === false);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
