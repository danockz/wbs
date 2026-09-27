<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use WBS\Groups\Support\BirthdayConfig;
use WBS\Groups\Support\BirthdayConfigPort;
use WBS\Notifications\Services\NotificationService;
use WBS\Shared\Support\Clock;

/**
 * Hierarchical group-aware birthdays.
 *
 * Viewer V (union of active memberships, no new bits) sees, when that
 * membership's `groups.birthdays` config is ON for the requested surface:
 *   - own next birthday (always; month+day only, never year/age);
 *   - fellow members of those groups inside that group's peer_days window;
 *   - leaders of proper ancestor groups inside that group's leader_days
 *     window, with a 1-of-N … N-of-N count-up.
 *
 * Missing date_of_birth is omitted. A person who is both a peer and an
 * ancestor leader is classified as leader. Config is fail-closed: no row /
 * enabled≠true → nothing.
 */
final class BirthdayService
{
    public const PEER_DAYS   = BirthdayConfig::DEFAULT_PEER_DAYS;
    public const LEADER_DAYS = BirthdayConfig::DEFAULT_LEADER_DAYS;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?BirthdayConfigPort $config = null,
    ) {
    }

    /**
     * Hub payload for one signed-in member.
     *
     * @return array{
     *   timezone:string,
     *   today:string,
     *   enabled:bool,
     *   show_peers:bool,
     *   show_leaders:bool,
     *   peer_days:?int,
     *   leader_days:?int,
     *   mine:?array<string,mixed>,
     *   today_list:list<array<string,mixed>>,
     *   peers:list<array<string,mixed>>,
     *   leaders:list<array<string,mixed>>
     * }
     */
    public function forUser(string $organizationId, string $userId, string $surface = 'hub'): array
    {
        $tz    = $this->orgTimezone($organizationId);
        $today = $this->clock->now()->setTimezone($tz)->setTime(0, 0, 0);
        $empty = [
            'timezone'     => $tz->getName(),
            'today'        => $today->format('Y-m-d'),
            'enabled'      => false,
            'show_peers'   => false,
            'show_leaders' => false,
            'peer_days'    => null,
            'leader_days'  => null,
            'mine'         => null,
            'today_list'   => [],
            'peers'        => [],
            'leaders'      => [],
        ];
        if ($organizationId === '' || $userId === '') {
            return $empty;
        }

        $memberships = $this->membershipGroupIds($organizationId, $userId);
        if ($memberships === []) {
            return $empty;
        }

        $cfgs = $this->configsFor($memberships);
        $active = [];
        $showPeers = false;
        $showLeaders = false;
        $peerDays = null;
        $leaderDays = null;
        foreach ($memberships as $gid) {
            $cfg = $cfgs[$gid];
            if (! $cfg->allows($surface)) {
                continue;
            }
            $active[$gid] = $cfg;
            if ($cfg->showPeers) {
                $showPeers = true;
                $peerDays  = $peerDays === null ? $cfg->peerDays : max($peerDays, $cfg->peerDays);
            }
            if ($cfg->showLeaders) {
                $showLeaders = true;
                $leaderDays  = $leaderDays === null ? $cfg->leaderDays : max($leaderDays, $cfg->leaderDays);
            }
        }
        if ($active === []) {
            return $empty;
        }

        $items = $this->collect($organizationId, $userId, $active, $today);
        $mine  = null;
        $todayList = [];
        $peers = [];
        $leaders = [];
        foreach ($items as $it) {
            if ($it['kind'] === 'self') {
                $mine = $it;
            }
            if ((int) $it['days_remaining'] === 0) {
                $todayList[] = $it;
            }
            if ($it['kind'] === 'peer' && (int) $it['days_remaining'] > 0) {
                $peers[] = $it;
            }
            if ($it['kind'] === 'leader' && (int) $it['days_remaining'] > 0) {
                $leaders[] = $it;
            }
        }

        return [
            'timezone'     => $tz->getName(),
            'today'        => $today->format('Y-m-d'),
            'enabled'      => true,
            'show_peers'   => $showPeers,
            'show_leaders' => $showLeaders,
            'peer_days'    => $peerDays,
            'leader_days'  => $leaderDays,
            'mine'         => $mine,
            'today_list'   => $todayList,
            'peers'        => $peers,
            'leaders'      => $leaders,
        ];
    }

    /**
     * Windowed birthdays whose next occurrence falls in $year-$month (for the
     * events calendar overlay). Windows vs today, not vs the viewed month.
     *
     * @return list<array<string,mixed>>
     */
    public function forCalendar(string $organizationId, string $userId, int $year, int $month): array
    {
        $hub = $this->forUser($organizationId, $userId, 'calendar');
        $out = [];
        $all = array_merge(
            $hub['mine'] !== null ? [$hub['mine']] : [],
            $hub['today_list'],
            $hub['peers'],
            $hub['leaders'],
        );
        $seen = [];
        foreach ($all as $it) {
            $id = (string) ($it['user_id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $next = (string) ($it['next_date'] ?? '');
            if ($next === '' || (int) substr($next, 0, 4) !== $year || (int) substr($next, 5, 2) !== $month) {
                continue;
            }
            $out[] = $it;
        }
        usort($out, static fn ($a, $b) => ((int) ($a['day'] ?? 0)) <=> ((int) ($b['day'] ?? 0)));

        return $out;
    }

    /**
     * Stage in-app notices for everyone who would see a birthday TODAY on the
     * notify surface. Idempotent via notification_deliveries.dedupe_key.
     *
     * @return array{subjects:int, notices:int}
     */
    public function notifyToday(string $organizationId, NotificationService $notifications): array
    {
        if ($organizationId === '') {
            return ['subjects' => 0, 'notices' => 0];
        }
        $tz    = $this->orgTimezone($organizationId);
        $today = $this->clock->now()->setTimezone($tz)->setTime(0, 0, 0);
        $ymd   = $today->format('Y-m-d');

        $viewers = $this->activeMemberIds($organizationId);
        $subjects = 0;
        $notices  = 0;
        $seenPair = [];
        foreach ($viewers as $viewerId) {
            $hub = $this->forUser($organizationId, $viewerId, 'notify');
            foreach ($hub['today_list'] as $it) {
                $sid = (string) ($it['user_id'] ?? '');
                if ($sid === '') {
                    continue;
                }
                $pair = $viewerId . ':' . $sid;
                if (isset($seenPair[$pair])) {
                    continue;
                }
                $seenPair[$pair] = true;
                $subjects++;
                $name = (string) ($it['display_name'] ?? '');
                $self = $sid === $viewerId;
                $body = $self
                    ? 'Happy birthday.'
                    : '{{member.preferred_name}} has a birthday today.';
                $res = $notifications->send($organizationId, $viewerId, 'inapp', 'service', [
                    'body'       => $body,
                    'context'    => ['member.preferred_name' => $name],
                    'dedupe_key' => 'birthday:' . $organizationId . ':' . $viewerId . ':' . $sid . ':' . $ymd,
                    'priority'   => 'normal',
                    'escape'     => true,
                ]);
                if ($res->ok) {
                    $notices++;
                }
            }
        }

        return ['subjects' => $subjects, 'notices' => $notices];
    }

    /**
     * Days from $today (date, tz of $today) until the next month/day of $dob.
     * 0 = today. Null if $dob is unusable.
     */
    public static function daysUntil(string $dob, DateTimeImmutable $today): ?int
    {
        $next = self::nextOccurrence($dob, $today);
        if ($next === null) {
            return null;
        }
        $a = $today->setTime(0, 0, 0);
        $b = $next->setTime(0, 0, 0);

        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /**
     * Next anniversary of $dob on or after $today (same timezone as $today).
     * 29 Feb in a non-leap year lands on 28 Feb.
     */
    public static function nextOccurrence(string $dob, DateTimeImmutable $today): ?DateTimeImmutable
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($dob), $m)) {
            return null;
        }
        $month = (int) $m[2];
        $day   = (int) $m[3];
        if ($month < 1 || $month > 12) {
            return null;
        }
        $tz = $today->getTimezone();
        $y  = (int) $today->format('Y');
        $cand = self::dateInYear($y, $month, $day, $tz);
        if ($cand === null) {
            return null;
        }
        if ($cand->setTime(0, 0, 0) < $today->setTime(0, 0, 0)) {
            $cand = self::dateInYear($y + 1, $month, $day, $tz);
        }

        return $cand;
    }

    /** 1..$window while days remaining is $window..1; 0 means today. */
    public static function leaderCountUp(int $daysRemaining, int $window = self::LEADER_DAYS): int
    {
        if ($daysRemaining <= 0 || $window < 1 || $daysRemaining > $window) {
            return 0;
        }

        return $window + 1 - $daysRemaining;
    }

    public static function inPeerWindow(int $daysRemaining, int $window = self::PEER_DAYS): bool
    {
        return $daysRemaining >= 0 && $daysRemaining <= $window;
    }

    public static function inLeaderWindow(int $daysRemaining, int $window = self::LEADER_DAYS): bool
    {
        return $daysRemaining >= 0 && $daysRemaining <= $window;
    }

    /**
     * @param array<string,BirthdayConfig> $active membership group id → config
     *
     * @return list<array<string,mixed>>
     */
    private function collect(string $organizationId, string $viewerId, array $active, DateTimeImmutable $today): array
    {
        $peerGroupIds = [];
        $leaderSeeds  = [];
        foreach ($active as $gid => $cfg) {
            if ($cfg->showPeers) {
                $peerGroupIds[] = $gid;
            }
            if ($cfg->showLeaders) {
                $leaderSeeds[] = $gid;
            }
        }

        $membersByGroup = $this->membersByGroup($organizationId, array_keys($active));
        $peerIds        = $this->memberIdsInGroups($organizationId, $peerGroupIds);
        $ancestorsBySeed = [];
        $allAncestors    = [];
        foreach ($leaderSeeds as $seed) {
            $anc = $this->properAncestors([$seed]);
            $ancestorsBySeed[$seed] = $anc;
            foreach ($anc as $a) {
                $allAncestors[$a] = true;
            }
        }
        $leaderByUser = $this->leadersOf($organizationId, array_keys($allAncestors));

        $want = [$viewerId => true];
        foreach (array_keys($peerIds) as $id) {
            if ($id !== '') {
                $want[$id] = true;
            }
        }
        foreach (array_keys($leaderByUser) as $id) {
            $want[$id] = true;
        }

        $people = $this->peopleWithDob($organizationId, array_keys($want));
        $items  = [];
        foreach ($people as $uid => $p) {
            $days = self::daysUntil((string) $p['date_of_birth'], $today);
            if ($days === null) {
                continue;
            }
            $isSelf = $uid === $viewerId;

            $leaderHit = null;
            $leaderWin = 0;
            if (! $isSelf) {
                foreach ($active as $gid => $cfg) {
                    if (! $cfg->showLeaders || ! self::inLeaderWindow($days, $cfg->leaderDays)) {
                        continue;
                    }
                    foreach ($ancestorsBySeed[$gid] ?? [] as $ancId) {
                        foreach ($leaderByUser[$uid] ?? [] as $meta) {
                            if (($meta['group_id'] ?? '') !== $ancId) {
                                continue;
                            }
                            if ($cfg->leaderDays >= $leaderWin) {
                                $leaderHit = $meta;
                                $leaderWin = $cfg->leaderDays;
                            }
                        }
                    }
                }
            }

            $peerHit = false;
            if (! $isSelf && $leaderHit === null) {
                foreach ($active as $gid => $cfg) {
                    if (! $cfg->showPeers || ! self::inPeerWindow($days, $cfg->peerDays)) {
                        continue;
                    }
                    if (isset($membersByGroup[$gid][$uid])) {
                        $peerHit = true;
                        break;
                    }
                }
            }

            $kind = null;
            if ($isSelf) {
                $kind = 'self';
            } elseif ($leaderHit !== null) {
                $kind = 'leader';
            } elseif ($peerHit) {
                $kind = 'peer';
            }
            if ($kind === null) {
                continue;
            }

            $next = self::nextOccurrence((string) $p['date_of_birth'], $today);
            if ($next === null) {
                continue;
            }
            $items[] = [
                'user_id'        => $uid,
                'display_name'   => (string) ($p['display_name'] ?: $uid),
                'kind'           => $kind,
                'month'          => (int) $next->format('n'),
                'day'            => (int) $next->format('j'),
                'month_day'      => $next->format('j M'),
                'next_date'      => $next->format('Y-m-d'),
                'days_remaining' => $days,
                'count_up'       => $kind === 'leader' ? self::leaderCountUp($days, $leaderWin) : null,
                'window'         => $kind === 'leader' ? $leaderWin : null,
                'group_id'       => (string) (($leaderHit['group_id'] ?? '')),
                'group_name'     => (string) (($leaderHit['group_name'] ?? '')),
            ];
        }
        usort($items, static function ($a, $b) {
            $d = ((int) $a['days_remaining']) <=> ((int) $b['days_remaining']);

            return $d !== 0 ? $d : strcasecmp((string) $a['display_name'], (string) $b['display_name']);
        });

        return $items;
    }

    /**
     * @param list<string> $groupIds
     *
     * @return array<string,BirthdayConfig>
     */
    private function configsFor(array $groupIds): array
    {
        $out = [];
        foreach ($groupIds as $gid) {
            $out[$gid] = $this->config === null
                ? BirthdayConfig::off()
                : BirthdayConfig::fromResolved($this->config->value($gid, BirthdayConfig::CAPABILITY));
        }

        return $out;
    }

    private function orgTimezone(string $organizationId): DateTimeZone
    {
        $name = 'UTC';
        if ($organizationId !== '') {
            try {
                $row = $this->db->table('organizations')->select('timezone')->where('id', $organizationId)->get()->getRowArray();
                $cand = is_array($row) ? trim((string) ($row['timezone'] ?? '')) : '';
                if ($cand !== '') {
                    $name = $cand;
                }
            } catch (\Throwable) {
                $name = 'UTC';
            }
        }
        try {
            return new DateTimeZone($name);
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /** @return list<string> */
    private function membershipGroupIds(string $organizationId, string $userId): array
    {
        $rows = $this->db->table('group_members')
            ->select('group_id')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->get()->getResultArray();
        $ids = [];
        foreach ($rows as $r) {
            $id = (string) ($r['group_id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return list<string> */
    private function activeMemberIds(string $organizationId): array
    {
        $rows = $this->db->table('group_members')
            ->select('user_id')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get()->getResultArray();
        $ids = [];
        foreach ($rows as $r) {
            $id = (string) ($r['user_id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param list<string> $groupIds
     *
     * @return array<string,true>
     */
    private function memberIdsInGroups(string $organizationId, array $groupIds): array
    {
        $by = $this->membersByGroup($organizationId, $groupIds);
        $ids = [];
        foreach ($by as $members) {
            foreach (array_keys($members) as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $groupIds
     *
     * @return array<string,array<string,true>> group_id => user_id => true
     */
    private function membersByGroup(string $organizationId, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $rows = $this->db->table('group_members')
            ->select('group_id, user_id')
            ->where('organization_id', $organizationId)
            ->whereIn('group_id', $groupIds)
            ->where('status', 'active')
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $gid = (string) ($r['group_id'] ?? '');
            $uid = (string) ($r['user_id'] ?? '');
            if ($gid === '' || $uid === '') {
                continue;
            }
            $out[$gid][$uid] = true;
        }

        return $out;
    }

    /**
     * @param list<string> $seedIds
     *
     * @return list<string>
     */
    private function properAncestors(array $seedIds): array
    {
        $out = [];
        foreach ($seedIds as $seed) {
            if ($seed === '') {
                continue;
            }
            try {
                $rows = $this->db->table('group_closure')
                    ->select('ancestor_id')
                    ->where('descendant_id', $seed)
                    ->where('distance >', 0)
                    ->get()->getResultArray();
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $r) {
                $id = (string) ($r['ancestor_id'] ?? '');
                if ($id !== '') {
                    $out[$id] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * @param list<string> $groupIds
     *
     * @return array<string,list<array{group_id:string,group_name:string}>>
     */
    private function leadersOf(string $organizationId, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $rows = $this->db->table('groups')
            ->select('id, name, leader_user_id')
            ->where('organization_id', $organizationId)
            ->whereIn('id', $groupIds)
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $uid = (string) ($r['leader_user_id'] ?? '');
            if ($uid === '') {
                continue;
            }
            $out[$uid][] = [
                'group_id'   => (string) ($r['id'] ?? ''),
                'group_name' => (string) ($r['name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $userIds
     *
     * @return array<string,array{display_name:string,date_of_birth:string}>
     */
    private function peopleWithDob(string $organizationId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $this->db->table('users')
            ->select('id, display_name, date_of_birth')
            ->where('organization_id', $organizationId)
            ->whereIn('id', $userIds)
            ->where('date_of_birth IS NOT NULL', null, false)
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $id  = (string) ($r['id'] ?? '');
            $dob = (string) ($r['date_of_birth'] ?? '');
            if ($id === '' || $dob === '') {
                continue;
            }
            $out[$id] = [
                'display_name'  => (string) ($r['display_name'] ?? ''),
                'date_of_birth' => $dob,
            ];
        }

        return $out;
    }

    private static function dateInYear(int $year, int $month, int $day, DateTimeZone $tz): ?DateTimeImmutable
    {
        if ($month === 2 && $day === 29 && ! checkdate(2, 29, $year)) {
            $day = 28;
        }
        if (! checkdate($month, $day, $year)) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day), $tz);

        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
}
