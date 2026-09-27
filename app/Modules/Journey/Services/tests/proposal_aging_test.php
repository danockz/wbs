<?php

declare(strict_types=1);

/**
 * JourneyProposalAgingService::sweepPendingProposals() — J6 proposal aging.
 *
 * A pending journey-stage proposal used to sit in a leader's queue forever. This
 * proves the uniform remind → escalate → timeout lifecycle:
 *   - a proposal younger than the remind SLA is untouched;
 *   - past the remind SLA, the reviewing leaders (active `leader` memberships of
 *     the proposal's group) are reminded once and the watermark/counter advance;
 *     a second pass inside the interval does NOT re-remind (idempotent);
 *   - past the escalate SLA, the proposal escalates exactly once (watermark);
 *   - with timeout_days=0 a very old proposal is NEVER auto-terminated; with
 *     timeout_days>0 it is marked `rejected` (default) or `superseded` per
 *     proposal_timeout_action, with a system actor, and leaves the pending queue;
 *   - a decided (non-pending) proposal is never scanned;
 *   - notifications are best-effort (no leaders / no service = no crash);
 *   - per-org thresholds + org scoping.
 *
 *   php app/Modules/Journey/Services/tests/proposal_aging_test.php
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
        private array $conds = [];
        private ?string $orderCol = null;
        private ?int $limit = null;
        /** @var array{table:string,alias:string,on:string}|null */
        private ?array $join = null;
        private string $alias = '';

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
            $parts = preg_split('/\s+/', trim($t));
            $this->t = $parts[0];
            $this->alias = $parts[1] ?? '';
        }

        public function select($s, $escape = true)
        {
            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            $this->conds[] = ['k' => (string) $k, 'v' => $v];

            return $this;
        }

        public function join($table, $on, $type = 'inner')
        {
            $parts = preg_split('/\s+/', trim((string) $table));
            $this->join = ['table' => $parts[0], 'alias' => $parts[1] ?? '', 'on' => (string) $on];

            return $this;
        }

        public function orderBy($col, $dir = 'ASC')
        {
            $this->orderCol = (string) $col;

            return $this;
        }

        public function limit($n, $offset = 0)
        {
            $this->limit = (int) $n;

            return $this;
        }

        public function get($limit = null): RS
        {
            $rows = $this->matchingRows();
            if ($this->orderCol !== null) {
                $col = $this->stripAlias($this->orderCol);
                usort($rows, fn ($a, $b) => strcmp((string) ($a[$col] ?? ''), (string) ($b[$col] ?? '')));
            }
            $lim = $limit ?? $this->limit;
            if ($lim !== null) {
                $rows = array_slice($rows, 0, (int) $lim);
            }

            return new RS($rows);
        }

        public function countAllResults(): int
        {
            return count($this->matchingRows());
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

        private function stripAlias(string $k): string
        {
            $pos = strpos($k, '.');

            return $pos === false ? $k : substr($k, $pos + 1);
        }

        private function matchingRows(): array
        {
            $base = array_values($this->db->rows[$this->t] ?? []);

            // Inner join for role_assignments ra JOIN roles r ON r.id = ra.role_id
            if ($this->join !== null) {
                $joinRows = $this->db->rows[$this->join['table']] ?? [];
                $merged = [];
                foreach ($base as $b) {
                    foreach ($joinRows as $j) {
                        if (($b['role_id'] ?? null) === ($j['id'] ?? '~')) {
                            $merged[] = $b + ['code' => $j['code'] ?? null];
                        }
                    }
                }
                $base = $merged;
            }

            return array_values(array_filter($base, fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $key = $this->stripAlias((string) $c['k']);
                if ($c['v'] === null) {
                    if (($r[$key] ?? null) !== null) {
                        return false;
                    }
                    continue;
                }
                if ((string) ($r[$key] ?? '') !== (string) $c['v']) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace WBS\Notifications\Services {
    class NotificationService
    {
        /** @var list<array{org:string,user:string,category:string,dedupe:string}> */
        public array $sent = [];

        public function send(string $organizationId, string $userId, string $channel, string $category, array $opts = [])
        {
            $this->sent[] = [
                'org'      => $organizationId,
                'user'     => $userId,
                'category' => $category,
                'dedupe'   => (string) ($opts['dedupe_key'] ?? ''),
            ];

            return \WBS\Shared\Support\Result::ok(['sent' => true]);
        }
    }
}

namespace WBS\Journey\Services {
    require_once dirname(__DIR__, 5) . '/app/Modules/Journey/Services/ProposalConfigPort.php';

    // Config fake (ProposalConfigPort): per-org key/value.
    class ConfigStub implements ProposalConfigPort
    {
        /** @var array<string,array<string,mixed>> */
        public array $values = [];

        public function get(string $organizationId, string $key, mixed $default = null): mixed
        {
            return $this->values[$organizationId][$key] ?? $default;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Journey\Services\ConfigStub;
    use WBS\Journey\Services\JourneyProposalAgingService;
    use WBS\Notifications\Services\NotificationService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Journey/Services/ProposalConfigPort.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyProposalAgingService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $GRP = 'grp-1';
    $NOW = '2026-09-17 12:00:00.000000';
    Clock::freeze(new \DateTimeImmutable($NOW, new \DateTimeZone('UTC')));

    $ago = static function (int $hours): string {
        return (new \DateTimeImmutable('2026-09-17 12:00:00', new \DateTimeZone('UTC')))
            ->modify("-{$hours} hours")->format('Y-m-d H:i:s');
    };

    // Defaults: remind 48h, escalate 120h, timeout 0.
    $seed = static function () use ($ORG, $GRP, $ago): BaseConnection {
        $db = new BaseConnection();
        $db->rows['journey_stage_proposals'] = [
            // fresh (10h) -> untouched
            ['id' => 'p-fresh', 'organization_id' => $ORG, 'user_id' => 'ann', 'group_id' => $GRP, 'to_stage' => 'growing', 'direction' => 'advance', 'status' => 'pending', 'created_at' => $ago(10), 'reminded_at' => null, 'reminder_count' => 0, 'escalated_at' => null],
            // 60h old -> remind (past 48h, under 120h)
            ['id' => 'p-remind', 'organization_id' => $ORG, 'user_id' => 'bob', 'group_id' => $GRP, 'to_stage' => 'growing', 'direction' => 'advance', 'status' => 'pending', 'created_at' => $ago(60), 'reminded_at' => null, 'reminder_count' => 0, 'escalated_at' => null],
            // 130h old -> escalate (past 120h), not yet escalated
            ['id' => 'p-escalate', 'organization_id' => $ORG, 'user_id' => 'cid', 'group_id' => $GRP, 'to_stage' => 'growing', 'direction' => 'advance', 'status' => 'pending', 'created_at' => $ago(130), 'reminded_at' => $ago(60), 'reminder_count' => 1, 'escalated_at' => null],
            // already decided -> never scanned
            ['id' => 'p-done', 'organization_id' => $ORG, 'user_id' => 'dan', 'group_id' => $GRP, 'to_stage' => 'growing', 'direction' => 'advance', 'status' => 'approved', 'created_at' => $ago(300), 'reminded_at' => null, 'reminder_count' => 0, 'escalated_at' => null],
        ];
        // Two active leaders of the group = reviewers.
        $db->rows['group_members'] = [
            ['id' => 'gm1', 'organization_id' => $ORG, 'group_id' => $GRP, 'user_id' => 'lead-1', 'membership_type' => 'leader', 'status' => 'active'],
            ['id' => 'gm2', 'organization_id' => $ORG, 'group_id' => $GRP, 'user_id' => 'lead-2', 'membership_type' => 'leader', 'status' => 'active'],
            ['id' => 'gm3', 'organization_id' => $ORG, 'group_id' => $GRP, 'user_id' => 'member-x', 'membership_type' => 'member', 'status' => 'active'],
            ['id' => 'gm4', 'organization_id' => $ORG, 'group_id' => $GRP, 'user_id' => 'lead-old', 'membership_type' => 'leader', 'status' => 'ended'],
        ];
        return $db;
    };

    $byId = static function (BaseConnection $db, string $id): ?array {
        foreach ($db->rows['journey_stage_proposals'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    };

    // ---- default thresholds -------------------------------------------------
    $db    = $seed();
    $cfg   = new ConfigStub();
    $notif = new NotificationService();
    $svc   = new JourneyProposalAgingService($db, new Clock(), $cfg, $notif);
    $r     = $svc->sweepPendingProposals($ORG, 100);

    $chk('scanned 3 pending (decided excluded)', $r['scanned'] === 3, json_encode($r));
    $chk('reminded 1', $r['reminded'] === 1, json_encode($r));
    $chk('escalated 1', $r['escalated'] === 1, json_encode($r));
    $chk('timed_out 0 (auto-terminate off by default)', $r['timed_out'] === 0);

    $chk('p-fresh untouched', ($byId($db, 'p-fresh')['reminded_at'] ?? null) === null);
    $chk('p-remind watermarked', ! empty($byId($db, 'p-remind')['reminded_at']));
    $chk('p-remind counter=1', (int) $byId($db, 'p-remind')['reminder_count'] === 1);
    $chk('p-escalate escalated_at stamped', ! empty($byId($db, 'p-escalate')['escalated_at']));
    $chk('p-done never touched (decided)', ($byId($db, 'p-done')['reminded_at'] ?? null) === null);

    // notifications: remind -> 2 leaders, escalate -> 2 leaders = 4 in_app sends;
    // the non-leader member and the ENDED leader are never notified.
    $chk('notified only active leaders (4 sends: 2 remind + 2 escalate)', count($notif->sent) === 4, (string) count($notif->sent));
    $recipients = array_unique(array_column($notif->sent, 'user'));
    sort($recipients);
    $chk('recipients are exactly the two active leaders', $recipients === ['lead-1', 'lead-2'], json_encode($recipients));
    $chk('2 reminder notifications', count(array_filter($notif->sent, fn ($s) => $s['category'] === 'journey_proposal_reminder')) === 2);
    $chk('2 escalation notifications', count(array_filter($notif->sent, fn ($s) => $s['category'] === 'journey_proposal_escalation')) === 2);

    // ---- idempotent: immediate second pass ---------------------------------
    $notif2 = new NotificationService();
    $svc2   = new JourneyProposalAgingService($db, new Clock(), $cfg, $notif2);
    $r2     = $svc2->sweepPendingProposals($ORG, 100);
    $chk('second pass escalated 0 (watermark holds)', $r2['escalated'] === 0, json_encode($r2));
    $chk('second pass does not re-remind just-reminded p-remind',
        (int) $byId($db, 'p-remind')['reminder_count'] === 1, (string) $byId($db, 'p-remind')['reminder_count']);
    $chk('escalated-but-open p-escalate still gets follow-up reminders (48h since last)',
        $r2['reminded'] === 1 && (int) $byId($db, 'p-escalate')['reminder_count'] === 2, json_encode($r2));

    // ---- re-remind after the interval elapses ------------------------------
    $db3 = $seed();
    foreach ($db3->rows['journey_stage_proposals'] as &$row) {
        if ($row['id'] === 'p-remind') {
            $row['reminded_at']    = $ago(50); // > 48h ago
            $row['reminder_count'] = 1;
            $row['created_at']     = $ago(80); // under 120h
        }
    }
    unset($row);
    $svc3 = new JourneyProposalAgingService($db3, new Clock(), $cfg, new NotificationService());
    $r3   = $svc3->sweepPendingProposals($ORG, 100);
    $chk('re-remind after interval: p-remind reminded again', (int) $byId($db3, 'p-remind')['reminder_count'] === 2, json_encode($r3));

    // ---- timeout enabled: default action = rejected ------------------------
    $db4  = $seed();
    $cfg4 = new ConfigStub();
    $cfg4->values[$ORG] = ['journey.proposal_timeout_days' => 5]; // 120h
    $svc4 = new JourneyProposalAgingService($db4, new Clock(), $cfg4, new NotificationService());
    $r4   = $svc4->sweepPendingProposals($ORG, 100);
    // Only p-escalate (130h) exceeds 5d.
    $chk('timeout terminated the >5d proposal', $r4['timed_out'] === 1, json_encode($r4));
    $chk('timed-out proposal is rejected (default action)', ($byId($db4, 'p-escalate')['status'] ?? null) === 'rejected');
    $chk('timed-out decided_by=system', ($byId($db4, 'p-escalate')['decided_by'] ?? null) === 'system');

    // ---- timeout action = superseded ---------------------------------------
    $db5  = $seed();
    $cfg5 = new ConfigStub();
    $cfg5->values[$ORG] = ['journey.proposal_timeout_days' => 5, 'journey.proposal_timeout_action' => 'superseded'];
    $svc5 = new JourneyProposalAgingService($db5, new Clock(), $cfg5, new NotificationService());
    $svc5->sweepPendingProposals($ORG, 100);
    $chk('timeout action=superseded honoured', ($byId($db5, 'p-escalate')['status'] ?? null) === 'superseded');

    // ---- best-effort: no notifier / no reviewers = no crash ----------------
    $db6 = $seed();
    $db6->rows['group_members'] = []; // no leaders
    $svc6 = new JourneyProposalAgingService($db6, new Clock(), new ConfigStub(), null);
    $r6   = $svc6->sweepPendingProposals($ORG, 100);
    $chk('no notifier/reviewers: still ages watermarks without error', $r6['reminded'] === 1 && $r6['escalated'] === 1, json_encode($r6));

    // ---- org scoping --------------------------------------------------------
    $db7 = $seed();
    $db7->rows['journey_stage_proposals'][] = ['id' => 'p-other', 'organization_id' => 'org-2', 'user_id' => 'zoe', 'group_id' => 'grp-2', 'to_stage' => 'growing', 'direction' => 'advance', 'status' => 'pending', 'created_at' => $ago(200), 'reminded_at' => null, 'reminder_count' => 0, 'escalated_at' => null];
    $svc7 = new JourneyProposalAgingService($db7, new Clock(), new ConfigStub(), new NotificationService());
    $r7   = $svc7->sweepPendingProposals($ORG, 100);
    $chk('org filter: other-org proposal not scanned', $r7['scanned'] === 3, json_encode($r7));
    $chk('org filter: p-other untouched', ($byId($db7, 'p-other')['escalated_at'] ?? null) === null);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
