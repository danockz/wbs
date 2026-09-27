<?php

declare(strict_types=1);

/**
 * GroupKindService::listWithCounts() + groupsForKind() + show() drill-down.
 *
 * Proves the group↔group-kind relationship surfaces in the kinds console:
 *   - listWithCounts() returns each active kind with a `group_count` of the
 *     ACTIVE groups whose kind_code matches (0 when none);
 *   - groupsForKind() returns the active groups for a given code, path-ordered,
 *     and normalizes the code (case/space); an unknown code returns [];
 *   - show() enriches the kind row with `groups` + `group_count` for drill-down;
 *   - inactive groups are NOT counted.
 *
 * Tiny in-memory fake of the CI4 query builder (no DB, no framework) supporting
 * the grouped-count query (select(...,false) + groupBy + raw WHERE) these
 * methods use, with a no-op AuditLogger + Clock.
 *
 *   php app/Modules/Groups/Services/tests/group_kind_counts_test.php
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
        /** @var list<array{k:string,v:mixed}> */
        private array $eq = [];
        private bool $notNullKind = false;
        private ?string $groupBy = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s, $escape = true)
        {
            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if (str_contains($k, 'IS NOT NULL')) {
                $this->notNullKind = true;

                return $this;
            }
            $this->eq[] = ['k' => $k, 'v' => $v];

            return $this;
        }

        public function groupBy($k)
        {
            $this->groupBy = (string) $k;

            return $this;
        }

        public function orderBy($a, $b = null)
        {
            return $this;
        }

        public function limit($n)
        {
            return $this;
        }

        public function get(): RS
        {
            $rows = $this->matchingRows();

            // Emulate the grouped COUNT(*) AS c query used by listWithCounts.
            if ($this->groupBy === 'kind_code') {
                $agg = [];
                foreach ($rows as $r) {
                    $code = (string) ($r['kind_code'] ?? '');
                    $agg[$code] = ($agg[$code] ?? 0) + 1;
                }
                $out = [];
                foreach ($agg as $code => $c) {
                    $out[] = ['kind_code' => $code, 'c' => $c];
                }

                return new RS($out);
            }

            return new RS($rows);
        }

        public function countAllResults(): int
        {
            return count($this->matchingRows());
        }

        private function matchingRows(): array
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], function ($r) {
                foreach ($this->eq as $c) {
                    if ((string) ($r[$c['k']] ?? '') !== (string) $c['v']) {
                        return false;
                    }
                }
                if ($this->notNullKind && ($r['kind_code'] ?? null) === null) {
                    return false;
                }

                return true;
            }));

            return $rows;
        }
    }
}

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

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Audit\Services\AuditLogger;
    use WBS\Groups\Services\GroupKindService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Groups/Services/GroupKindService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $db  = new BaseConnection();
    $db->rows['group_kinds'] = [
        ['id' => 'k1', 'organization_id' => $ORG, 'code' => 'ministry', 'name' => 'Ministry', 'status' => 'active', 'sort_order' => 0],
        ['id' => 'k2', 'organization_id' => $ORG, 'code' => 'team', 'name' => 'Team', 'status' => 'active', 'sort_order' => 1],
        ['id' => 'k3', 'organization_id' => $ORG, 'code' => 'committee', 'name' => 'Committee', 'status' => 'active', 'sort_order' => 2],
    ];
    $db->rows['groups'] = [
        ['id' => 'g1', 'organization_id' => $ORG, 'name' => 'Worship', 'type' => 'cell', 'depth' => 3, 'path' => '/a/g1/', 'kind_code' => 'ministry', 'status' => 'active'],
        ['id' => 'g2', 'organization_id' => $ORG, 'name' => 'Ushering', 'type' => 'cell', 'depth' => 3, 'path' => '/a/g2/', 'kind_code' => 'ministry', 'status' => 'active'],
        ['id' => 'g3', 'organization_id' => $ORG, 'name' => 'Media', 'type' => 'cell', 'depth' => 3, 'path' => '/a/g3/', 'kind_code' => 'team', 'status' => 'active'],
        ['id' => 'g4', 'organization_id' => $ORG, 'name' => 'Archived Min', 'type' => 'cell', 'depth' => 3, 'path' => '/a/g4/', 'kind_code' => 'ministry', 'status' => 'dissolved'],
        ['id' => 'g5', 'organization_id' => $ORG, 'name' => 'Unclassified', 'type' => 'cell', 'depth' => 3, 'path' => '/a/g5/', 'kind_code' => null, 'status' => 'active'],
    ];

    $svc = new GroupKindService($db, new Clock(), new AuditLogger());

    // listWithCounts
    $kinds = $svc->listWithCounts($ORG, true);
    $byCode = [];
    foreach ($kinds as $k) {
        $byCode[$k['code']] = $k;
    }
    $chk('ministry count = 2 (active only, dissolved excluded)', (int) ($byCode['ministry']['group_count'] ?? -1) === 2, (string) ($byCode['ministry']['group_count'] ?? -1));
    $chk('team count = 1', (int) ($byCode['team']['group_count'] ?? -1) === 1, (string) ($byCode['team']['group_count'] ?? -1));
    $chk('committee count = 0', (int) ($byCode['committee']['group_count'] ?? -1) === 0, (string) ($byCode['committee']['group_count'] ?? -1));

    // groupsForKind
    $g = $svc->groupsForKind($ORG, 'ministry');
    $names = array_map(static fn ($r) => (string) $r['name'], $g);
    $chk('groupsForKind(ministry) = 2 active groups', count($g) === 2, json_encode($names));
    $chk('groupsForKind excludes dissolved', ! in_array('Archived Min', $names, true), json_encode($names));
    $chk('groupsForKind normalizes code (  MINISTRY )', count($svc->groupsForKind($ORG, '  MINISTRY ')) === 2);
    $chk('groupsForKind(unknown) = []', $svc->groupsForKind($ORG, 'nope') === []);
    $chk('groupsForKind(empty) = []', $svc->groupsForKind($ORG, '') === []);

    // show() drill-down enrichment
    $res = $svc->show($ORG, 'k1');
    $chk('show ok', $res->ok === true);
    $chk('show enriches group_count = 2', (int) ($res->data['group_count'] ?? -1) === 2, (string) ($res->data['group_count'] ?? -1));
    $chk('show enriches groups list', is_array($res->data['groups'] ?? null) && count($res->data['groups']) === 2);
    $chk('show unknown kind 404', $svc->show($ORG, 'ghost')->ok === false);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
