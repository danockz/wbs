<?php

declare(strict_types=1);

/**
 * `join_group` decision side-effects test (Phase 0: gap R3).
 *
 * recordDecision() previously only appended a `prospect_decisions` row — for the
 * canonical `join_group` decision (the hinge of conversion→integration) it did
 * NOTHING: no membership, no journey signal, no stage move. This test proves the
 * decision now:
 *   - creates/requests a group_members row via the membership port under the
 *     group's join policy (open → active, approval → pending),
 *   - emits a journey signal via the journey port,
 *   - moves the prospect's mirrored journey_stage off 'prospect',
 *   - still records the append-only decision history,
 * and that NON-join_group decisions (and join_group with no target group) fire
 * no side effects.
 *
 *   php app/Modules/Referrals/Services/tests/join_group_side_effects_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        /** @var list<array{table:string,set:array<string,mixed>}> */
        public array $updates = [];

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
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function get($limit = null): RS
        {
            return new RS($this->matching());
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;

            return true;
        }

        public function update(array $set): bool
        {
            $this->db->updates[] = ['table' => $this->t, 'set' => $set];
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        private function matching(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
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
    use WBS\Referrals\Services\GroupMembershipPort;
    use WBS\Referrals\Services\JourneySignalPort;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\Result;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Referrals/Services/EventRegistrarPort.php';
    require_once $root . '/app/Modules/Referrals/Services/CourseEnrollerPort.php';
    require_once $root . '/app/Modules/Referrals/Services/GroupMembershipPort.php';
    require_once $root . '/app/Modules/Referrals/Services/JourneySignalPort.php';
    require_once $root . '/app/Modules/Referrals/Support/IntegrationDecision.php';
    require_once $root . '/app/Modules/Referrals/Services/ContactBookService.php';

    $pass = 0;
    $fail = 0;
    $chk  = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    // Spy ports.
    $makeMembershipSpy = static fn (): object => new class implements GroupMembershipPort {
        public array $calls = [];
        /** ensureBelonging() calls — the "every user belongs to a group" seam. */
        public array $ensured = [];
        public Result $next;

        public function __construct()
        {
            $this->next = Result::created(['membership_id' => 'm-1', 'status' => 'active']);
        }

        public function join(string $organizationId, string $groupId, string $userId, array $opts = []): Result
        {
            $this->calls[] = compact('organizationId', 'groupId', 'userId', 'opts');

            return $this->next;
        }

        public function ensureBelonging(string $organizationId, string $userId, ?string $groupId, array $opts = []): Result
        {
            $this->ensured[] = compact('organizationId', 'userId', 'groupId', 'opts');

            return Result::ok(['belonged' => false, 'created' => true, 'membership_id' => 'm-ensure', 'group_id' => $groupId]);
        }
    };
    $makeJourneySpy = static fn (): object => new class implements JourneySignalPort {
        public array $calls = [];

        public function ingest(string $organizationId, array $data): Result
        {
            $this->calls[] = compact('organizationId', 'data');

            return Result::ok(['matched' => 1, 'applied' => [], 'proposed' => []]);
        }
    };

    $seedContact = static function (BaseConnection $db, array $over = []): void {
        $db->rows['prospects'] = [array_merge([
            'id'                => 'c-1',
            'organization_id'   => 'org-1',
            'owner_user_id'     => 'owner-1',
            'assigned_group_id' => 'grp-legon',
            'linked_user_id'    => 'u-1',
            'journey_stage'     => 'prospect',
            'full_name'         => 'Kofi',
            'email'             => 'kofi@example.test',
        ], $over)];
    };

    $clock = new Clock();

    // ── 1. join_group with an OPEN group → active membership + signal + stage ──
    echo "join_group (open group) creates an active membership, a signal, and advances the stage\n";
    {
        $db = new BaseConnection();
        $seedContact($db);
        $db->rows['groups'] = [['id' => 'grp-legon', 'organization_id' => 'org-1', 'join_policy' => 'open']];
        $mem = $makeMembershipSpy();
        $jrn = $makeJourneySpy();
        $svc = new ContactBookService($db, $clock, null, null, null, null, null, null, $mem, $jrn);

        $res = $svc->recordDecision('c-1', 'owner-1', [
            'decision_type'   => 'join_group',
            'decision_date'   => '2026-09-15',
            'target_group_id' => 'grp-legon',
        ]);

        $chk('decision recorded', $res->ok && ($res->data['decision_type'] ?? '') === 'join_group', $res->message ?? '');
        $chk('decision history row appended', count($db->rows['prospect_decisions'] ?? []) === 1);
        $chk('history row carries target_group_id', ($db->rows['prospect_decisions'][0]['target_group_id'] ?? null) === 'grp-legon');
        $chk('membership port called once', count($mem->calls) === 1);
        $chk('membership targets the group + linked user', ($mem->calls[0]['groupId'] ?? '') === 'grp-legon' && ($mem->calls[0]['userId'] ?? '') === 'u-1');
        $chk('open group → requires_approval FALSE', ($mem->calls[0]['opts']['requires_approval'] ?? true) === false);
        $chk('membership source tagged referral', ($mem->calls[0]['opts']['source'] ?? '') === 'referral');
        $chk('result surfaces membership status active', ($res->data['membership_status'] ?? '') === 'active');
        $chk('linked user was made to BELONG to a group (invariant)', count($mem->ensured) === 1
            && ($mem->ensured[0]['userId'] ?? '') === 'u-1', json_encode($mem->ensured));
        $chk('belonging targets the contact group, source=system',
            ($mem->ensured[0]['groupId'] ?? '') === 'grp-legon'
            && ($mem->ensured[0]['opts']['source'] ?? '') === 'system');
        $chk('journey signal emitted once', count($jrn->calls) === 1);
        $chk('signal action is group.joined', ($jrn->calls[0]['data']['action'] ?? '') === 'journey.signal.group.joined');
        $chk('signal advances org-wide journey (group_id null)', array_key_exists('group_id', $jrn->calls[0]['data']) && $jrn->calls[0]['data']['group_id'] === null);
        $chk('signal scoped to the originating group', ($jrn->calls[0]['data']['scope_group_id'] ?? '') === 'grp-legon');
        $chk('prospect stage moved off prospect', ($res->data['journey_stage'] ?? '') === 'new_believer');
        $stageUpdated = false;
        foreach ($db->updates as $u) {
            if ($u['table'] === 'prospects' && ($u['set']['journey_stage'] ?? '') === 'new_believer') {
                $stageUpdated = true;
            }
        }
        $chk('prospect row stage actually written', $stageUpdated);
    }

    // ── 2. join_group with an APPROVAL group → pending membership ──────────────
    echo "join_group (approval group) requests a pending membership\n";
    {
        $db = new BaseConnection();
        // Placed in the approval group (placement = the mentor's group): the
        // decision follows the placement, so that is what the case must seed.
        $seedContact($db, ['assigned_group_id' => 'grp-cell']);
        $db->rows['groups'] = [['id' => 'grp-cell', 'organization_id' => 'org-1', 'join_policy' => 'approval']];
        $mem = $makeMembershipSpy();
        $mem->next = Result::created(['membership_id' => 'm-2', 'status' => 'pending']);
        $jrn = $makeJourneySpy();
        $svc = new ContactBookService($db, $clock, null, null, null, null, null, null, $mem, $jrn);

        $res = $svc->recordDecision('c-1', 'owner-1', [
            'decision_type'   => 'join_group',
            'decision_date'   => '2026-09-15',
            'target_group_id' => 'grp-cell',
        ]);
        $chk('decision recorded', $res->ok);
        // A submitted target that differs from the placement is IGNORED: the
        // prospect is not given a choice of group (a real move is a transfer).
        $chk('join_group targets the placement, not a submitted group',
            ($db->rows['prospect_decisions'][0]['target_group_id'] ?? null) === 'grp-cell'
            && ($mem->calls[0]['groupId'] ?? '') === 'grp-cell');
        $chk('approval group → requires_approval TRUE', ($mem->calls[0]['opts']['requires_approval'] ?? false) === true);
        $chk('result surfaces membership status pending', ($res->data['membership_status'] ?? '') === 'pending');
    }

    // ── 3. NON-join_group decision → no side effects ──────────────────────────
    echo "a non-join_group decision fires no membership / journey side effects\n";
    {
        $db = new BaseConnection();
        $seedContact($db);
        $mem = $makeMembershipSpy();
        $jrn = $makeJourneySpy();
        $svc = new ContactBookService($db, $clock, null, null, null, null, null, null, $mem, $jrn);

        $res = $svc->recordDecision('c-1', 'owner-1', [
            'decision_type' => 'salvation',
            'decision_date' => '2026-09-15',
        ]);
        $chk('decision recorded', $res->ok && ($res->data['decision_type'] ?? '') === 'salvation');
        $chk('history row appended', count($db->rows['prospect_decisions'] ?? []) === 1);
        $chk('no membership side effect', $mem->calls === []);
        $chk('no journey side effect', $jrn->calls === []);
    }

    // ── 4. join_group with NO target group → recorded, but no side effects ─────
    echo "join_group without a target group records history but no membership\n";
    {
        $db = new BaseConnection();
        $seedContact($db);
        $mem = $makeMembershipSpy();
        $jrn = $makeJourneySpy();
        $svc = new ContactBookService($db, $clock, null, null, null, null, null, null, $mem, $jrn);

        $res = $svc->recordDecision('c-1', 'owner-1', [
            'decision_type' => 'join_group',
            'decision_date' => '2026-09-15',
        ]);
        $chk('decision recorded', $res->ok);
        $chk('history row appended', count($db->rows['prospect_decisions'] ?? []) === 1);
        $chk('no membership without a target group', $mem->calls === []);
    }

    // ── 5. Ports absent (older wiring) → decision still recorded, no crash ─────
    echo "ports absent → decision still recorded (no side effects, no crash)\n";
    {
        $db = new BaseConnection();
        $seedContact($db);
        $db->rows['groups'] = [['id' => 'grp-legon', 'organization_id' => 'org-1', 'join_policy' => 'open']];
        $svc = new ContactBookService($db, $clock);

        $res = $svc->recordDecision('c-1', 'owner-1', [
            'decision_type'   => 'join_group',
            'decision_date'   => '2026-09-15',
            'target_group_id' => 'grp-legon',
        ]);
        $chk('decision recorded even without ports', $res->ok);
        $chk('history row appended', count($db->rows['prospect_decisions'] ?? []) === 1);
    }

    // ── 6. Not owned → forbidden, nothing recorded ────────────────────────────
    echo "a contact the caller does not own is rejected\n";
    {
        $db = new BaseConnection();
        $seedContact($db);
        $mem = $makeMembershipSpy();
        $svc = new ContactBookService($db, $clock, null, null, null, null, null, null, $mem, $makeJourneySpy());
        $res = $svc->recordDecision('c-1', 'someone-else', [
            'decision_type'   => 'join_group',
            'decision_date'   => '2026-09-15',
            'target_group_id' => 'grp-legon',
        ]);
        $chk('non-owner rejected', ! $res->ok && $res->code === 'CONTACT_FORBIDDEN');
        $chk('nothing recorded for a non-owner', ($db->rows['prospect_decisions'] ?? []) === []);
        $chk('no membership for a non-owner', $mem->calls === []);
    }

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
