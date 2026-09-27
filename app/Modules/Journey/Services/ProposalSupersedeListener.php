<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;

/**
 * J6 — supersede-on-move.
 *
 * When a member advances (by any path — a manual move, an auto-applied rule, or
 * the approval of ANOTHER proposal) past a stage a pending proposal still
 * targets, that proposal used to stay `pending` and could later be `approved`,
 * driving a REGRESS or a stale move the reviewer no longer intends. This listener
 * closes that gap: after every committed transition it re-derives, for each still
 * pending proposal in the SAME `(user, journey context)`, the direction from the
 * member's NEW current stage to the proposal's target. A proposal whose target is
 * now the current stage (`same`) or now BEHIND the member (`regress`) is
 * redundant/stale, so it is marked `superseded` — unless the proposal was
 * ITSELF a deliberate regress (a leader's allow_regress rule), which is left for
 * a human to decide.
 *
 * Runs as a {@see JourneyTransitionListener}, so it MUST NOT throw: any failure
 * is swallowed and can never roll back the member's (already durable) move. It is
 * idempotent — an already superseded/decided proposal is never re-touched.
 */
final class ProposalSupersedeListener implements JourneyTransitionListener
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly JourneyService $journey,
        private readonly Clock $clock,
    ) {
    }

    public function onTransition(array $event): void
    {
        try {
            $orgId  = (string) ($event['organization_id'] ?? '');
            $userId = (string) ($event['user_id'] ?? '');
            $toCode = (string) ($event['to_stage'] ?? '');
            if ($orgId === '' || $userId === '' || $toCode === '') {
                return;
            }
            $groupId = isset($event['group_id']) && $event['group_id'] !== '' ? (string) $event['group_id'] : null;

            // Still-open proposals for this exact journey context.
            $q = $this->db->table('journey_stage_proposals')
                ->where('organization_id', $orgId)
                ->where('user_id', $userId)
                ->where('status', 'pending');
            $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
            $pending = $q->get()->getResultArray();
            if ($pending === []) {
                return;
            }

            $now = $this->clock->nowUtcString();

            foreach ($pending as $p) {
                $target = (string) ($p['to_stage'] ?? '');
                if ($target === '') {
                    continue;
                }
                // Direction from where the member IS NOW to this proposal's target.
                $dir = $this->journey->compareStages($orgId, $groupId, $toCode, $target);

                // 'same'    -> member is already at the target: proposal redundant.
                // 'regress' -> target is now behind the member: approving it would
                //              demote them. Supersede unless it was a deliberate
                //              regress proposal (a leader's allow_regress rule).
                $storedDir = (string) ($p['direction'] ?? '');
                $stale = $dir === 'same' || ($dir === 'regress' && $storedDir !== 'regress');
                if (! $stale) {
                    continue;
                }

                $this->db->table('journey_stage_proposals')
                    ->where('id', $p['id'])
                    ->where('status', 'pending')
                    ->update([
                        'status'        => 'superseded',
                        'decided_at'    => $now,
                        'decision_note' => 'Superseded: member advanced to ' . $toCode,
                    ]);
            }
        } catch (Throwable) {
            // Never let a supersede failure affect the committed move.
        }
    }
}
