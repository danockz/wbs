<?php

declare(strict_types=1);

/**
 * JourneySignalService assessment — rule-driven progression (Option C).
 *
 * The bridge between real-world SIGNALS (course completed, event attended, …)
 * and the journey. It holds NO hard-coded progression logic: rules live in the
 * shared RuBAC engine (facet 'membership'); this service builds context, asks
 * the engine what matched, and per matched rule applies the effect:
 *
 *   - effect 'adjust'         -> AUTO-APPLY via JourneyService (source=rule);
 *   - effect 'require_review' -> PROPOSE (queue a pending proposal);
 *   - effect 'flag'           -> PROPOSE (soft nudge);
 *   - allow/deny              -> ignored (not a journey move).
 *
 * The assessment pins:
 *   - a rule missing effect_params.to_stage is skipped;
 *   - the DIRECTION guard: a rule that would REGRESS is skipped unless it opts
 *     in with allow_regress=true; a 'same'-stage move is skipped;
 *   - auto-apply actually moves the member and tags source=rule + project_code;
 *   - propose queues a pending row, DEDUPED against an existing open proposal
 *     for the same (user, group, to_stage);
 *   - the approve/reject queue: approve applies the transition and closes the
 *     proposal (carrying project_code); a non-pending or missing proposal is a
 *     clean error; reject closes without moving;
 *   - guard rails: blank user/action -> BAD_SIGNAL; no rule engine wired ->
 *     NO_RULE_ENGINE; zero matches -> matched:0.
 *
 * Real RuleOutcome value object + real JourneyService (over a fake DB) so the
 * transition/compareStages logic is authentic; RuleEngine is `final`, so a spy
 * is registered under its FQCN and the real file is never loaded.
 *
 *   php app/Modules/Journey/Services/tests/journey_signal_test.php
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
    class QB
    {
        private array $wheres = [];
        private array $order  = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        private function key(string $k): string
        {
            $k = trim(explode(' ', trim($k))[0]);
            $p = explode('.', $k);

            return end($p);
        }

        public function where($k, $v = null)
        {
            $this->wheres[] = [$this->key((string) $k), $v];

            return $this;
        }

        public function select($s)
        {
            return $this;
        }

        public function groupBy($g)
        {
            return $this;
        }

        public function orderBy($k, $d = 'ASC')
        {
            $this->order[] = [$this->key((string) $k), strtoupper((string) $d)];

            return $this;
        }

        private function matches(array $r): bool
        {
            foreach ($this->wheres as [$c, $v]) {
                $actual = $r[$c] ?? null;
                if ($v === null) {
                    if ($actual !== null) {
                        return false;
                    }
                    continue;
                }
                if ((string) $actual !== (string) $v) {
                    return false;
                }
            }

            return true;
        }

        private function filtered(): array
        {
            $rows = array_values(array_filter($this->db->rows[$this->base()] ?? [], fn ($r) => $this->matches($r)));
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC'
                    ? (($b[$c] ?? '') <=> ($a[$c] ?? ''))
                    : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
            }

            return $rows;
        }

        public function countAllResults(): int
        {
            return count($this->filtered());
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->base()][] = $row;

            return true;
        }

        public function update(array $set): bool
        {
            foreach ($this->db->rows[$this->base()] ?? [] as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->base()][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        public function get($limit = null, $offset = 0): RS
        {
            $rows = $this->filtered();
            if ($limit !== null) {
                $rows = array_slice($rows, (int) $offset, (int) $limit);
            }

            return new RS($rows);
        }

        private function base(): string
        {
            return explode(' ', trim($this->t))[0];
        }
    }

    class RS
    {
        public function __construct(private array $r)
        {
        }

        public function getResultArray(): array
        {
            return $this->r;
        }

        public function getRowArray(): ?array
        {
            return $this->r[0] ?? null;
        }
    }
}

namespace WBS\AccessControl\Services {

    use WBS\AccessControl\Policy\RuleOutcome;

    /** Spy RuleEngine: returns a scripted RuleOutcome; records the last call. */
    final class RuleEngine
    {
        public ?RuleOutcome $next = null;

        /** @var array<string,mixed>|null */
        public ?array $lastCall = null;

        public function evaluate(string $organizationId, string $facet, string $action, array $attributes, ?string $targetGroupId = null): RuleOutcome
        {
            $this->lastCall = compact('organizationId', 'facet', 'action', 'attributes', 'targetGroupId');

            return $this->next ?? RuleOutcome::none();
        }
    }
}

namespace {
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/AccessControl/Policy/RuleOutcome.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyTransitionListener.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyService.php';
    require_once $root . '/app/Modules/Journey/Services/JourneySignalService.php';
    require_once $root . '/app/Modules/Journey/Services/ProposalSupersedeListener.php';

    use CodeIgniter\Database\BaseConnection;
    use WBS\AccessControl\Policy\RuleOutcome;
    use WBS\AccessControl\Services\RuleEngine;
    use WBS\Journey\Services\JourneyService;
    use WBS\Journey\Services\JourneySignalService;
    use WBS\Journey\Services\ProposalSupersedeListener;
    use WBS\Shared\Support\Clock;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    $ORG   = 'org1';
    $clock = new Clock();

    // Ladder + a seeded member at 'seeker'.
    $world = static function (string $startStage = 'seeker') use ($clock): array {
        $db = new BaseConnection();
        $mk = static fn (string $c, string $p, int $o): array => [
            'id' => "st_$c", 'organization_id' => 'org1', 'group_id' => null, 'code' => $c,
            'name' => ucfirst($c), 'phase' => $p, 'sort_order' => $o, 'is_entry' => $c === 'seeker' ? 1 : 0,
            'is_terminal' => $c === 'sender' ? 1 : 0, 'status' => 'active',
        ];
        $db->rows['journey_stages'] = [$mk('seeker', 'win', 10), $mk('new_believer', 'build', 20), $mk('growing', 'build', 30), $mk('sender', 'send', 40)];
        $db->rows['member_journeys'] = [[
            'id' => 'j_ann', 'organization_id' => 'org1', 'user_id' => 'ann', 'group_id' => null,
            'stage_code' => $startStage, 'stage_phase' => 'win', 'previous_stage' => null,
            'status' => 'active', 'source' => 'manual', 'source_ref' => null,
        ]];
        $db->rows['member_journey_transitions'] = [];
        $db->rows['journey_stage_proposals']    = [];
        $engine  = new RuleEngine();
        $journey = new JourneyService($db, $clock, null, []);
        $svc     = new JourneySignalService($db, $clock, $journey, $engine);

        return [$db, $svc, $engine];
    };

    $rule = static fn (string $code, string $effect, array $params): array => [
        'code' => $code, 'effect' => $effect, 'priority' => 10, 'effect_params' => $params,
    ];

    // ---- adjust auto-applies ----------------------------------------------
    echo "effect 'adjust' auto-applies a forward move\n";
    [$db, $svc, $eng] = $world();
    $eng->next = new RuleOutcome('adjust', [$rule('r_adv', 'adjust', ['to_stage' => 'new_believer'])]);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.course.completed', 'project_code' => 'foundations', 'discipler_id' => 'lead1']);
    chk('ok', $r->ok);
    chk('one applied', count($r->data['applied']) === 1 && $r->data['applied'][0]['to_stage'] === 'new_believer', json_encode($r->data));
    chk('applied direction advance', ($r->data['applied'][0]['direction'] ?? null) === 'advance');
    chk('member actually moved', ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'new_believer');
    chk('move source=rule', ($db->rows['member_journeys'][0]['source'] ?? null) === 'rule');
    $t = end($db->rows['member_journey_transitions']);
    chk('transition tagged project_code', ($t['project_code'] ?? null) === 'foundations');
    chk('rule engine asked with membership facet + current_stage in ctx', ($eng->lastCall['facet'] ?? null) === 'membership'
        && ($eng->lastCall['attributes']['current_stage'] ?? null) === 'seeker');

    // ---- require_review proposes (not applied) ----------------------------
    echo "effect 'require_review' proposes\n";
    [$db, $svc, $eng] = $world();
    $eng->next = new RuleOutcome('require_review', [$rule('r_rev', 'require_review', ['to_stage' => 'growing'])]);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.event.attended', 'project_code' => 'camp']);
    chk('none applied', $r->data['applied'] === []);
    chk('one proposed', count($r->data['proposed']) === 1 && $r->data['proposed'][0]['to_stage'] === 'growing');
    chk('member NOT moved', ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'seeker');
    chk('proposal row pending', count($db->rows['journey_stage_proposals']) === 1
        && $db->rows['journey_stage_proposals'][0]['status'] === 'pending');
    chk('proposal carries project_code', ($db->rows['journey_stage_proposals'][0]['project_code'] ?? null) === 'camp');

    // ---- flag also proposes -----------------------------------------------
    echo "effect 'flag' proposes\n";
    [$db, $svc, $eng] = $world();
    $eng->next = new RuleOutcome('flag', [$rule('r_flag', 'flag', ['to_stage' => 'growing'])]);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.follow_up.recorded']);
    chk('flag -> one proposed', count($r->data['proposed']) === 1);

    // ---- allow/deny ignored -----------------------------------------------
    echo "allow/deny are ignored for the journey facet\n";
    [$db, $svc, $eng] = $world();
    $eng->next = new RuleOutcome('allow', [$rule('r_a', 'allow', ['to_stage' => 'growing']), $rule('r_d', 'deny', ['to_stage' => 'growing'])]);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.x']);
    chk('matched counted but nothing applied/proposed', $r->data['matched'] === 2 && $r->data['applied'] === [] && $r->data['proposed'] === []);

    // ---- missing to_stage skipped -----------------------------------------
    echo "rule without to_stage is skipped\n";
    [$db, $svc, $eng] = $world();
    $eng->next = new RuleOutcome('adjust', [$rule('r_bad', 'adjust', ['reason' => 'no dest'])]);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.x']);
    chk('nothing applied (no to_stage)', $r->data['applied'] === [] && $r->data['proposed'] === []);

    // ---- direction guard: regress blocked unless opted in -----------------
    echo "direction guard\n";
    [$db, $svc, $eng] = $world('growing'); // ann starts at growing
    $eng->next = new RuleOutcome('adjust', [$rule('r_reg', 'adjust', ['to_stage' => 'seeker'])]);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.x']);
    chk('regress blocked by default', $r->data['applied'] === [] && ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'growing');

    [$db, $svc, $eng] = $world('growing');
    $eng->next = new RuleOutcome('adjust', [$rule('r_reg2', 'adjust', ['to_stage' => 'seeker', 'allow_regress' => true])]);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.x']);
    chk('regress applied with allow_regress', count($r->data['applied']) === 1
        && ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'seeker');

    // ---- same-stage skipped ------------------------------------------------
    echo "same-stage move skipped\n";
    [$db, $svc, $eng] = $world('growing');
    $eng->next = new RuleOutcome('adjust', [$rule('r_same', 'adjust', ['to_stage' => 'growing'])]);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.x']);
    chk('same-stage -> nothing applied', $r->data['applied'] === []);

    // ---- dedup: identical open proposal not duplicated --------------------
    echo "proposal dedup\n";
    [$db, $svc, $eng] = $world();
    $eng->next = new RuleOutcome('require_review', [$rule('r_rev', 'require_review', ['to_stage' => 'growing'])]);
    $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.a']);
    $r2 = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.b']);
    chk('second identical proposal deduped', $r2->data['proposed'] === []
        && count($db->rows['journey_stage_proposals']) === 1);

    // ---- approve / reject queue -------------------------------------------
    echo "approve + reject queue\n";
    [$db, $svc, $eng] = $world();
    $eng->next = new RuleOutcome('require_review', [$rule('r_rev', 'require_review', ['to_stage' => 'new_believer'])]);
    $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.a', 'project_code' => 'harvest']);
    $pid = $db->rows['journey_stage_proposals'][0]['id'];
    $pending = $svc->pendingProposals($ORG)->data;
    chk('pendingProposals lists it', count($pending['proposals']) === 1);
    $ap = $svc->approveProposal($ORG, $pid, ['actor_id' => 'lead1']);
    chk('approve ok', $ap->ok && ($ap->data['status'] ?? null) === 'approved');
    chk('approve moved member', ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'new_believer');
    chk('approve carried project_code to transition', (end($db->rows['member_journey_transitions'])['project_code'] ?? null) === 'harvest');
    chk('proposal now approved', ($db->rows['journey_stage_proposals'][0]['status'] ?? null) === 'approved');
    $ap2 = $svc->approveProposal($ORG, $pid, ['actor_id' => 'lead1']);
    chk('re-approve -> NOT_PENDING 409', ! $ap2->ok && $ap2->status === 409 && $ap2->code === 'NOT_PENDING');
    $apx = $svc->approveProposal($ORG, 'ghost', []);
    chk('approve missing -> not found', ! $apx->ok && $apx->code === 'PROPOSAL_NOT_FOUND');

    [$db, $svc, $eng] = $world();
    $eng->next = new RuleOutcome('require_review', [$rule('r_rev', 'require_review', ['to_stage' => 'new_believer'])]);
    $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.a']);
    $pid = $db->rows['journey_stage_proposals'][0]['id'];
    $rj = $svc->rejectProposal($ORG, $pid, ['actor_id' => 'lead1', 'note' => 'not yet']);
    chk('reject ok + closed', $rj->ok && ($db->rows['journey_stage_proposals'][0]['status'] ?? null) === 'rejected');
    chk('reject did NOT move member', ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'seeker');

    // ---- guard rails -------------------------------------------------------
    echo "guard rails\n";
    [$db, $svc, $eng] = $world();
    $bad = $svc->ingest($ORG, ['user_id' => '', 'action' => '']);
    chk('blank signal -> BAD_SIGNAL 422', ! $bad->ok && $bad->status === 422 && $bad->code === 'BAD_SIGNAL');

    $noEngine = new JourneySignalService(new BaseConnection(), $clock, new JourneyService(new BaseConnection(), $clock, null, []), null);
    $ne = $noEngine->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.a']);
    chk('no rule engine -> NO_RULE_ENGINE 500', ! $ne->ok && $ne->status === 500 && $ne->code === 'NO_RULE_ENGINE');

    [$db, $svc, $eng] = $world();
    $eng->next = RuleOutcome::none();
    $zero = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.a']);
    chk('zero matches -> matched:0', $zero->ok && $zero->data['matched'] === 0 && $zero->data['applied'] === []);

    // ---- J6: supersede-on-move (ProposalSupersedeListener) ----------------
    echo "J6 supersede-on-move\n";
    // A world whose JourneyService carries the supersede listener, so a committed
    // transition closes pending proposals the move made stale. The listener uses a
    // second, listener-less JourneyService for its read-only compareStages.
    $worldSup = static function (string $startStage = 'seeker') use ($clock): array {
        $db = new BaseConnection();
        $mk = static fn (string $c, string $p, int $o): array => [
            'id' => "st_$c", 'organization_id' => 'org1', 'group_id' => null, 'code' => $c,
            'name' => ucfirst($c), 'phase' => $p, 'sort_order' => $o, 'is_entry' => $c === 'seeker' ? 1 : 0,
            'is_terminal' => $c === 'sender' ? 1 : 0, 'status' => 'active',
        ];
        $db->rows['journey_stages'] = [$mk('seeker', 'win', 10), $mk('new_believer', 'build', 20), $mk('growing', 'build', 30), $mk('sender', 'send', 40)];
        $db->rows['member_journeys'] = [[
            'id' => 'j_ann', 'organization_id' => 'org1', 'user_id' => 'ann', 'group_id' => null,
            'stage_code' => $startStage, 'stage_phase' => 'win', 'previous_stage' => null,
            'status' => 'active', 'source' => 'manual', 'source_ref' => null,
        ]];
        $db->rows['member_journey_transitions'] = [];
        $db->rows['journey_stage_proposals']    = [];
        $engine    = new RuleEngine();
        $readModel = new JourneyService($db, $clock, null, []);
        $journey   = new JourneyService($db, $clock, null, [new ProposalSupersedeListener($db, $readModel, $clock)]);
        $svc       = new JourneySignalService($db, $clock, $journey, $engine);

        return [$db, $svc, $engine, $journey];
    };

    // Two pending proposals: one BEHIND where the member is about to land
    // (new_believer) and one AHEAD (sender). Advancing to 'growing' supersedes the
    // behind one (target now regress) but leaves the ahead one pending.
    [$db, $svc, $eng, $journey] = $worldSup('seeker');
    $db->rows['journey_stage_proposals'] = [
        ['id' => 'pp_behind', 'organization_id' => 'org1', 'user_id' => 'ann', 'group_id' => null, 'from_stage' => 'seeker', 'to_stage' => 'new_believer', 'direction' => 'advance', 'status' => 'pending', 'created_at' => '2026-09-01 00:00:00.000000'],
        ['id' => 'pp_ahead',  'organization_id' => 'org1', 'user_id' => 'ann', 'group_id' => null, 'from_stage' => 'seeker', 'to_stage' => 'sender',       'direction' => 'advance', 'status' => 'pending', 'created_at' => '2026-09-01 00:00:00.000000'],
        // Another member's proposal must be left alone.
        ['id' => 'pp_other',  'organization_id' => 'org1', 'user_id' => 'bob', 'group_id' => null, 'from_stage' => 'seeker', 'to_stage' => 'new_believer', 'direction' => 'advance', 'status' => 'pending', 'created_at' => '2026-09-01 00:00:00.000000'],
    ];
    $mv = $journey->transition($ORG, 'ann', 'growing', ['source' => 'manual']);
    chk('transition to growing ok', $mv->ok);
    $find = static function (array $db, string $id): ?array {
        foreach ($db['journey_stage_proposals'] as $p) {
            if ($p['id'] === $id) { return $p; }
        }
        return null;
    };
    chk('behind proposal (new_believer) superseded', ($find($db->rows, 'pp_behind')['status'] ?? null) === 'superseded');
    chk('ahead proposal (sender) still pending', ($find($db->rows, 'pp_ahead')['status'] ?? null) === 'pending');
    chk('other member proposal untouched', ($find($db->rows, 'pp_other')['status'] ?? null) === 'pending');

    // A pending proposal whose target == the member's NEW stage is redundant -> superseded.
    [$db, $svc, $eng, $journey] = $worldSup('seeker');
    $db->rows['journey_stage_proposals'] = [
        ['id' => 'pp_same', 'organization_id' => 'org1', 'user_id' => 'ann', 'group_id' => null, 'from_stage' => 'seeker', 'to_stage' => 'growing', 'direction' => 'advance', 'status' => 'pending', 'created_at' => '2026-09-01 00:00:00.000000'],
    ];
    $journey->transition($ORG, 'ann', 'growing', ['source' => 'manual']);
    chk('redundant proposal (target == new stage) superseded', ($find($db->rows, 'pp_same')['status'] ?? null) === 'superseded');

    // A deliberate regress proposal (direction=regress) is NOT auto-superseded.
    [$db, $svc, $eng, $journey] = $worldSup('seeker');
    $db->rows['journey_stage_proposals'] = [
        ['id' => 'pp_regress', 'organization_id' => 'org1', 'user_id' => 'ann', 'group_id' => null, 'from_stage' => 'growing', 'to_stage' => 'new_believer', 'direction' => 'regress', 'status' => 'pending', 'created_at' => '2026-09-01 00:00:00.000000'],
    ];
    $journey->transition($ORG, 'ann', 'growing', ['source' => 'manual']);
    chk('deliberate regress proposal left pending for a human', ($find($db->rows, 'pp_regress')['status'] ?? null) === 'pending');

    // ---- J6: approveProposal re-derives direction vs CURRENT stage --------
    echo "J6 approve guard (re-derive vs current stage)\n";
    // Member has since advanced PAST the proposal target -> approve refuses (409 stale)
    // and closes the proposal as superseded rather than regressing them.
    [$db, $svc, $eng] = $world('growing');
    $db->rows['journey_stage_proposals'] = [
        ['id' => 'ap_behind', 'organization_id' => 'org1', 'user_id' => 'ann', 'group_id' => null, 'from_stage' => 'seeker', 'to_stage' => 'new_believer', 'direction' => 'advance', 'status' => 'pending', 'reason' => 'r', 'created_at' => '2026-09-01 00:00:00.000000'],
    ];
    $ap = $svc->approveProposal($ORG, 'ap_behind', ['actor_id' => 'lead1']);
    chk('approve of now-behind proposal -> 409 PROPOSAL_STALE', ! $ap->ok && $ap->status === 409 && $ap->code === 'PROPOSAL_STALE', json_encode([$ap->code, $ap->status]));
    chk('stale approve did NOT regress member', ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'growing');
    chk('stale approve superseded the proposal', ($db->rows['journey_stage_proposals'][0]['status'] ?? null) === 'superseded');

    // Member already AT the proposal target -> approve is a redundant no-op (stale).
    [$db, $svc, $eng] = $world('growing');
    $db->rows['journey_stage_proposals'] = [
        ['id' => 'ap_same', 'organization_id' => 'org1', 'user_id' => 'ann', 'group_id' => null, 'from_stage' => 'seeker', 'to_stage' => 'growing', 'direction' => 'advance', 'status' => 'pending', 'reason' => 'r', 'created_at' => '2026-09-01 00:00:00.000000'],
    ];
    $ap = $svc->approveProposal($ORG, 'ap_same', ['actor_id' => 'lead1']);
    chk('approve of already-at-target proposal -> 409 PROPOSAL_STALE', ! $ap->ok && $ap->status === 409 && $ap->code === 'PROPOSAL_STALE');
    chk('already-at-target proposal superseded', ($db->rows['journey_stage_proposals'][0]['status'] ?? null) === 'superseded');

    // A still-valid forward proposal approves normally (guard does not over-block).
    [$db, $svc, $eng] = $world('seeker');
    $db->rows['journey_stage_proposals'] = [
        ['id' => 'ap_ok', 'organization_id' => 'org1', 'user_id' => 'ann', 'group_id' => null, 'from_stage' => 'seeker', 'to_stage' => 'growing', 'direction' => 'advance', 'status' => 'pending', 'reason' => 'r', 'created_at' => '2026-09-01 00:00:00.000000'],
    ];
    $ap = $svc->approveProposal($ORG, 'ap_ok', ['actor_id' => 'lead1']);
    chk('valid forward proposal still approves', $ap->ok && ($ap->data['status'] ?? null) === 'approved');
    chk('valid approve moved member to growing', ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'growing');

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
