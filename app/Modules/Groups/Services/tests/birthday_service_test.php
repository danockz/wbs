<?php

declare(strict_types=1);

/**
 * BirthdayService — pure date/window helpers plus a tiny in-memory fake of
 * the CI4 query builder (no DB, no framework) for classification:
 *   self / peer (7d) / ancestor-leader (30d count-up);
 *   peer+leader → leader; missing DOB omitted; never year/age.
 *
 *   php app/Modules/Groups/Services/tests/birthday_service_test.php
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
        /** @var list<array{k:string,op:string,v:mixed}> */
        private array $ops = [];
        /** @var list<array{k:string,vals:list<mixed>}> */
        private array $ins = [];
        /** @var list<string> */
        private array $notNull = [];

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
                $col = trim(str_ireplace('IS NOT NULL', '', $k));
                if ($col !== '') {
                    $this->notNull[] = $col;
                }

                return $this;
            }
            if (preg_match('/^(\w+)\s*(>=|<=|!=|>|<)$/', $k, $m)) {
                $this->ops[] = ['k' => $m[1], 'op' => $m[2], 'v' => $v];

                return $this;
            }
            $this->eq[] = ['k' => $k, 'v' => $v];

            return $this;
        }

        public function whereIn($k, $vals)
        {
            $this->ins[] = ['k' => (string) $k, 'vals' => array_map('strval', (array) $vals)];

            return $this;
        }

        public function get(): RS
        {
            return new RS($this->matchingRows());
        }

        private function matchingRows(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], function ($r) {
                foreach ($this->eq as $c) {
                    if ((string) ($r[$c['k']] ?? '') !== (string) $c['v']) {
                        return false;
                    }
                }
                foreach ($this->ops as $c) {
                    $left = $r[$c['k']] ?? null;
                    if ($left === null) {
                        return false;
                    }
                    $ok = match ($c['op']) {
                        '>'  => $left > $c['v'],
                        '<'  => $left < $c['v'],
                        '>=' => $left >= $c['v'],
                        '<=' => $left <= $c['v'],
                        '!=' => $left != $c['v'],
                        default => false,
                    };
                    if (! $ok) {
                        return false;
                    }
                }
                foreach ($this->ins as $c) {
                    if (! in_array((string) ($r[$c['k']] ?? ''), $c['vals'], true)) {
                        return false;
                    }
                }
                foreach ($this->notNull as $col) {
                    $v = $r[$col] ?? null;
                    if ($v === null || $v === '') {
                        return false;
                    }
                }

                return true;
            }));
        }
    }
}

namespace {
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Groups/Support/BirthdayConfigPort.php';
    require_once $root . '/app/Modules/Groups/Support/BirthdayConfig.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Groups/Services/BirthdayService.php';

    use CodeIgniter\Database\BaseConnection;
    use WBS\Groups\Services\BirthdayService;
    use WBS\Groups\Support\BirthdayConfig;
    use WBS\Groups\Support\BirthdayConfigPort;
    use WBS\Shared\Support\Clock;

    $cfgFactory = static function () {
        return new class implements BirthdayConfigPort {
            public mixed $all = ['enabled' => true];
            /** @var array<string,mixed> */
            public array $byGroup = [];

            public function value(string $groupId, string $capability): mixed
            {
                return $this->byGroup[$groupId] ?? $this->all;
            }
        };
    };

    $pass = 0;
    $fail = 0;
    $chk  = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        if ($ok) {
            $pass++;
            echo "  ok  {$label}\n";
        } else {
            $fail++;
            echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        }
    };

    $tz    = new DateTimeZone('UTC');
    $today = DateTimeImmutable::createFromFormat('!Y-m-d', '2026-05-10', $tz);
    assert($today instanceof DateTimeImmutable);

    $chk('today is 0 days', BirthdayService::daysUntil('2000-05-10', $today) === 0);
    $chk('tomorrow is 1 day', BirthdayService::daysUntil('1999-05-11', $today) === 1);
    $chk('7 days is peer window edge', BirthdayService::daysUntil('1980-05-17', $today) === 7);
    $chk('8 days is outside peer window', BirthdayService::daysUntil('1980-05-18', $today) === 8);
    $chk('inPeerWindow 0..7', BirthdayService::inPeerWindow(0) && BirthdayService::inPeerWindow(7) && ! BirthdayService::inPeerWindow(8));
    $chk('inLeaderWindow 0..30', BirthdayService::inLeaderWindow(0) && BirthdayService::inLeaderWindow(30) && ! BirthdayService::inLeaderWindow(31));

    $chk('past this year rolls to next year', BirthdayService::daysUntil('2001-01-01', $today) === 236);

    $feb28 = DateTimeImmutable::createFromFormat('!Y-m-d', '2026-02-28', $tz);
    assert($feb28 instanceof DateTimeImmutable);
    $chk('29 Feb in non-leap year lands on 28 Feb', BirthdayService::daysUntil('2000-02-29', $feb28) === 0);
    $feb27 = DateTimeImmutable::createFromFormat('!Y-m-d', '2026-02-27', $tz);
    assert($feb27 instanceof DateTimeImmutable);
    $chk('29 Feb one day before 28 Feb non-leap', BirthdayService::daysUntil('2000-02-29', $feb27) === 1);

    $leap = DateTimeImmutable::createFromFormat('!Y-m-d', '2028-02-28', $tz);
    assert($leap instanceof DateTimeImmutable);
    $chk('29 Feb in leap year stays 29 Feb', BirthdayService::daysUntil('2000-02-29', $leap) === 1);

    $chk('invalid dob is null', BirthdayService::daysUntil('not-a-date', $today) === null);
    $chk('empty dob is null', BirthdayService::daysUntil('', $today) === null);

    $chk('leader count-up 30 days remaining = 1 of 30', BirthdayService::leaderCountUp(30) === 1);
    $chk('leader count-up 1 day remaining = 30 of 30', BirthdayService::leaderCountUp(1) === 30);
    $chk('leader count-up today = 0', BirthdayService::leaderCountUp(0) === 0);
    $chk('leader count-up outside window = 0', BirthdayService::leaderCountUp(31) === 0);

    $next = BirthdayService::nextOccurrence('1990-12-25', $today);
    $chk('nextOccurrence returns DateTimeImmutable', $next instanceof DateTimeImmutable);
    $chk('nextOccurrence keeps month-day', $next instanceof DateTimeImmutable && $next->format('m-d') === '12-25');
    $chk('nextOccurrence never exposes birth year', $next instanceof DateTimeImmutable && $next->format('Y') !== '1990');

    $chk('PEER_DAYS is 7', BirthdayService::PEER_DAYS === 7);
    $chk('LEADER_DAYS is 30', BirthdayService::LEADER_DAYS === 30);

    // ---- classification (fake DB) ------------------------------------------------
    Clock::freeze(new DateTimeImmutable('2026-05-10 12:00:00', $tz));

    $db = new BaseConnection();
    $db->rows = [
        'organizations' => [
            ['id' => 'o1', 'timezone' => 'UTC'],
        ],
        'groups' => [
            ['id' => 'cell', 'organization_id' => 'o1', 'name' => 'Cell', 'leader_user_id' => 'leader-cell'],
            ['id' => 'area', 'organization_id' => 'o1', 'name' => 'Area', 'leader_user_id' => 'leader-area'],
            ['id' => 'region', 'organization_id' => 'o1', 'name' => 'Region', 'leader_user_id' => 'leader-region'],
        ],
        'group_closure' => [
            ['ancestor_id' => 'cell', 'descendant_id' => 'cell', 'distance' => 0],
            ['ancestor_id' => 'area', 'descendant_id' => 'cell', 'distance' => 1],
            ['ancestor_id' => 'region', 'descendant_id' => 'cell', 'distance' => 2],
            ['ancestor_id' => 'area', 'descendant_id' => 'area', 'distance' => 0],
            ['ancestor_id' => 'region', 'descendant_id' => 'area', 'distance' => 1],
        ],
        'group_members' => [
            ['organization_id' => 'o1', 'group_id' => 'cell', 'user_id' => 'viewer', 'status' => 'active'],
            ['organization_id' => 'o1', 'group_id' => 'cell', 'user_id' => 'peer-in', 'status' => 'active'],
            ['organization_id' => 'o1', 'group_id' => 'cell', 'user_id' => 'peer-out', 'status' => 'active'],
            ['organization_id' => 'o1', 'group_id' => 'cell', 'user_id' => 'both', 'status' => 'active'],
            ['organization_id' => 'o1', 'group_id' => 'cell', 'user_id' => 'no-dob', 'status' => 'active'],
            ['organization_id' => 'o1', 'group_id' => 'cell', 'user_id' => 'left', 'status' => 'left'],
        ],
        'users' => [
            ['id' => 'viewer', 'organization_id' => 'o1', 'display_name' => 'Vie Wer', 'date_of_birth' => '1990-05-10'],
            ['id' => 'peer-in', 'organization_id' => 'o1', 'display_name' => 'Peer In', 'date_of_birth' => '1988-05-12'],
            ['id' => 'peer-out', 'organization_id' => 'o1', 'display_name' => 'Peer Out', 'date_of_birth' => '1985-05-20'],
            ['id' => 'both', 'organization_id' => 'o1', 'display_name' => 'Both Roles', 'date_of_birth' => '1975-05-15'],
            ['id' => 'no-dob', 'organization_id' => 'o1', 'display_name' => 'No Dob', 'date_of_birth' => null],
            ['id' => 'leader-area', 'organization_id' => 'o1', 'display_name' => 'Area Lead', 'date_of_birth' => '1970-05-25'],
            ['id' => 'leader-region', 'organization_id' => 'o1', 'display_name' => 'Region Lead', 'date_of_birth' => '1960-06-20'],
            ['id' => 'left', 'organization_id' => 'o1', 'display_name' => 'Left Member', 'date_of_birth' => '1991-05-11'],
        ],
    ];
    // both is also the Area leader (peer + ancestor leader → leader)
    $db->rows['groups'][1]['leader_user_id'] = 'both';

    $cfg = $cfgFactory();
    $svc = new BirthdayService($db, new Clock(), $cfg);
    $hub = $svc->forUser('o1', 'viewer');

    $chk('hub timezone UTC', ($hub['timezone'] ?? '') === 'UTC');
    $chk('hub today 2026-05-10', ($hub['today'] ?? '') === '2026-05-10');
    $chk('mine is self today', is_array($hub['mine']) && ($hub['mine']['kind'] ?? '') === 'self' && (int) $hub['mine']['days_remaining'] === 0);
    $chk('mine never includes birth year', is_array($hub['mine']) && ! str_contains(json_encode($hub['mine']) ?: '', '1990'));
    $chk('today_list includes self', (bool) array_filter($hub['today_list'], static fn ($it) => ($it['user_id'] ?? '') === 'viewer'));

    $peerIds = array_map(static fn ($it) => $it['user_id'], $hub['peers']);
    $chk('in-window peer listed', in_array('peer-in', $peerIds, true));
    $chk('out-of-window peer omitted', ! in_array('peer-out', $peerIds, true));
    $chk('inactive member omitted', ! in_array('left', $peerIds, true));
    $chk('missing DOB omitted', ! in_array('no-dob', $peerIds, true));

    $leaderIds = array_map(static fn ($it) => $it['user_id'], $hub['leaders']);
    $chk('ancestor leader in 30d listed', in_array('leader-area', $leaderIds, true) || in_array('both', $leaderIds, true));
    $chk('ancestor leader outside 30d omitted', ! in_array('leader-region', $leaderIds, true));
    $chk('peer+leader classified as leader not peer', in_array('both', $leaderIds, true) && ! in_array('both', $peerIds, true));

    $both = null;
    foreach ($hub['leaders'] as $it) {
        if (($it['user_id'] ?? '') === 'both') {
            $both = $it;
        }
    }
    $chk('leader count-up is 1-of-30 style', is_array($both) && (int) $both['count_up'] === BirthdayService::leaderCountUp(5));
    $chk('leader names the ancestor group', is_array($both) && ($both['group_name'] ?? '') === 'Area');
    $chk('payload has month_day not age', is_array($both) && isset($both['month_day']) && ! isset($both['age']) && ! isset($both['year']));

    $cal = $svc->forCalendar('o1', 'viewer', 2026, 5);
    $calIds = array_map(static fn ($it) => $it['user_id'], $cal);
    $chk('calendar overlay includes in-window May birthdays', in_array('peer-in', $calIds, true) && in_array('viewer', $calIds, true));
    $chk('calendar overlay skips out-of-window May birthday', ! in_array('peer-out', $calIds, true));
    $june = $svc->forCalendar('o1', 'viewer', 2026, 6);
    $chk('calendar overlay does not expand window to viewed month', $june === []);

    $empty = $svc->forUser('o1', 'nobody');
    $chk('no memberships → empty hub', $empty['mine'] === null && $empty['peers'] === [] && $empty['leaders'] === []);
    $chk('enabled hub when config on', ($hub['enabled'] ?? false) === true);

    $off = new BirthdayService($db, new Clock(), null);
    $offHub = $off->forUser('o1', 'viewer');
    $chk('no config port → fail closed', ($offHub['enabled'] ?? true) === false && $offHub['mine'] === null && $offHub['peers'] === []);

    $cfg->all = ['enabled' => false];
    $off2 = (new BirthdayService($db, new Clock(), $cfg))->forUser('o1', 'viewer');
    $chk('enabled false → fail closed', ($off2['enabled'] ?? true) === false && $off2['peers'] === []);

    $cfg->all = ['enabled' => true, 'show_peers' => false, 'show_leaders' => true];
    $noPeers = (new BirthdayService($db, new Clock(), $cfg))->forUser('o1', 'viewer');
    $chk('show_peers false hides peers', $noPeers['peers'] === [] && $noPeers['mine'] !== null);
    $noPeerIds = array_map(static fn ($it) => $it['user_id'], $noPeers['leaders']);
    $chk('show_peers false still lists ancestor leaders', in_array('both', $noPeerIds, true));

    $cfg->all = ['enabled' => true, 'show_peers' => true, 'show_leaders' => false];
    $noLead = (new BirthdayService($db, new Clock(), $cfg))->forUser('o1', 'viewer');
    $chk('show_leaders false hides leaders', $noLead['leaders'] === []);
    $np = array_map(static fn ($it) => $it['user_id'], $noLead['peers']);
    $chk('show_leaders false still lists peers', in_array('peer-in', $np, true));
    $chk('peer+leader becomes peer when leaders hidden', in_array('both', $np, true));

    $cfg->all = ['enabled' => true, 'peer_days' => 1];
    $tight = (new BirthdayService($db, new Clock(), $cfg))->forUser('o1', 'viewer');
    $tp = array_map(static fn ($it) => $it['user_id'], $tight['peers']);
    $chk('custom peer_days=1 drops 2-day peer', ! in_array('peer-in', $tp, true));

    $cfg->all = ['enabled' => true, 'hub' => false, 'calendar' => true, 'notify' => false];
    $noHub = (new BirthdayService($db, new Clock(), $cfg))->forUser('o1', 'viewer', 'hub');
    $chk('hub surface off → empty', ($noHub['enabled'] ?? true) === false);
    $calOn = (new BirthdayService($db, new Clock(), $cfg))->forCalendar('o1', 'viewer', 2026, 5);
    $chk('calendar surface on still overlays', $calOn !== []);

    $chk('fromResolved null is off', BirthdayConfig::fromResolved(null)->enabled === false);
    $chk('fromResolved enabled true', BirthdayConfig::fromResolved(['enabled' => true])->enabled === true);
    $chk('fromResolved clamps peer_days', BirthdayConfig::fromResolved(['enabled' => true, 'peer_days' => 0])->peerDays === 1);
    $chk('CAPABILITY key', BirthdayConfig::CAPABILITY === 'groups.birthdays');

    Clock::freeze(null);

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
