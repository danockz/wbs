<?php

declare(strict_types=1);

/**
 * ContactBookService::processFollowUps test (Theme C — R4).
 *
 * The outreach triage board rotted: `next_follow_up_at`/`temperature` had indexes
 * and tiles but nothing acted on them. Over an in-memory DB fake + notifier spy,
 * proves:
 *   - a contact due for follow-up (next_follow_up_at <= now) with an owner gets
 *     exactly one reminder, keyed per due date;
 *   - a due contact with NO owner is skipped;
 *   - a not-yet-due contact is not reminded;
 *   - temperature decays hot→warm after 14d untouched and warm→cold after 30d,
 *     measured from last_contacted_at (falling back to created_at);
 *   - a recently-contacted hot contact does NOT decay;
 *   - cold is terminal;
 *   - the pass is idempotent (a second run decays nothing further);
 *   - org scoping only touches the requested org.
 *
 *   php app/Modules/Referrals/Services/tests/follow_up_decay_test.php
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
        private array $notNull = [];
        private array $cmp = [];       // list of [field, op, value]
        private array $coalesceCmp = []; // list of [fieldA, fieldB, op, value]

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if ($escape === false && str_contains($k, 'IS NOT NULL')) {
                $this->notNull[] = trim(str_replace('IS NOT NULL', '', $k));

                return $this;
            }
            if (str_starts_with($k, 'COALESCE(')) {
                // COALESCE(last_contacted_at, created_at) <= ?
                preg_match('/COALESCE\(([^,]+),\s*([^\)]+)\)\s*(<=|>=|<|>)/', $k, $m);
                $this->coalesceCmp[] = [trim($m[1]), trim($m[2]), $m[3], $v];

                return $this;
            }
            foreach (['<=', '>=', '<', '>'] as $op) {
                if (str_ends_with($k, $op)) {
                    $this->cmp[] = [trim(substr($k, 0, -strlen($op))), $op, $v];

                    return $this;
                }
            }
            $this->eq[$k] = $v;

            return $this;
        }

        public function orderBy($col, $dir = 'ASC')
        {
            return $this;
        }

        public function get($limit = null): RS
        {
            $out = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($limit !== null) {
                $out = array_slice($out, 0, (int) $limit);
            }

            return new RS($out);
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

        private function cmpOk(string $cell, string $op, string $v): bool
        {
            $c = strcmp($cell, $v);

            return match ($op) {
                '<=' => $c <= 0,
                '>=' => $c >= 0,
                '<'  => $c < 0,
                '>'  => $c > 0,
                default => false,
            };
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }
            foreach ($this->notNull as $f) {
                if (($r[$f] ?? null) === null || (string) $r[$f] === '') {
                    return false;
                }
            }
            foreach ($this->cmp as [$f, $op, $v]) {
                if (($r[$f] ?? null) === null) {
                    return false;
                }
                if (! $this->cmpOk((string) $r[$f], $op, (string) $v)) {
                    return false;
                }
            }
            foreach ($this->coalesceCmp as [$a, $b, $op, $v]) {
                $cell = $r[$a] ?? $r[$b] ?? null;
                if ($cell === null) {
                    return false;
                }
                if (! $this->cmpOk((string) $cell, $op, (string) $v)) {
                    return false;
                }
            }

            return true;
        }
    }
}

// Notifier spy.
namespace WBS\Referrals\Services {
    require_once dirname(__DIR__, 5) . '/app/Modules/Referrals/Services/FollowUpNotifierPort.php';

    class SpyNotifier implements FollowUpNotifierPort
    {
        /** @var list<array{dedupe:string,owner:string,contact:string}> */
        public array $sent = [];

        public function remindFollowUpDue(string $organizationId, string $ownerUserId, string $contactId, string $dedupeKey, array $context = []): void
        {
            $this->sent[] = ['dedupe' => $dedupeKey, 'owner' => $ownerUserId, 'contact' => $contactId];
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Referrals\Services\ContactBookService;
    use WBS\Referrals\Services\SpyNotifier;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/ScopeMode.php';
    require_once $root . '/app/Modules/Referrals/Services/FollowUpNotifierPort.php';
    require_once $root . '/app/Modules/Referrals/Services/ContactBookService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
    $now  = '2026-06-01 12:00:00';
    $d10  = '2026-05-22 12:00:00'; // 10 days ago
    $d20  = '2026-05-12 12:00:00'; // 20 days ago
    $d40  = '2026-04-22 12:00:00'; // 40 days ago

    $ORG = 'org-1';
    $db  = new BaseConnection();
    $db->rows['prospects'] = [
        // due follow-up, has owner -> remind
        ['id' => 'p1', 'organization_id' => $ORG, 'owner_user_id' => 'u1', 'display_name' => 'Ada', 'temperature' => 'warm', 'next_follow_up_at' => '2026-05-31 09:00:00', 'last_contacted_at' => $d10, 'created_at' => $d40],
        // due, NO owner -> skip reminder
        ['id' => 'p2', 'organization_id' => $ORG, 'owner_user_id' => null, 'temperature' => 'cold', 'next_follow_up_at' => '2026-05-30 09:00:00', 'last_contacted_at' => $d10, 'created_at' => $d40],
        // not yet due
        ['id' => 'p3', 'organization_id' => $ORG, 'owner_user_id' => 'u1', 'temperature' => 'hot', 'next_follow_up_at' => '2026-07-01 09:00:00', 'last_contacted_at' => $now, 'created_at' => $now],
        // hot, untouched 20d -> decays to warm
        ['id' => 'p4', 'organization_id' => $ORG, 'owner_user_id' => 'u2', 'temperature' => 'hot', 'next_follow_up_at' => null, 'last_contacted_at' => $d20, 'created_at' => $d40],
        // hot, contacted 10d ago -> stays hot (< 14d)
        ['id' => 'p5', 'organization_id' => $ORG, 'owner_user_id' => 'u2', 'temperature' => 'hot', 'next_follow_up_at' => null, 'last_contacted_at' => $d10, 'created_at' => $d40],
        // warm, untouched 40d -> decays to cold
        ['id' => 'p6', 'organization_id' => $ORG, 'owner_user_id' => 'u2', 'temperature' => 'warm', 'next_follow_up_at' => null, 'last_contacted_at' => $d40, 'created_at' => $d40],
        // cold, ancient -> terminal, no change
        ['id' => 'p7', 'organization_id' => $ORG, 'owner_user_id' => 'u2', 'temperature' => 'cold', 'next_follow_up_at' => null, 'last_contacted_at' => $d40, 'created_at' => $d40],
        // other org, hot untouched -> not touched under org scope
        ['id' => 'p8', 'organization_id' => 'org-2', 'owner_user_id' => 'u9', 'temperature' => 'hot', 'next_follow_up_at' => null, 'last_contacted_at' => $d40, 'created_at' => $d40],
    ];

    $spy = new SpyNotifier();
    $svc = new ContactBookService($db, new Clock(), null, null, null, null, null, null, null, null, $spy);

    $tempOf = static function (BaseConnection $db, string $id): string {
        foreach ($db->rows['prospects'] as $r) {
            if ($r['id'] === $id) {
                return (string) $r['temperature'];
            }
        }

        return '';
    };

    // ---- run under org-1 scope ---------------------------------------------
    $res = $svc->processFollowUps($ORG, 500);

    $chk('reminded 1 (p1 only)', $res['reminded'] === 1, (string) $res['reminded']);
    $chk('reminder went to p1', ($spy->sent[0]['contact'] ?? '') === 'p1');
    $chk('reminder dedupe keyed per due date', str_contains($spy->sent[0]['dedupe'] ?? '', 'p1:2026-05-31 09:00:00'));
    $chk('no-owner due contact not reminded', count(array_filter($spy->sent, fn ($s) => $s['contact'] === 'p2')) === 0);
    $chk('not-yet-due not reminded', count(array_filter($spy->sent, fn ($s) => $s['contact'] === 'p3')) === 0);

    $chk('p4 hot->warm (20d untouched)', $tempOf($db, 'p4') === 'warm');
    $chk('p5 stays hot (10d < 14d)', $tempOf($db, 'p5') === 'hot');
    $chk('p6 warm->cold (40d untouched)', $tempOf($db, 'p6') === 'cold');
    $chk('p7 cold stays cold (terminal)', $tempOf($db, 'p7') === 'cold');
    $chk('other-org p8 untouched under scope', $tempOf($db, 'p8') === 'hot');
    $chk('decayed count = 2 (p4, p6)', $res['decayed'] === 2, (string) $res['decayed']);

    // ---- idempotent second pass --------------------------------------------
    $spy->sent = [];
    $res2 = $svc->processFollowUps($ORG, 500);
    // p1 reminder re-sends (dedupe is enforced by NotificationService, not here) —
    // but decay must be stable: p4 is now warm@20d (< 30d) so no further decay;
    // p6 already cold. So decayed must be 0.
    $chk('second pass decays 0 (idempotent)', $res2['decayed'] === 0, (string) $res2['decayed']);
    $chk('p4 still warm after re-run', $tempOf($db, 'p4') === 'warm');

    Clock::freeze(null);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
