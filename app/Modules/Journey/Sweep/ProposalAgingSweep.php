<?php

declare(strict_types=1);

namespace WBS\Journey\Sweep;

use WBS\Journey\Config\Services as JourneyServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, J6): age the PENDING journey-proposal queue.
 *
 * A membership rule with effect require_review/flag queues a pending
 * `journey_stage_proposals` row for a leader, but nothing ever aged it — a
 * proposal a leader never noticed sat pending forever and `superseded` was a
 * status value nothing set. Wraps the bounded, idempotent
 * JourneyProposalAgingService::sweepPendingProposals(), which runs a uniform
 * remind → escalate → (optional) timeout lifecycle over pending proposals, every
 * step watermark-guarded so a re-run in the same window is a no-op.
 *
 * Thresholds are per-org platform settings (`proposal_remind_hours`,
 * `proposal_escalate_hours`, `proposal_timeout_days`, `proposal_timeout_action`);
 * auto-terminate is OFF by default (timeout_days=0). The complementary
 * supersede-on-move (closing a proposal the instant a member advances past its
 * target) runs inline on every transition, not here.
 */
final class ProposalAgingSweep implements SweepContract
{
    public function key(): string
    {
        return 'journey.proposal-aging';
    }

    public function description(): string
    {
        return 'Remind, escalate and (optionally) time-out pending journey stage proposals so leader queues never go stale.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 200;

        $r = JourneyServices::proposalAging()->sweepPendingProposals($organizationId, $limit);

        // "Swept" = proposals that actually advanced this pass.
        $advanced = (int) $r['reminded'] + (int) $r['escalated'] + (int) $r['timed_out'];

        return SweepResult::ok($advanced, [
            'scanned'   => (int) $r['scanned'],
            'reminded'  => (int) $r['reminded'],
            'escalated' => (int) $r['escalated'],
            'timed_out' => (int) $r['timed_out'],
        ]);
    }
}
