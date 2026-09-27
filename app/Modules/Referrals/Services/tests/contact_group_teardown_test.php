<?php

declare(strict_types=1);

/**
 * ContactBookService::onGroupTornDown test (Theme B group-half consumer — G5).
 *
 * When a group dies, outreach contacts pinned to it must not keep pointing at a
 * dead node. Proves over an in-memory DB fake:
 *   - MERGE: a group-context invite (invite_context_type='group') RE-POINTS to
 *     the survivor; assigned_group_id follows the survivor too; mode='merge';
 *   - DISSOLVE: the group-context invite is FAIL-CLOSED (context cleared to
 *     NULL so no one is admitted against a dead group); assigned_group_id rolls
 *     UP to the nearest live ancestor; mode='dissolve';
 *   - DISSOLVE with no live ancestor: assigned_group_id becomes NULL (org level);
 *   - a MERGE whose survivor is itself dead falls back to the dissolve rule;
 *   - non-group invite contexts (event/course) + other groups untouched;
 *   - idempotent re-run touches 0; empty org/group -> bad-input fail.
 *
 *   php app/Modules/Referrals/Services/tests/contact_group_teardown_test.php
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
    use WBS\Referrals\Services\ContactBookService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/ScopeMode.php';
    require_once $root . '/app/Modules/Referrals/Services/EventRegistrarPort.php';
    require_once $root . '/app/Modules/Referrals/Services/CourseEnrollerPort.php';
    require_once $root . '/app/Modules/Referrals/Services/GroupMembershipPort.php';
    require_once $root . '/app/Modules/Referrals/Services/JourneySignalPort.php';
    require_once $root . '/app/Modules/Referrals/Services/ContactBookService.php';

    $pass = 0;
    $fail = 0;
    $chk  = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';

    // Hierarchy: root(live) <- region(live) <- area(dead group);
    // and root <- deadbranch(dissolved) <- deep(dead group) for the skip case.
    $seedGroups = static fn (): array => [
        ['id' => 'root', 'organization_id' => $ORG, 'parent_id' => null, 'status' => 'active'],
        ['id' => 'region', 'organization_id' => $ORG, 'parent_id' => 'root', 'status' => 'active'],
        ['id' => 'area', 'organization_id' => $ORG, 'parent_id' => 'region', 'status' => 'active'],
        ['id' => 'survivor', 'organization_id' => $ORG, 'parent_id' => 'root', 'status' => 'active'],
        ['id' => 'deadbranch', 'organization_id' => $ORG, 'parent_id' => 'root', 'status' => 'dissolved'],
        ['id' => 'deep', 'organization_id' => $ORG, 'parent_id' => 'deadbranch', 'status' => 'active'],
        ['id' => 'orphan', 'organization_id' => $ORG, 'parent_id' => null, 'status' => 'active'],
    ];
    $rowOf = static function (BaseConnection $db, string $id): array {
        foreach ($db->rows['prospects'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };
    $mk = static fn (BaseConnection $db) => new ContactBookService($db, new Clock());

    // ---- MERGE: invite + assignment re-point to survivor -------------------
    $db = new BaseConnection();
    $db->rows['groups'] = $seedGroups();
    $db->rows['prospects'] = [
        ['id' => 'p-invite', 'organization_id' => $ORG, 'invite_context_type' => 'group', 'invite_context_id' => 'area', 'assigned_group_id' => 'area'],
        ['id' => 'p-assign', 'organization_id' => $ORG, 'invite_context_type' => null, 'invite_context_id' => null, 'assigned_group_id' => 'area'],
        ['id' => 'p-event', 'organization_id' => $ORG, 'invite_context_type' => 'event', 'invite_context_id' => 'area', 'assigned_group_id' => 'region'], // bystander: event ctx + other grp
    ];
    $svc = $mk($db);
    $res = $svc->onGroupTornDown($ORG, 'area', 'group.merged', 'survivor');
    $chk('merge ok', $res->ok === true, (string) ($res->code ?? ''));
    $chk('merge mode', ($res->data['mode'] ?? '') === 'merge');
    $chk('merge target = survivor', ($res->data['target_group_id'] ?? '') === 'survivor');
    $chk('merge repoints 1 invite', ($res->data['repointed_invite_contexts'] ?? -1) === 1);
    $chk('merge clears 0 invites', ($res->data['cleared_invite_contexts'] ?? -1) === 0);
    $chk('merge reassigns 2 contacts', ($res->data['reassigned_contacts'] ?? -1) === 2, json_encode($res->data));
    $chk('p-invite context now survivor', $rowOf($db, 'p-invite')['invite_context_id'] === 'survivor');
    $chk('p-invite still type group', $rowOf($db, 'p-invite')['invite_context_type'] === 'group');
    $chk('p-invite assignment now survivor', $rowOf($db, 'p-invite')['assigned_group_id'] === 'survivor');
    $chk('p-assign assignment now survivor', $rowOf($db, 'p-assign')['assigned_group_id'] === 'survivor');
    $chk('bystander event ctx untouched', $rowOf($db, 'p-event')['invite_context_id'] === 'area'
        && $rowOf($db, 'p-event')['invite_context_type'] === 'event');
    $chk('bystander other-group assignment untouched', $rowOf($db, 'p-event')['assigned_group_id'] === 'region');
    $chk('merge idempotent re-run 0 reassigned', ($svc->onGroupTornDown($ORG, 'area', 'group.merged', 'survivor')->data['reassigned_contacts'] ?? -1) === 0);

    // ---- DISSOLVE: invite fail-closed, assignment rolls up -----------------
    $db2 = new BaseConnection();
    $db2->rows['groups'] = $seedGroups();
    $db2->rows['prospects'] = [
        ['id' => 'p1', 'organization_id' => $ORG, 'invite_context_type' => 'group', 'invite_context_id' => 'area', 'assigned_group_id' => 'area'],
    ];
    $svc2 = $mk($db2);
    $res2 = $svc2->onGroupTornDown($ORG, 'area', 'group.dissolved', null);
    $chk('dissolve mode', ($res2->data['mode'] ?? '') === 'dissolve');
    $chk('dissolve target = region (nearest live ancestor)', ($res2->data['target_group_id'] ?? '') === 'region', (string) ($res2->data['target_group_id'] ?? 'null'));
    $chk('dissolve clears 1 invite', ($res2->data['cleared_invite_contexts'] ?? -1) === 1);
    $chk('dissolve repoints 0 invites', ($res2->data['repointed_invite_contexts'] ?? -1) === 0);
    $chk('p1 invite context fail-closed (type null)', $rowOf($db2, 'p1')['invite_context_type'] === null);
    $chk('p1 invite id cleared', $rowOf($db2, 'p1')['invite_context_id'] === null);
    $chk('p1 assignment rolled up to region', $rowOf($db2, 'p1')['assigned_group_id'] === 'region');

    // ---- DISSOLVE: skip dead intermediate ancestor -------------------------
    $db3 = new BaseConnection();
    $db3->rows['groups'] = $seedGroups(); // deep <- deadbranch(dissolved) <- root(live)
    $db3->rows['prospects'] = [
        ['id' => 'p-deep', 'organization_id' => $ORG, 'invite_context_type' => null, 'invite_context_id' => null, 'assigned_group_id' => 'deep'],
    ];
    $res3 = $mk($db3)->onGroupTornDown($ORG, 'deep', 'group.dissolved', null);
    $chk('dissolve skips dead intermediate -> root', ($res3->data['target_group_id'] ?? '') === 'root', (string) ($res3->data['target_group_id'] ?? 'null'));
    $chk('p-deep rolled up to root', $rowOf($db3, 'p-deep')['assigned_group_id'] === 'root');

    // ---- DISSOLVE: no live ancestor -> NULL (org level) --------------------
    $db4 = new BaseConnection();
    $db4->rows['groups'] = $seedGroups();
    $db4->rows['prospects'] = [
        ['id' => 'p-orphan', 'organization_id' => $ORG, 'invite_context_type' => null, 'invite_context_id' => null, 'assigned_group_id' => 'orphan'],
    ];
    $res4 = $mk($db4)->onGroupTornDown($ORG, 'orphan', 'group.dissolved', null);
    $chk('dissolve no ancestor -> null target', array_key_exists('target_group_id', $res4->data) && $res4->data['target_group_id'] === null);
    $chk('p-orphan assignment now null (org level)', $rowOf($db4, 'p-orphan')['assigned_group_id'] === null);

    // ---- MERGE whose survivor is dead falls back to ancestor ---------------
    $db5 = new BaseConnection();
    $db5->rows['groups'] = $seedGroups();
    $db5->rows['prospects'] = [
        ['id' => 'p5', 'organization_id' => $ORG, 'invite_context_type' => 'group', 'invite_context_id' => 'area', 'assigned_group_id' => 'area'],
    ];
    $res5 = $mk($db5)->onGroupTornDown($ORG, 'area', 'group.merged', 'deadbranch'); // survivor dissolved
    $chk('dead survivor -> dissolve fallback mode', ($res5->data['mode'] ?? '') === 'dissolve');
    $chk('dead survivor -> invite fail-closed', ($res5->data['cleared_invite_contexts'] ?? -1) === 1);
    $chk('dead survivor -> assignment to region', $rowOf($db5, 'p5')['assigned_group_id'] === 'region');

    // ---- bad input ---------------------------------------------------------
    $chk('empty org fails', $svc->onGroupTornDown('', 'area', 'x', 'survivor')->ok === false);
    $chk('empty group fails', $svc->onGroupTornDown($ORG, '', 'x', 'survivor')->ok === false);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
