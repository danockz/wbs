<?php

declare(strict_types=1);

/**
 * GroupLifecycleService closure pruning (Theme B foundation — GR2).
 *
 * A TERMINAL group (dissolved / merged) must leave the `group_closure`
 * projection so no downstream resolver keeps resolving a dead node. This proves:
 *
 *   - dissolve() removes the node's ancestor + descendant + self closure rows
 *     (dissolve is leaf-only, so no descendants are stranded);
 *   - merge() re-parents children under the survivor (delegated to GroupService)
 *     and then removes the merged node's own closure rows;
 *   - archive() does NOT prune (archived is reversible; its closure stays);
 *   - an unrelated group's closure rows are untouched;
 *   - pruning is idempotent.
 *
 * Uses a tiny in-memory fake of the CI4 query builder (no DB, no framework),
 * with a stub GroupService (records move() calls) and a no-op AuditLogger.
 *
 *   php app/Modules/Groups/Services/tests/group_closure_prune_test.php
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
        private array $nin = [];

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

        public function whereNotIn($k, array $vals)
        {
            $this->nin[trim((string) $k)] = array_map('strval', $vals);

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

        public function delete(): bool
        {
            $this->db->rows[$this->t] = array_values(array_filter(
                $this->db->rows[$this->t] ?? [],
                fn ($r) => ! $this->matches($r),
            ));

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
            foreach ($this->nin as $k => $vals) {
                if (in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }

            return true;
        }
    }
}

// Stub the AuditLogger + GroupService at their FQCNs (real files not loaded).
namespace WBS\Audit\Services {
    if (! class_exists(AuditLogger::class)) {
        class AuditLogger
        {
            public function record(string $organizationId, array $data)
            {
                return null;
            }
        }
    }
}

namespace WBS\Groups\Services {
    if (! class_exists(GroupService::class)) {
        class GroupService
        {
            /** @var list<array{group:string,parent:?string}> */
            public array $moves = [];

            public function move(string $groupId, ?string $newParentId)
            {
                $this->moves[] = ['group' => $groupId, 'parent' => $newParentId];

                return \WBS\Shared\Support\Result::ok(['id' => $groupId]);
            }
        }
    }
}

namespace WBS\Shared\Messaging {
    // Outbox spy — records staged group lifecycle events (GR-emit).
    if (! class_exists(OutboxService::class)) {
        class OutboxService
        {
            /** @var list<array{type:string,agg:string,topic:string,payload:array,org:?string}> */
            public array $staged = [];

            public function stage(string $aggregateType, string $aggregateId, string $topic, array $payload, ?string $organizationId = null, array $headers = []): string
            {
                $this->staged[] = ['type' => $aggregateType, 'agg' => $aggregateId, 'topic' => $topic, 'payload' => $payload, 'org' => $organizationId];

                return 'outbox-1';
            }
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Audit\Services\AuditLogger;
    use WBS\Groups\Services\GroupLifecycleService;
    use WBS\Groups\Services\GroupService;
    use WBS\Shared\Messaging\OutboxService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Groups/Services/GroupLifecycleService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';

    $closureMentions = static function (BaseConnection $db, string $id): int {
        $n = 0;
        foreach ($db->rows['group_closure'] as $r) {
            if ($r['ancestor_id'] === $id || $r['descendant_id'] === $id) {
                $n++;
            }
        }

        return $n;
    };

    $seed = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        // Tree: root -> branch -> leaf ; plus an unrelated other-tree node.
        $db->rows['groups'] = [
            ['id' => 'root', 'organization_id' => $ORG, 'parent_id' => null, 'name' => 'Root', 'status' => 'active'],
            ['id' => 'branch', 'organization_id' => $ORG, 'parent_id' => 'root', 'name' => 'Branch', 'status' => 'active'],
            ['id' => 'leaf', 'organization_id' => $ORG, 'parent_id' => 'branch', 'name' => 'Leaf', 'status' => 'active'],
            ['id' => 'survivor', 'organization_id' => $ORG, 'parent_id' => 'root', 'name' => 'Survivor', 'status' => 'active'],
            ['id' => 'other', 'organization_id' => $ORG, 'parent_id' => null, 'name' => 'Other', 'status' => 'active'],
        ];
        $db->rows['group_closure'] = [
            ['ancestor_id' => 'root', 'descendant_id' => 'root', 'distance' => 0],
            ['ancestor_id' => 'branch', 'descendant_id' => 'branch', 'distance' => 0],
            ['ancestor_id' => 'leaf', 'descendant_id' => 'leaf', 'distance' => 0],
            ['ancestor_id' => 'survivor', 'descendant_id' => 'survivor', 'distance' => 0],
            ['ancestor_id' => 'other', 'descendant_id' => 'other', 'distance' => 0],
            ['ancestor_id' => 'root', 'descendant_id' => 'branch', 'distance' => 1],
            ['ancestor_id' => 'root', 'descendant_id' => 'leaf', 'distance' => 2],
            ['ancestor_id' => 'branch', 'descendant_id' => 'leaf', 'distance' => 1],
            ['ancestor_id' => 'root', 'descendant_id' => 'survivor', 'distance' => 1],
        ];
        $db->rows['group_members'] = [];
        $db->rows['group_lifecycle_transitions'] = [];

        return $db;
    };

    // ---- dissolve prunes the leaf's closure + emits group.dissolved --------
    $db = $seed();
    $outbox = new OutboxService();
    $svc = new GroupLifecycleService($db, new Clock(), new AuditLogger(), new GroupService(), $outbox);
    $before = $closureMentions($db, 'leaf');
    $res = $svc->dissolve($ORG, 'leaf', 'no longer meets');
    $chk('dissolve ok', $res->ok === true, (string) ($res->code ?? ''));
    // GR-emit: exactly one group.dissolved event carrying the group + org.
    $dissolvedEvents = array_values(array_filter($outbox->staged, static fn ($e) => $e['topic'] === 'group.dissolved'));
    $chk('dissolve emits one group.dissolved', count($dissolvedEvents) === 1, json_encode(array_column($outbox->staged, 'topic')));
    if ($dissolvedEvents) {
        $ev = $dissolvedEvents[0];
        $chk('dissolved event aggregate = group', $ev['type'] === 'group' && $ev['agg'] === 'leaf');
        $chk('dissolved event carries group_id + org', ($ev['payload']['group_id'] ?? '') === 'leaf' && $ev['org'] === $ORG);
        $chk('dissolved event to_status = dissolved', ($ev['payload']['to_status'] ?? '') === 'dissolved');
        $chk('dissolved event carries pre-prune parent_id (for C6 rollup)', ($ev['payload']['parent_id'] ?? '') === 'branch');
    }
    $chk('leaf had closure rows before', $before > 0, (string) $before);
    $chk('dissolve prunes ALL leaf closure rows', $closureMentions($db, 'leaf') === 0);
    $chk('dissolve leaves root closure intact', $closureMentions($db, 'root') > 0);
    $chk('dissolve leaves unrelated other intact', $closureMentions($db, 'other') === 1);
    $chk('leaf marked dissolved', (static function ($db) {
        foreach ($db->rows['groups'] as $g) {
            if ($g['id'] === 'leaf') {
                return $g['status'] === 'dissolved';
            }
        }

        return false;
    })($db));

    // ---- archive does NOT prune, but DOES emit group.archived --------------
    $db = $seed();
    $outbox = new OutboxService();
    $svc = new GroupLifecycleService($db, new Clock(), new AuditLogger(), new GroupService(), $outbox);
    $svc->archive($ORG, 'leaf', 'paused for the season');
    $chk('archive keeps leaf closure rows (reversible)', $closureMentions($db, 'leaf') > 0);
    $archivedEvents = array_values(array_filter($outbox->staged, static fn ($e) => $e['topic'] === 'group.archived'));
    $chk('archive emits one group.archived', count($archivedEvents) === 1);
    $chk('archive emits NO dissolved/merged', array_values(array_filter(
        $outbox->staged,
        static fn ($e) => in_array($e['topic'], ['group.dissolved', 'group.merged'], true),
    )) === []);

    // ---- merge re-parents children + prunes the merged node + emits event --
    $db = $seed();
    $groupSvc = new GroupService();
    $outbox = new OutboxService();
    $svc = new GroupLifecycleService($db, new Clock(), new AuditLogger(), $groupSvc, $outbox);
    // Merge `branch` INTO `survivor`: branch's child (leaf) should be moved.
    $res = $svc->merge($ORG, 'branch', 'survivor', 'consolidating branches');
    $chk('merge ok', $res->ok === true, (string) ($res->code ?? ''));
    // GR-emit: one group.merged carrying from/into (survivor). No group.dissolved.
    $mergedEvents = array_values(array_filter($outbox->staged, static fn ($e) => $e['topic'] === 'group.merged'));
    $chk('merge emits one group.merged', count($mergedEvents) === 1, json_encode(array_column($outbox->staged, 'topic')));
    if ($mergedEvents) {
        $ev = $mergedEvents[0];
        $chk('merged event from_group_id = branch', ($ev['payload']['from_group_id'] ?? '') === 'branch');
        $chk('merged event into/survivor = survivor', ($ev['payload']['into_group_id'] ?? '') === 'survivor'
            && ($ev['payload']['survivor_group_id'] ?? '') === 'survivor');
    }
    $chk('merge emits NO group.dissolved', array_values(array_filter(
        $outbox->staged,
        static fn ($e) => $e['topic'] === 'group.dissolved',
    )) === []);
    $chk('merge re-parented branch child (leaf) via GroupService::move',
        in_array(['group' => 'leaf', 'parent' => 'survivor'], $groupSvc->moves, true),
        json_encode($groupSvc->moves));
    $chk('merge prunes the merged branch closure rows', $closureMentions($db, 'branch') === 0);
    $chk('merge keeps survivor closure', $closureMentions($db, 'survivor') > 0);
    $chk('branch marked merged', (static function ($db) {
        foreach ($db->rows['groups'] as $g) {
            if ($g['id'] === 'branch') {
                return $g['status'] === 'merged';
            }
        }

        return false;
    })($db));

    // ---- prune is idempotent (re-dissolve a terminal node is illegal, so we
    //      assert the closure stays empty after the fact) --------------------
    $db = $seed();
    $svc = new GroupLifecycleService($db, new Clock(), new AuditLogger(), new GroupService());
    $svc->dissolve($ORG, 'leaf', 'gone');
    $again = $svc->dissolve($ORG, 'leaf', 'again');
    $chk('re-dissolve a terminal group is rejected', $again->ok === false);
    $chk('closure stays pruned after rejected re-dissolve', $closureMentions($db, 'leaf') === 0);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
