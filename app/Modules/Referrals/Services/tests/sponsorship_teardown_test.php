<?php

declare(strict_types=1);

/**
 * SponsorshipService teardown + merge consumers test (Theme B — R7).
 *
 * Over an in-memory DB fake, proves:
 *
 *  onAccountTornDown (deactivate/suspend/anonymize):
 *   - the subject's ACTIVE referral links -> disabled (a gone sponsor stops
 *     auto-linking new prospects), other referrers' links untouched;
 *   - historical sponsorship edges are NOT rewritten;
 *   - idempotent (re-run disables 0); empty inputs -> 0.
 *
 *  reassignForMerge (person merge):
 *   - the loser's active DOWNLINE is re-parented to the survivor (close-old +
 *     open-new; history retained), and a downline member who IS the survivor is
 *     skipped (no self-sponsor) with only their historical edge closed;
 *   - the loser's OWN active member edge is closed;
 *   - the loser's referral links are re-pointed to the survivor;
 *   - loser == survivor / empty inputs -> all-zero no-op;
 *   - idempotent (a re-run finds nothing to move).
 *
 *   php app/Modules/Referrals/Services/tests/sponsorship_teardown_test.php
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

        // The service wraps assign() in a transaction; the fake treats it as a
        // no-op boundary (single-threaded, always succeeds).
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
        private array $neq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $k = trim((string) $k);
            if (str_ends_with($k, '!=')) {
                $this->neq[trim(substr($k, 0, -2))] = $v;

                return $this;
            }
            $this->eq[$k] = $v;

            return $this;
        }

        public function get(): RS
        {
            return new RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
        }

        public function insert(array $row): bool
        {
            // Enforce the single-active UNIQUE(active_key) guard so a broken
            // close-then-open would surface as a collision (Throwable path).
            if (($row['active_key'] ?? null) !== null) {
                foreach (($this->db->rows[$this->t] ?? []) as $r) {
                    if (($r['active_key'] ?? null) === $row['active_key']) {
                        throw new \RuntimeException('duplicate active_key');
                    }
                }
            }
            $this->db->rows[$this->t][] = $row;
            $this->db->affected = 1;

            return true;
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

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }
            foreach ($this->neq as $k => $v) {
                if ((string) ($r[$k] ?? '') === (string) $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Referrals\Services\SponsorshipService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Referrals/Services/SponsorshipService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';

    $activeEdges = static function (BaseConnection $db): array {
        return array_values(array_filter($db->rows['sponsorships'], fn ($r) => (int) $r['active'] === 1));
    };
    $edgeFor = static function (BaseConnection $db, string $member): ?array {
        foreach ($db->rows['sponsorships'] as $r) {
            if ($r['member_id'] === $member && (int) $r['active'] === 1) {
                return $r;
            }
        }

        return null;
    };

    // ======================================================================
    // onAccountTornDown — disable the subject's active referral links.
    // ======================================================================
    $db = new BaseConnection();
    $db->rows['referral_links'] = [
        ['id' => 'l1', 'organization_id' => $ORG, 'referrer_id' => 'gone', 'status' => 'active', 'active_key' => null],
        ['id' => 'l2', 'organization_id' => $ORG, 'referrer_id' => 'gone', 'status' => 'active', 'active_key' => null],
        ['id' => 'l3', 'organization_id' => $ORG, 'referrer_id' => 'gone', 'status' => 'disabled', 'active_key' => null],
        ['id' => 'l4', 'organization_id' => $ORG, 'referrer_id' => 'other', 'status' => 'active', 'active_key' => null],
    ];
    // A historical edge that must NOT be rewritten by teardown.
    $db->rows['sponsorships'] = [
        ['id' => 's1', 'organization_id' => $ORG, 'member_id' => 'child', 'sponsor_id' => 'gone', 'active' => 1, 'active_key' => 'child', 'effective_to' => null, 'reason' => null],
    ];

    $svc = new SponsorshipService($db, new Clock());

    $n = $svc->onAccountTornDown($ORG, 'gone', 'account.deactivated');
    $statusOf = static function (BaseConnection $db, string $id): string {
        foreach ($db->rows['referral_links'] as $r) {
            if ($r['id'] === $id) {
                return (string) $r['status'];
            }
        }

        return '';
    };
    $chk('teardown disables 2 active links', $n === 2, (string) $n);
    $chk('active link l1 -> disabled', $statusOf($db, 'l1') === 'disabled');
    $chk('active link l2 -> disabled', $statusOf($db, 'l2') === 'disabled');
    $chk('already-disabled l3 untouched', $statusOf($db, 'l3') === 'disabled');
    $chk('other referrer l4 untouched', $statusOf($db, 'l4') === 'active');
    $chk('teardown does NOT rewrite historical sponsor edge', $edgeFor($db, 'child')['sponsor_id'] === 'gone'
        && (int) $edgeFor($db, 'child')['active'] === 1);

    $chk('teardown idempotent (re-run disables 0)', $svc->onAccountTornDown($ORG, 'gone', 'x') === 0);
    $chk('teardown empty org -> 0', $svc->onAccountTornDown('', 'gone', 'x') === 0);
    $chk('teardown empty subject -> 0', $svc->onAccountTornDown($ORG, '', 'x') === 0);

    // ======================================================================
    // reassignForMerge — re-point loser's downline + links to the survivor.
    // ======================================================================
    $db = new BaseConnection();
    $db->rows['sponsorships'] = [
        // loser's OWN active edge (loser sponsored by grandparent).
        ['id' => 'e0', 'organization_id' => $ORG, 'member_id' => 'loser', 'sponsor_id' => 'grand', 'active' => 1, 'active_key' => 'loser', 'effective_to' => null, 'reason' => null],
        // loser's downline: a, b.
        ['id' => 'e1', 'organization_id' => $ORG, 'member_id' => 'a', 'sponsor_id' => 'loser', 'active' => 1, 'active_key' => 'a', 'effective_to' => null, 'reason' => null],
        ['id' => 'e2', 'organization_id' => $ORG, 'member_id' => 'b', 'sponsor_id' => 'loser', 'active' => 1, 'active_key' => 'b', 'effective_to' => null, 'reason' => null],
        // the SURVIVOR is themselves in the loser's downline (edge case).
        ['id' => 'e3', 'organization_id' => $ORG, 'member_id' => 'survivor', 'sponsor_id' => 'loser', 'active' => 1, 'active_key' => 'survivor', 'effective_to' => null, 'reason' => null],
        // an unrelated edge, must be left alone.
        ['id' => 'e4', 'organization_id' => $ORG, 'member_id' => 'z', 'sponsor_id' => 'someone', 'active' => 1, 'active_key' => 'z', 'effective_to' => null, 'reason' => null],
    ];
    $db->rows['referral_links'] = [
        ['id' => 'rl1', 'organization_id' => $ORG, 'referrer_id' => 'loser', 'status' => 'active', 'active_key' => null],
        ['id' => 'rl2', 'organization_id' => $ORG, 'referrer_id' => 'loser', 'status' => 'disabled', 'active_key' => null],
        ['id' => 'rl3', 'organization_id' => $ORG, 'referrer_id' => 'other', 'status' => 'active', 'active_key' => null],
    ];

    $svc = new SponsorshipService($db, new Clock());
    $res = $svc->reassignForMerge($ORG, 'loser', 'survivor');

    $chk('merge repoints 2 downline (a,b)', $res['downline_repointed'] === 2, json_encode($res));
    $chk('merge skips survivor-as-downline (1)', $res['downline_skipped'] === 1, json_encode($res));
    $chk('merge closes loser own member edge (1)', $res['member_edges_closed'] === 1, json_encode($res));
    $chk('merge repoints loser links (1 row: rl1+rl2 by referrer)', $res['links_repointed'] === 2, json_encode($res));

    $chk('a now sponsored by survivor', ($edgeFor($db, 'a')['sponsor_id'] ?? '') === 'survivor');
    $chk('b now sponsored by survivor', ($edgeFor($db, 'b')['sponsor_id'] ?? '') === 'survivor');
    $chk('survivor edge under loser is CLOSED (no self-sponsor)', $edgeFor($db, 'survivor') === null);
    $chk('loser own edge closed', $edgeFor($db, 'loser') === null);
    $chk('unrelated edge z untouched', ($edgeFor($db, 'z')['sponsor_id'] ?? '') === 'someone');

    // history retained: old a/b edges still present but inactive.
    $inactiveAB = array_filter($db->rows['sponsorships'], fn ($r) => in_array($r['member_id'], ['a', 'b'], true)
        && $r['sponsor_id'] === 'loser' && (int) $r['active'] === 0);
    $chk('old a/b -> loser edges retained as history (inactive)', count($inactiveAB) === 2, (string) count($inactiveAB));

    // links re-pointed to survivor.
    $referrerOf = static function (BaseConnection $db, string $id): string {
        foreach ($db->rows['referral_links'] as $r) {
            if ($r['id'] === $id) {
                return (string) $r['referrer_id'];
            }
        }

        return '';
    };
    $chk('loser link rl1 -> survivor', $referrerOf($db, 'rl1') === 'survivor');
    $chk('loser link rl2 -> survivor (even if disabled)', $referrerOf($db, 'rl2') === 'survivor');
    $chk('other referrer rl3 untouched', $referrerOf($db, 'rl3') === 'other');

    // single-active invariant preserved for the survivor line.
    $survivorActive = array_filter($activeEdges($db), fn ($r) => $r['sponsor_id'] === 'survivor');
    $chk('a,b now the survivor active downline', count($survivorActive) === 2);

    // idempotent re-run — nothing left to move.
    $res2 = $svc->reassignForMerge($ORG, 'loser', 'survivor');
    $chk('merge idempotent: re-run repoints 0', $res2['downline_repointed'] === 0, json_encode($res2));
    $chk('merge idempotent: re-run closes 0 edges', $res2['member_edges_closed'] === 0);
    $chk('merge idempotent: re-run repoints 0 links', $res2['links_repointed'] === 0);

    // guards.
    $zero = ['downline_repointed' => 0, 'downline_skipped' => 0, 'member_edges_closed' => 0, 'links_repointed' => 0];
    $chk('merge loser==survivor -> no-op', $svc->reassignForMerge($ORG, 'x', 'x') === $zero);
    $chk('merge empty loser -> no-op', $svc->reassignForMerge($ORG, '', 'survivor') === $zero);
    $chk('merge empty survivor -> no-op', $svc->reassignForMerge($ORG, 'loser', '') === $zero);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
