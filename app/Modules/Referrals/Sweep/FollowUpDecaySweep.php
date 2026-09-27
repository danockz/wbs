<?php

declare(strict_types=1);

namespace WBS\Referrals\Sweep;

use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, R4): keep the outreach downline board honest. The
 * `prospects` triage columns (`next_follow_up_at`, `temperature`,
 * `last_contacted_at`) had purpose-built indexes and dashboard tiles but nothing
 * acted on them — a due follow-up sat unremarked and a contact that went cold
 * stayed whatever temperature a human last set. Wraps the bounded, idempotent
 * ContactBookService::processFollowUps(): reminds owners of due follow-ups (once
 * per due date, gated + deduped) and decays temperature (hot→warm→cold) as a
 * contact goes untouched. `swept` = reminders sent + contacts decayed this pass.
 */
final class FollowUpDecaySweep implements SweepContract
{
    public function key(): string
    {
        return 'referrals.follow-up-decay';
    }

    public function description(): string
    {
        return 'Remind owners of due outreach follow-ups and decay stale contact temperature.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 500;

        $res      = ReferralServices::contactBook()->processFollowUps($organizationId, $limit);
        $reminded = (int) ($res['reminded'] ?? 0);
        $decayed  = (int) ($res['decayed'] ?? 0);

        return SweepResult::ok($reminded + $decayed, [
            'reminded' => $reminded,
            'decayed'  => $decayed,
        ]);
    }
}
