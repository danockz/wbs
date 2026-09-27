<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

/**
 * Narrow seam the N2 campaign fan-out uses to resolve a campaign's audience into
 * concrete recipient user ids, WITHOUT the Notifications module owning the
 * audience-filter engine (membership/group/journey predicates live in other
 * modules). The production resolver interprets `audience_filter` against the
 * platform's directory; tests supply a fixed list. Returns user ids only — the
 * gate (opt-out / quiet-hours / verified-channel) is applied per recipient by
 * the sender, so this need not pre-filter for consent.
 */
interface CampaignAudiencePort
{
    /**
     * @param array<string,mixed> $campaign the notification_campaigns row
     * @return list<string> recipient user ids (may be empty)
     */
    public function resolve(array $campaign): array;
}
