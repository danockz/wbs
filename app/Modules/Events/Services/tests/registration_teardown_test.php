<?php

declare(strict_types=1);

/**
 * RegistrationService E-B2 test — account teardown + merge reactions.
 *
 * TEARDOWN (releaseActiveForSubject): a gone person's FUTURE event footprint is
 * released so seats/holds return to inventory:
 *   - a registered seat on a published event is cancelled AND the next
 *     waitlisted person is promoted into it;
 *   - a waitlisted entry is cancelled + its waitlist row expired (no promotion);
 *   - a registration on a COMPLETED event is left as history;
 *   - live ticket_holds are released; stray waiting waitlist rows expired;
 *   - idempotent re-run releases 0; empty org/user -> bad-input fail.
 *
 * MERGE (reassignForMerge): the loser's footprint re-points to the survivor,
 * deduped per-event:
 *   - a registration for an event the survivor isn't on re-points; a duplicate
 *     is superseded (cancelled);
 *   - present attendance re-points (active_key rebuilt) or is voided on dup;
 *   - live holds re-point or release; waitlist entries re-point or expire;
 *   - loser==survivor / empty -> all-zero no-op.
 *
 *   php app/Modules/Events/Services/tests/registration_teardown_test.php
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
        /** @var array<string,mixed> */
        private array $eq = [];
        /** @var array<string,list<string>> */
        private array $in = [];
        private ?string $orderKey = null;

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

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function orderBy($k, $dir = 'ASC')
        {
            $this->orderKey = trim((string) $k);

            return $this;
        }

        /** @return list<array<string,mixed>> */
        private function filtered(): array
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($this->orderKey !== null) {
                usort($rows, fn ($a, $b) => ($a[$this->orderKey] ?? 0) <=> ($b[$this->orderKey] ?? 0));
            }

            return $rows;
        }

        public function get(): RS
        {
            return new RS($this->filtered());
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
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Events\Services\RegistrationService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Events/Services/RegistrationService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $rowById = static function (BaseConnection $db, string $table, string $id): array {
        foreach ($db->rows[$table] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };
    $svcOf = static fn (BaseConnection $db) => new RegistrationService($db, new Clock());

    // ======================= TEARDOWN =======================================
    $db = new BaseConnection();
    $db->rows['events'] = [
        ['id' => 'ev-pub', 'status' => 'published'],
        ['id' => 'ev-done', 'status' => 'completed'],
    ];
    $db->rows['event_registrations'] = [
        ['id' => 'r-seat', 'organization_id' => $ORG, 'event_id' => 'ev-pub', 'user_id' => 'gone', 'status' => 'registered'],
        ['id' => 'r-wait', 'organization_id' => $ORG, 'event_id' => 'ev-pub', 'user_id' => 'gone', 'status' => 'waitlisted'], // (2nd event would be needed realistically; ok for counts)
        ['id' => 'r-done', 'organization_id' => $ORG, 'event_id' => 'ev-done', 'user_id' => 'gone', 'status' => 'registered'],
        ['id' => 'r-next', 'organization_id' => $ORG, 'event_id' => 'ev-pub', 'user_id' => 'nextp', 'status' => 'waitlisted'],
        ['id' => 'r-other', 'organization_id' => $ORG, 'event_id' => 'ev-pub', 'user_id' => 'bystander', 'status' => 'registered'],
    ];
    $db->rows['waitlist_entries'] = [
        ['id' => 'w-next', 'organization_id' => $ORG, 'event_id' => 'ev-pub', 'user_id' => 'nextp', 'status' => 'waiting', 'position' => 1],
        ['id' => 'w-gone', 'organization_id' => $ORG, 'event_id' => 'ev-pub', 'user_id' => 'gone', 'status' => 'waiting', 'position' => 2],
    ];
    $db->rows['ticket_holds'] = [
        ['id' => 'h-gone', 'organization_id' => $ORG, 'event_id' => 'ev-pub', 'user_id' => 'gone', 'status' => 'held'],
        ['id' => 'h-other', 'organization_id' => $ORG, 'event_id' => 'ev-pub', 'user_id' => 'bystander', 'status' => 'held'],
    ];
    $svc = $svcOf($db);
    $res = $svc->releaseActiveForSubject($ORG, 'gone', 'account.deactivated');
    $chk('teardown ok', $res->ok === true, (string) ($res->code ?? ''));
    $chk('releases 2 active regs (seat + waitlisted)', ($res->data['released_registrations'] ?? -1) === 2, json_encode($res->data));
    $chk('promotes next person', ($res->data['promoted'] ?? []) === ['nextp'], json_encode($res->data['promoted'] ?? []));
    $chk('r-seat cancelled', $rowById($db, 'event_registrations', 'r-seat')['status'] === 'cancelled');
    $chk('r-next promoted to registered', $rowById($db, 'event_registrations', 'r-next')['status'] === 'registered');
    $chk('w-next promoted', $rowById($db, 'waitlist_entries', 'w-next')['status'] === 'promoted');
    $chk('r-wait cancelled', $rowById($db, 'event_registrations', 'r-wait')['status'] === 'cancelled');
    $chk('completed-event reg untouched (history)', $rowById($db, 'event_registrations', 'r-done')['status'] === 'registered');
    $chk('bystander reg untouched', $rowById($db, 'event_registrations', 'r-other')['status'] === 'registered');
    $chk('gone hold released', $rowById($db, 'ticket_holds', 'h-gone')['status'] === 'released');
    $chk('bystander hold untouched', $rowById($db, 'ticket_holds', 'h-other')['status'] === 'held');
    $chk('gone waitlist row expired', $rowById($db, 'waitlist_entries', 'w-gone')['status'] === 'expired');
    $chk('releases 1 hold', ($res->data['released_holds'] ?? -1) === 1);

    // idempotent re-run
    $res2 = $svc->releaseActiveForSubject($ORG, 'gone', 'account.deactivated');
    $chk('teardown idempotent re-run 0 regs', ($res2->data['released_registrations'] ?? -1) === 0);
    $chk('teardown empty org fails', $svc->releaseActiveForSubject('', 'gone', 'x')->ok === false);
    $chk('teardown empty user fails', $svc->releaseActiveForSubject($ORG, '', 'x')->ok === false);

    // ======================= MERGE ==========================================
    $db3 = new BaseConnection();
    $db3->rows['events'] = [['id' => 'e1', 'status' => 'published'], ['id' => 'e2', 'status' => 'published']];
    $db3->rows['event_registrations'] = [
        ['id' => 'lr1', 'organization_id' => $ORG, 'event_id' => 'e1', 'user_id' => 'loser', 'status' => 'registered'], // repoint
        ['id' => 'lr2', 'organization_id' => $ORG, 'event_id' => 'e2', 'user_id' => 'loser', 'status' => 'registered'], // dup -> superseded
        ['id' => 'sr2', 'organization_id' => $ORG, 'event_id' => 'e2', 'user_id' => 'surv', 'status' => 'registered'],
    ];
    $db3->rows['event_attendance'] = [
        ['id' => 'la1', 'organization_id' => $ORG, 'event_id' => 'e1', 'user_id' => 'loser', 'status' => 'present', 'active_key' => 'e1:loser'], // repoint
        ['id' => 'la2', 'organization_id' => $ORG, 'event_id' => 'e2', 'user_id' => 'loser', 'status' => 'present', 'active_key' => 'e2:loser'], // dup -> voided
        ['id' => 'sa2', 'organization_id' => $ORG, 'event_id' => 'e2', 'user_id' => 'surv', 'status' => 'present', 'active_key' => 'e2:surv'],
    ];
    $db3->rows['ticket_holds'] = [
        ['id' => 'lh1', 'organization_id' => $ORG, 'event_id' => 'e1', 'user_id' => 'loser', 'status' => 'held'], // repoint
    ];
    $db3->rows['waitlist_entries'] = [
        ['id' => 'lw1', 'organization_id' => $ORG, 'event_id' => 'e1', 'user_id' => 'loser', 'status' => 'waiting', 'position' => 5], // repoint
    ];
    $out = $svcOf($db3)->reassignForMerge($ORG, 'loser', 'surv');
    $chk('merge repoints 1 registration', $out['registrations_repointed'] === 1, json_encode($out));
    $chk('merge supersedes 1 dup registration', $out['registrations_superseded'] === 1);
    $chk('lr1 now owned by survivor', $rowById($db3, 'event_registrations', 'lr1')['user_id'] === 'surv');
    $chk('lr2 dup cancelled', $rowById($db3, 'event_registrations', 'lr2')['status'] === 'cancelled');
    $chk('merge repoints 1 attendance', $out['attendance_repointed'] === 1);
    $chk('merge voids 1 dup attendance', $out['attendance_voided'] === 1);
    $chk('la1 attendance repointed + active_key rebuilt', $rowById($db3, 'event_attendance', 'la1')['user_id'] === 'surv'
        && $rowById($db3, 'event_attendance', 'la1')['active_key'] === 'e1:surv');
    $chk('la2 dup voided + active_key null', $rowById($db3, 'event_attendance', 'la2')['status'] === 'voided'
        && $rowById($db3, 'event_attendance', 'la2')['active_key'] === null);
    $chk('merge repoints 1 hold', $out['holds_repointed'] === 1);
    $chk('lh1 hold repointed', $rowById($db3, 'ticket_holds', 'lh1')['user_id'] === 'surv');
    $chk('merge repoints 1 waitlist', $out['waitlist_repointed'] === 1);
    $chk('lw1 waitlist repointed', $rowById($db3, 'waitlist_entries', 'lw1')['user_id'] === 'surv');

    // no-op guards
    $noop = $svcOf($db3)->reassignForMerge($ORG, 'x', 'x');
    $chk('merge loser==survivor no-op', $noop['registrations_repointed'] === 0 && $noop['attendance_repointed'] === 0);
    $chk('merge empty survivor no-op', $svcOf($db3)->reassignForMerge($ORG, 'loser', '')['registrations_repointed'] === 0);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
