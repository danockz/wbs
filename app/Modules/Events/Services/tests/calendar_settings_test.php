<?php

declare(strict_types=1);

/**
 * Hierarchical calendar settings + scoped listInRange/feedInRange.
 *
 *   php app/Modules/Events/Services/tests/calendar_settings_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;
        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
        public function affectedRows(): int { return $this->affected; }
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
        /** @var array<int,array{k:string,op:string,v:mixed}> */
        private array $conds = [];
        private ?string $orderKey = null;
        private string $orderDir = 'ASC';
        private ?int $limit = null;
        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}
        public function select($f) { return $this; }
        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if (stripos($k, 'IS NOT NULL') !== false) {
                $this->conds[] = ['k' => trim(str_ireplace('IS NOT NULL', '', $k)), 'op' => 'notnull', 'v' => null];
            } elseif (stripos($k, 'IS NULL') !== false) {
                $this->conds[] = ['k' => trim(str_ireplace('IS NULL', '', $k)), 'op' => 'null', 'v' => null];
            } elseif (preg_match('/^(\S+)\s*(>=|<=|!=|>|<)$/', $k, $m) === 1) {
                $this->conds[] = ['k' => $m[1], 'op' => $m[2], 'v' => $v];
            } else {
                $this->conds[] = ['k' => $k, 'op' => '=', 'v' => $v];
            }
            return $this;
        }
        public function whereIn($k, array $vals) { $this->conds[] = ['k' => $k, 'op' => 'in', 'v' => $vals]; return $this; }
        public function orderBy($k, $dir = 'ASC') { $this->orderKey = trim((string) $k); $this->orderDir = strtoupper((string) $dir); return $this; }
        public function limit($n) { $this->limit = (int) $n; return $this; }
        public function get(): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($this->orderKey !== null) {
                $k = $this->orderKey; $dir = $this->orderDir;
                usort($rows, static function ($a, $b) use ($k, $dir) {
                    $c = ($a[$k] ?? '') <=> ($b[$k] ?? '');
                    return $dir === 'DESC' ? -$c : $c;
                });
            }
            if ($this->limit !== null) { $rows = array_slice($rows, 0, $this->limit); }
            return new RS($rows);
        }
        public function insert(array $row): bool { $this->db->rows[$this->t][] = $row; return true; }
        public function update(array $set): bool
        {
            $n = 0;
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) { $this->db->rows[$this->t][$i] = array_merge($r, $set); $n++; }
            }
            $this->db->affected = $n;
            return true;
        }
        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $rv = $r[$c['k']] ?? null;
                $ok = match ($c['op']) {
                    'null'    => $rv === null || $rv === '',
                    'notnull' => $rv !== null && $rv !== '',
                    '>'       => (string) $rv > (string) $c['v'],
                    '>='      => (string) $rv >= (string) $c['v'],
                    '<'       => (string) $rv < (string) $c['v'],
                    '<='      => (string) $rv <= (string) $c['v'],
                    '!='      => (string) $rv !== (string) $c['v'],
                    'in'      => in_array((string) $rv, array_map('strval', $c['v']), true),
                    default   => (string) $rv === (string) $c['v'],
                };
                if (! $ok) { return false; }
            }
            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Events\Services\CalendarSettingsService;
    use WBS\Events\Services\EventService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Events/Services/CalendarSettingsService.php';
    require_once $root . '/app/Modules/Events/Services/EventValidator.php';
    require_once $root . '/app/Modules/Events/Services/EventReadiness.php';
    require_once $root . '/app/Modules/Events/Services/EventService.php';

    $pass = 0; $fail = 0;
    $chk = static function (string $l, bool $ok, string $d = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $l . ($ok ? '' : ' — ' . $d) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-09-24 12:00:00', new DateTimeZone('UTC')));
    $ORG = 'org-1';
    $db = new BaseConnection();
    $db->rows['group_members'] = [
        ['organization_id' => $ORG, 'group_id' => 'g-local', 'user_id' => 'u-1', 'status' => 'active'],
    ];
    $db->rows['group_closure'] = [
        ['ancestor_id' => 'g-local', 'descendant_id' => 'g-local', 'distance' => 0],
        ['ancestor_id' => 'g-local', 'descendant_id' => 'g-cell', 'distance' => 1],
        ['ancestor_id' => 'g-nat', 'descendant_id' => 'g-local', 'distance' => 1],
        ['ancestor_id' => 'g-nat', 'descendant_id' => 'g-cell', 'distance' => 2],
        ['ancestor_id' => 'g-nat', 'descendant_id' => 'g-nat', 'distance' => 0],
        ['ancestor_id' => 'g-cell', 'descendant_id' => 'g-cell', 'distance' => 0],
    ];
    $db->rows['group_calendar_settings'] = [];
    $db->rows['events'] = [
        ['id' => 'e1', 'organization_id' => $ORG, 'group_id' => 'g-cell', 'title' => 'Cell meeting', 'status' => 'published', 'type' => 'gathering', 'starts_at' => '2026-10-04 09:00:00', 'ends_at' => null, 'capacity' => 10, 'mode' => 'physical', 'archived_at' => null, 'timezone' => 'UTC', 'description' => '', 'access_url' => '', 'updated_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00'],
        ['id' => 'e2', 'organization_id' => $ORG, 'group_id' => 'g-other', 'title' => 'Other', 'status' => 'published', 'type' => 'conference', 'starts_at' => '2026-10-05 09:00:00', 'ends_at' => null, 'capacity' => 10, 'mode' => 'physical', 'archived_at' => null, 'timezone' => 'UTC', 'description' => '', 'access_url' => '', 'updated_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00'],
        ['id' => 'e3', 'organization_id' => $ORG, 'group_id' => 'g-local', 'title' => 'Local conf', 'status' => 'published', 'type' => 'conference', 'starts_at' => '2026-10-06 09:00:00', 'ends_at' => null, 'capacity' => 10, 'mode' => 'physical', 'archived_at' => null, 'timezone' => 'UTC', 'description' => '', 'access_url' => '', 'updated_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00'],
        ['id' => 'e4', 'organization_id' => $ORG, 'group_id' => 'g-nat', 'title' => 'National convention', 'status' => 'published', 'type' => 'conference', 'starts_at' => '2026-10-07 09:00:00', 'ends_at' => null, 'capacity' => 100, 'mode' => 'physical', 'archived_at' => null, 'timezone' => 'UTC', 'description' => '', 'access_url' => '', 'updated_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00'],
        ['id' => 'e5', 'organization_id' => $ORG, 'group_id' => 'g-nat', 'title' => 'National draft', 'status' => 'draft', 'type' => 'conference', 'starts_at' => '2026-10-08 09:00:00', 'ends_at' => null, 'capacity' => 100, 'mode' => 'physical', 'archived_at' => null, 'timezone' => 'UTC', 'description' => '', 'access_url' => '', 'updated_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00'],
    ];

    $cal = new CalendarSettingsService($db, new Clock());
    $ev  = new EventService($db, new Clock());

    echo "membership + ancestors (look UP, not down)\n";
    $seeds = $cal->membershipGroupIds($ORG, 'u-1');
    $chk('membership is local', $seeds === ['g-local']);
    $tree = $cal->expandSubtrees($seeds);
    sort($tree);
    $chk('subtree (down) is local+cell — kept for other callers', $tree === ['g-cell', 'g-local'], implode(',', $tree));
    $up = $cal->expandAncestors($seeds);
    sort($up);
    $chk('ancestors (up) is local+nat, not cell', $up === ['g-local', 'g-nat'], implode(',', $up));
    $anc = $cal->ancestorIds('g-cell');
    $chk('cell ancestors nearest-first include local then nat', $anc[0] === 'g-local' && in_array('g-nat', $anc, true));

    echo "settings inherit\n";
    $r = $cal->upsert($ORG, 'g-nat', ['display_label' => 'National', 'timezone' => 'Africa/Accra', 'visible_kinds' => 'gathering'], 'u-1');
    $chk('upsert national ok', $r->ok);
    $res = $cal->resolve($ORG, 'g-cell');
    $chk('cell inherits national label', $res['display_label'] === 'National');
    $chk('cell inherits tz', $res['timezone'] === 'Africa/Accra');
    $chk('cell inherits kinds', $res['visible_kinds'] === ['gathering']);
    $chk('inherited_from is g-nat', $res['inherited_from'] === 'g-nat');
    $own = $cal->upsert($ORG, 'g-cell', ['display_label' => 'Cell cal', 'timezone' => 'UTC'], 'u-1');
    $chk('own row overrides', $cal->resolve($ORG, 'g-cell')['display_label'] === 'Cell cal');
    $chk('bad timezone 422', $cal->upsert($ORG, 'g-cell', ['timezone' => 'Not/AZone'], 'u-1')->ok === false);

    echo "listInRange scoped UP\n";
    $ids = $cal->expandAncestors(['g-local']);
    $rows = $ev->listInRange($ORG, '2026-10-01 00:00:00', '2026-11-01 00:00:00', null, $ids);
    $got = array_column($rows, 'id');
    sort($got);
    $chk('up-scope is local+nat, excludes cell and other', $got === ['e3', 'e4', 'e5'], implode(',', $got));
    $own = $ev->listInRange($ORG, '2026-10-01 00:00:00', '2026-11-01 00:00:00', null, ['g-local']);
    $chk('own group includes local only', array_column($own, 'id') === ['e3']);
    $higher = $ev->listInRange($ORG, '2026-10-01 00:00:00', '2026-11-01 00:00:00', null, ['g-nat'], null, true);
    $chk('higher-group published-only hides national draft', array_column($higher, 'id') === ['e4'], implode(',', array_column($higher, 'id')));
    $onlyG = $ev->listInRange($ORG, '2026-10-01 00:00:00', '2026-11-01 00:00:00', null, $ids, ['gathering']);
    $chk('kinds filter gathering only (none at local/nat)', $onlyG === []);
    $chk('empty groupIds yields no rows', $ev->listInRange($ORG, '2026-10-01 00:00:00', '2026-11-01 00:00:00', null, []) === []);

    echo "localStamp\n";
    $st = CalendarSettingsService::localStamp('2026-10-04 09:00:00', 'Africa/Accra');
    $chk('Accra stamp same as UTC (no DST offset)', $st !== null && $st[0] === '2026-10-04');

    echo "source locks\n";
    $ctrl = file_get_contents($root . '/app/Modules/Events/Controllers/EventController.php');
    $chk('calendar() uses calendarQueryScope', str_contains($ctrl, 'calendarQueryScope'));
    $chk('signed-in scope looks UP via expandAncestors', str_contains($ctrl, 'expandAncestors'));
    $chk('higher-group events published-only', str_contains($ctrl, 'calendarEventsInRange') && str_contains($ctrl, 'published'));
    $chk('leader groups via event.create', str_contains($ctrl, "canManageGroupScope('event.create'"));
    $chk('calendar settings POST saveCalendarSettings', str_contains($ctrl, 'function saveCalendarSettings'));
    $routes = file_get_contents($root . '/app/Config/Routes.php');
    $chk('GET events/calendar/settings routed', str_contains($routes, "get('calendar/settings'"));
    $chk('POST events/calendar/settings webcsrf', str_contains($routes, "post('calendar/settings'"));
    $menu = file_get_contents($root . '/app/Modules/Shared/Navigation/CoreMenuProvider.php');
    $chk('menu item events.calendar_settings', str_contains($menu, 'events.calendar_settings'));
    $view = file_get_contents($root . '/app/Modules/Events/Views/calendar.php');
    $chk('calendar view has group switcher', str_contains($view, 'name="group_id"') && str_contains($view, 'allMyGroups'));
    $mig = file_get_contents($root . '/app/Modules/Events/Database/Migrations/2026-09-24-000093_CreateGroupCalendarSettings.php');
    $chk('migration resets data cache', str_contains($mig, 'resetDataCache'));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
