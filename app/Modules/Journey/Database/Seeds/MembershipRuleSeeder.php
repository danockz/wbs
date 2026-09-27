<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\Uuid;

/**
 * Seeds the DEFAULT membership-facet RULES that turn real-world signals into
 * journey progress (assessment Option C).
 *
 * ── Why this seeder exists ────────────────────────────────────────────────────
 * The Option-C pipeline is fully built and wired: the four domain emitters
 * (events check-in, follow-ups, course completion, verified contribution) call
 * JourneySignalService::ingest(), which asks the shared RuBAC RuleEngine which
 * `membership`-facet rules match. But NO seeder ever created any membership
 * rules — so on a FRESH org every signal matched nothing and the entire
 * auto-progression path was a silent no-op until an admin hand-authored rules.
 * This seeder ships a sensible, editable starting set so the signals have
 * something to match out of the box.
 *
 * ── What it seeds (org-wide, group_id = NULL, scope_mode = self => covers all) ─
 * Rules map a signal action + the member's CURRENT stage to a destination stage.
 * Stage codes match JourneyStageSeeder's default ladder. Effects use the journey
 * facet vocabulary the engine already validates:
 *
 *   - 'adjust'         -> auto-apply the move (no human step);
 *   - 'require_review' -> queue a proposal for a leader to approve.
 *
 * The condition tree gates on `current_stage` (an ABAC leaf the signal always
 * puts in the context), so a rule only fires for members at the expected point.
 * The destination + apply-mode live in effect_params.to_stage, per the engine's
 * contract.
 *
 * Default set (conservative — auto-apply only the two lowest, review the rest):
 *   1. first attendance:   prospect      --attend-->        first_timer      (adjust)
 *   2. new-believer step:  first_timer   --attend-->        new_believer     (require_review)
 *   3. foundation entry:   new_believer  --course.completed->in_foundation   (adjust)
 *   4. established:        in_foundation --course.completed->established      (require_review)
 *   5. follow-up nudge:    prospect      --follow_up-------->first_timer      (require_review)
 *   6. giving milestone:   established   --contribution----->worker           (require_review)
 *
 * ── Safety / discipline ───────────────────────────────────────────────────────
 *  - Idempotent: each rule upserts on (organization_id, facet='membership',
 *    code); operator edits are left intact (existing code => skip).
 *  - Every rule is a normal `rules` row, editable/deletable through the standard
 *    AccessControl rule UI + revisions — nothing bespoke, no parallel table.
 *  - Written org-wide with scope_mode='self' (NULL scope group) so the default
 *    rules cover every group; a leader can later add narrower group-scoped rules.
 *  - Runs for the 'wbs' org if present, else every organization (matches the
 *    other Journey seeders).
 *
 *   composer seed:membership-rules
 */
class MembershipRuleSeeder extends Seeder
{
    /**
     * code => [name, action_pattern, effect, from_stage, to_stage, priority, description]
     *
     * @var array<int,array{0:string,1:string,2:string,3:string,4:string,5:string,6:int,7:string}>
     */
    private const RULES = [
        [
            'mbr.attend.prospect_to_first_timer',
            'First attendance welcomes a prospect',
            'journey.signal.event.attended',
            'adjust',
            'prospect',
            'first_timer',
            50,
            'When a captured prospect attends for the first time, advance them to First-Timer automatically.',
        ],
        [
            'mbr.attend.first_timer_to_new_believer',
            'Repeat attendance suggests New Believer',
            'journey.signal.event.attended',
            'require_review',
            'first_timer',
            'new_believer',
            60,
            'A returning first-timer is proposed for New Believer for a leader to confirm after a conversion.',
        ],
        [
            'mbr.course.new_believer_to_foundation',
            'Foundation course enrolls a new believer',
            'journey.signal.course.completed',
            'adjust',
            'new_believer',
            'in_foundation',
            50,
            'Completing a course moves a New Believer into Foundation/Growth automatically.',
        ],
        [
            'mbr.course.foundation_to_established',
            'Completing foundation establishes a member',
            'journey.signal.course.completed',
            'require_review',
            'in_foundation',
            'established',
            60,
            'A member who completes a course while in Foundation is proposed as an Established Member.',
        ],
        [
            'mbr.follow_up.prospect_to_first_timer',
            'A recorded follow-up warms a prospect',
            'journey.signal.follow_up.recorded',
            'require_review',
            'prospect',
            'first_timer',
            70,
            'A logged follow-up with a prospect proposes advancing them to First-Timer.',
        ],
        [
            'mbr.group.prospect_to_first_timer',
            'Joining a group welcomes a prospect',
            'journey.signal.group.joined',
            'adjust',
            'prospect',
            'first_timer',
            55,
            'When a captured prospect joins a group (the membership half of integration), advance them to First-Timer automatically.',
        ],
        [
            'mbr.group.new_believer_to_foundation',
            'Group belonging begins a new believer\'s foundation',
            'journey.signal.group.joined',
            'require_review',
            'new_believer',
            'in_foundation',
            65,
            'A New Believer joining a group is proposed for Foundation/Growth — belonging + foundations courses together are "integration".',
        ],
        [
            'mbr.contribution.established_to_worker',
            'A first gift marks a worker',
            'journey.signal.contribution.verified',
            'require_review',
            'established',
            'worker',
            70,
            'A verified contribution from an established member proposes recognising them as a Worker.',
        ],
        // M4 — ENTRY rules. These open a journey the moment an account is created
        // or a referral converts (the "one open seam"), so a brand-new member has
        // a journey row at the first ladder stage instead of only materialising on
        // their first tracked action. The sentinel from-stage `(new)` compiles to
        // a `has_journey = false` gate (not a current_stage match), so the rule
        // fires ONCE — when there is no journey yet — and is a harmless no-op on
        // replay once the journey exists.
        [
            'mbr.register.open_prospect',
            'A new account opens a journey',
            'journey.signal.member.registered',
            'adjust',
            '(new)',
            'prospect',
            40,
            'When an account is created, open its membership journey at Prospect so first-stage pipeline counts include brand-new members.',
        ],
        [
            'mbr.convert.open_first_timer',
            'A converted referral opens a journey',
            'journey.signal.member.converted',
            'adjust',
            '(new)',
            'first_timer',
            45,
            'When a referral converts into an account, open its journey at First-Timer — a converted contact has already engaged.',
        ],
    ];

    /** Sentinel from-stage that compiles to a "no journey yet" gate (M4 entry). */
    private const ENTRY_FROM = '(new)';

    public function run(): void
    {
        $now = date('Y-m-d H:i:s.u');

        $orgIds = [];
        $wbs = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($wbs !== null) {
            $orgIds[] = (string) $wbs['id'];
        } else {
            foreach ($this->db->table('organizations')->select('id')->get()->getResultArray() as $o) {
                $orgIds[] = (string) $o['id'];
            }
        }

        // Column-defensive: `scope_mode` (000054) and `include_crosscut` (000056)
        // are only present once those RuBAC migrations have run. A seeder must not
        // hard-fail on an optional column, so we include each only when it exists
        // (the DB default — self / 0 — applies otherwise). This keeps the seeder
        // safe on a schema that is a migration or two behind.
        $hasScopeMode = $this->db->fieldExists('scope_mode', 'rules');
        $hasCrosscut  = $this->db->fieldExists('include_crosscut', 'rules');

        $created = 0;
        foreach ($orgIds as $orgId) {
            foreach (self::RULES as [$code, $name, $action, $effect, $fromStage, $toStage, $priority, $description]) {
                $exists = $this->db->table('rules')
                    ->where('organization_id', $orgId)
                    ->where('facet', 'membership')
                    ->where('code', $code)
                    ->countAllResults() > 0;
                if ($exists) {
                    continue; // idempotent — leave operator edits intact
                }

                // Gate on the member's current stage so the rule only fires for
                // people at the expected point on the ladder. `current_stage` is
                // an attribute JourneySignalService always puts in the context.
                // The ENTRY sentinel instead gates on "no journey yet"
                // (`has_journey = false`), so an entry rule fires exactly once —
                // at account creation / conversion — and no-ops on replay.
                if ($fromStage === self::ENTRY_FROM) {
                    $condition = [
                        'all' => [
                            ['attr' => 'has_journey', 'op' => 'eq', 'value' => false],
                        ],
                    ];
                } else {
                    $condition = [
                        'all' => [
                            ['attr' => 'current_stage', 'op' => 'eq', 'value' => $fromStage],
                        ],
                    ];
                }

                // The journey engine reads the destination + (implicit) apply
                // mode from effect_params; `reason` is surfaced on the proposal.
                $effectParams = [
                    'to_stage' => $toStage,
                    'reason'   => $name,
                ];

                $row = [
                    'id'               => Uuid::v7(),
                    'organization_id'  => $orgId,
                    'facet'            => 'membership',
                    'code'             => $code,
                    'name'             => $name,
                    'description'      => $description,
                    'effect'           => $effect,
                    'action_pattern'   => $action,
                    'condition'        => json_encode($condition, JSON_UNESCAPED_UNICODE),
                    'effect_params'    => json_encode($effectParams, JSON_UNESCAPED_UNICODE),
                    'scope_group_id'   => null,   // org-wide
                    'priority'         => $priority,
                    'enabled'          => 1,
                    'created_by'       => null,
                    'created_at'       => $now,
                    'updated_at'       => null,
                ];
                if ($hasScopeMode) {
                    $row['scope_mode'] = 'self'; // NULL scope + self => covers every group
                }
                if ($hasCrosscut) {
                    $row['include_crosscut'] = 0;
                }

                $this->db->table('rules')->insert($row);
                $created++;
            }
        }

        $this->say(STDOUT, "MembershipRuleSeeder: ensured default membership rules (created {$created}).\n");
    }

    private function say(mixed $stream, string $msg): void
    {
        if (is_resource($stream)) {
            fwrite($stream, $msg);
        }
    }
}
