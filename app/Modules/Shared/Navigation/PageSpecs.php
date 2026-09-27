<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * PAGE SPEC CATALOG — declarative, localized page definitions for endpoints that
 * previously fell through to the generic data_page.php fallback.
 *
 * Each spec describes ONE bespoke page: its id (drives lang keys under
 * Pages.views.<id>.*), accent color, the columns/detail fields to render from
 * the service Result, and any write forms/row-actions. The shared presenter
 * (WBS\Shared\Views\presenter\page) turns a spec + the Result data into a full,
 * house-style, locale-aware HTML page. Controllers call
 * BaseController::respondPage($result, '<id>', $extract) — no per-endpoint view
 * file, but every page is genuinely bespoke (own title, shape, forms) and fully
 * translated (parity-checked against Pages.php in every locale).
 *
 * A spec is a plain array; `extract` (a callable given the Result payload) maps
 * the service data onto the presenter keys rows/record/count/facts/back and any
 * dynamic form action ids. Static presentation (columns, forms, accent) lives
 * in the spec; only data-dependent bits are computed at request time.
 */
final class PageSpecs
{
    /**
     * Return the static spec for a page id, or null if unknown.
     *
     * @return array<string,mixed>|null
     */
    public static function get(string $id): ?array
    {
        $all = self::all();

        return $all[$id] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [
            // ---- Identity ---------------------------------------------------
            'identity_mfa_factors' => [
                'accent'  => '#38bdf8',
                'enhance' => true,
                'columns' => [
                    ['key' => 'type', 'type' => 'chip', 'labelKey' => 'Pages.common.colType'],
                    ['key' => 'label', 'type' => 'strong', 'labelKey' => 'Pages.common.colName'],
                    ['key' => 'verified', 'type' => 'bool', 'labelKey' => 'Pages.common.colVerified'],
                    ['key' => 'created_at', 'type' => 'date', 'labelKey' => 'Pages.common.colCreated'],
                ],
            ],
            'identity_tokens' => [
                'accent'  => '#38bdf8',
                'enhance' => true,
                'columns' => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName'],
                    ['key' => 'last_used_at', 'type' => 'date', 'labelKey' => 'Pages.common.colLastUsed'],
                    ['key' => 'expires_at', 'type' => 'date', 'labelKey' => 'Pages.common.colExpires'],
                    ['key' => 'created_at', 'type' => 'date', 'labelKey' => 'Pages.common.colCreated'],
                ],
            ],

            // ---- Groups -----------------------------------------------------
            'groups_membership' => [
                'accent'  => '#22c55e',
                'detail'  => [
                    ['key' => 'id', 'type' => 'code', 'labelKey' => 'Pages.common.colId'],
                    ['key' => 'user_id', 'type' => 'code', 'labelKey' => 'Pages.common.colUser'],
                    ['key' => 'group_id', 'type' => 'code', 'labelKey' => 'Pages.common.colGroup'],
                    ['key' => 'role', 'type' => 'chip', 'labelKey' => 'Pages.common.colRole'],
                    ['key' => 'membership_type', 'type' => 'chip', 'labelKey' => 'Pages.common.colType'],
                    ['key' => 'status', 'type' => 'chip', 'labelKey' => 'Pages.common.colStatus', 'colors' => ['active' => '#22c55e', 'pending' => '#f59e0b', 'ended' => '#94a3b8']],
                    ['key' => 'joined_at', 'type' => 'date', 'labelKey' => 'Pages.common.colJoined'],
                ],
            ],
            'groups_user_memberships' => [
                'accent'  => '#22c55e',
                'enhance' => true,
                'columns' => [
                    ['key' => 'group_id', 'type' => 'strong', 'labelKey' => 'Pages.common.colGroup', 'rowHref' => 'memberships/{id}', 'idKey' => 'id'],
                    ['key' => 'role', 'type' => 'chip', 'labelKey' => 'Pages.common.colRole'],
                    ['key' => 'membership_type', 'type' => 'chip', 'labelKey' => 'Pages.common.colType'],
                    ['key' => 'status', 'type' => 'chip', 'labelKey' => 'Pages.common.colStatus', 'colors' => ['active' => '#22c55e', 'pending' => '#f59e0b', 'ended' => '#94a3b8']],
                    ['key' => 'joined_at', 'type' => 'date', 'labelKey' => 'Pages.common.colJoined'],
                ],
            ],

            // ---- Journey ----------------------------------------------------
            'journey_stage_members' => [
                'accent'  => '#a78bfa',
                'enhance' => true,
                'columns' => [
                    ['key' => 'display_name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName'],
                    ['key' => 'user_id', 'type' => 'code', 'labelKey' => 'Pages.common.colUser'],
                    ['key' => 'temperature', 'type' => 'chip', 'labelKey' => 'Pages.common.colTriage', 'colors' => ['hot' => '#f87171', 'warm' => '#fbbf24', 'cold' => '#60a5fa']],
                    ['key' => 'group_id', 'type' => 'code', 'labelKey' => 'Pages.common.colGroup'],
                    ['key' => 'entered_at', 'type' => 'date', 'labelKey' => 'Pages.common.colEntered'],
                ],
            ],
            'journey_disciplers' => [
                'accent'  => '#a78bfa',
                'enhance' => true,
                'columns' => [
                    ['key' => 'rank', 'type' => 'num', 'labelKey' => 'Pages.common.colRank'],
                    ['key' => 'discipler_name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName'],
                    ['key' => 'active_count', 'type' => 'num', 'labelKey' => 'Pages.common.colActive'],
                    ['key' => 'completed_count', 'type' => 'num', 'labelKey' => 'Pages.common.colCompleted'],
                ],
            ],
            'journey_user_all' => [
                'accent'  => '#a78bfa',
                'enhance' => true,
                'columns' => [
                    ['key' => 'group_id', 'type' => 'code', 'labelKey' => 'Pages.common.colGroup'],
                    ['key' => 'stage_code', 'type' => 'chip', 'labelKey' => 'Pages.common.colStage'],
                    ['key' => 'phase', 'type' => 'chip', 'labelKey' => 'Pages.common.colPhase', 'colors' => ['win' => '#38bdf8', 'build' => '#a78bfa', 'send' => '#22c55e']],
                    ['key' => 'updated_at', 'type' => 'date', 'labelKey' => 'Pages.common.colUpdated'],
                ],
            ],

            // ---- Geo --------------------------------------------------------
            'geo_reverse' => [
                'accent'  => '#14b8a6',
                'detail'  => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colPlace'],
                    ['key' => 'admin1', 'type' => 'text', 'labelKey' => 'Pages.common.colRegion'],
                    ['key' => 'country_code', 'type' => 'chip', 'labelKey' => 'Pages.common.colCountry'],
                    ['key' => 'latitude', 'type' => 'text', 'labelKey' => 'Pages.common.colLatitude'],
                    ['key' => 'longitude', 'type' => 'text', 'labelKey' => 'Pages.common.colLongitude'],
                ],
            ],
            'geo_places' => [
                'accent'  => '#14b8a6',
                'enhance' => true,
                'columns' => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colPlace'],
                    ['key' => 'admin1', 'type' => 'text', 'labelKey' => 'Pages.common.colRegion'],
                    ['key' => 'country_code', 'type' => 'chip', 'labelKey' => 'Pages.common.colCountry'],
                    ['key' => 'distance_km', 'type' => 'num', 'labelKey' => 'Pages.common.colDistanceKm'],
                ],
            ],
            'geo_stats' => [
                'accent'  => '#14b8a6',
                'columns' => [
                    ['key' => 'label', 'type' => 'strong', 'labelKey' => 'Pages.common.colGroup'],
                    ['key' => 'count', 'type' => 'num', 'labelKey' => 'Pages.common.colCount'],
                ],
            ],
            'venue_nearby' => [
                'accent'  => '#14b8a6',
                'enhance' => true,
                'columns' => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName', 'rowHref' => 'venues/{id}', 'idKey' => 'id'],
                    ['key' => 'address', 'type' => 'text', 'labelKey' => 'Pages.common.colAddress'],
                    ['key' => 'distance_km', 'type' => 'num', 'labelKey' => 'Pages.common.colDistanceKm'],
                    ['key' => 'capacity', 'type' => 'num', 'labelKey' => 'Pages.common.colCapacity'],
                ],
            ],
            'venue_stats' => [
                'accent'  => '#14b8a6',
                'columns' => [
                    ['key' => 'label', 'type' => 'strong', 'labelKey' => 'Pages.common.colMetric'],
                    ['key' => 'value', 'type' => 'num', 'labelKey' => 'Pages.common.colValue'],
                ],
            ],
            'venue_show' => [
                'accent'  => '#14b8a6',
                'detail'  => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName'],
                    ['key' => 'address', 'type' => 'text', 'labelKey' => 'Pages.common.colAddress'],
                    ['key' => 'capacity', 'type' => 'num', 'labelKey' => 'Pages.common.colCapacity'],
                    ['key' => 'latitude', 'type' => 'text', 'labelKey' => 'Pages.common.colLatitude'],
                    ['key' => 'longitude', 'type' => 'text', 'labelKey' => 'Pages.common.colLongitude'],
                ],
            ],
            'venue_group' => [
                'accent'  => '#14b8a6',
                'enhance' => true,
                'columns' => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName', 'rowHref' => 'venues/{id}', 'idKey' => 'id'],
                    ['key' => 'address', 'type' => 'text', 'labelKey' => 'Pages.common.colAddress'],
                    ['key' => 'capacity', 'type' => 'num', 'labelKey' => 'Pages.common.colCapacity'],
                ],
            ],

            // ---- Referrals --------------------------------------------------
            'referral_link_analytics' => [
                'accent'  => '#f97316',
                'columns' => [
                    ['key' => 'label', 'type' => 'strong', 'labelKey' => 'Pages.common.colMetric'],
                    ['key' => 'value', 'type' => 'num', 'labelKey' => 'Pages.common.colValue'],
                ],
            ],
            'referral_referrer_analytics' => [
                'accent'  => '#f97316',
                'columns' => [
                    ['key' => 'label', 'type' => 'strong', 'labelKey' => 'Pages.common.colMetric'],
                    ['key' => 'value', 'type' => 'num', 'labelKey' => 'Pages.common.colValue'],
                ],
            ],
            // ---- Events -----------------------------------------------------
            'event_ticket_types' => [
                'accent'  => '#eab308',
                'enhance' => true,
                'columns' => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName'],
                    ['key' => 'price', 'type' => 'num', 'labelKey' => 'Pages.common.colPrice'],
                    ['key' => 'currency', 'type' => 'chip', 'labelKey' => 'Pages.common.colCurrency'],
                    ['key' => 'quantity', 'type' => 'num', 'labelKey' => 'Pages.common.colQuantity'],
                    ['key' => 'sold', 'type' => 'num', 'labelKey' => 'Pages.common.colSold'],
                ],
            ],
            'event_media' => [
                'accent'  => '#eab308',
                'enhance' => true,
                'columns' => [
                    ['key' => 'title', 'type' => 'strong', 'labelKey' => 'Pages.common.colTitle'],
                    ['key' => 'kind', 'type' => 'chip', 'labelKey' => 'Pages.common.colType'],
                    ['key' => 'url', 'type' => 'link', 'href' => '{id}', 'textKey' => 'Pages.common.open', 'labelKey' => 'Pages.common.colLink'],
                    ['key' => 'created_at', 'type' => 'date', 'labelKey' => 'Pages.common.colCreated'],
                ],
            ],
            'certificate_verify' => [
                'accent'  => '#eab308',
                'detail'  => [
                    ['key' => 'valid', 'type' => 'bool', 'labelKey' => 'Pages.common.colValid'],
                    ['key' => 'recipient_name', 'type' => 'strong', 'labelKey' => 'Pages.common.colRecipient'],
                    ['key' => 'title', 'type' => 'text', 'labelKey' => 'Pages.common.colTitle'],
                    ['key' => 'issued_at', 'type' => 'date', 'labelKey' => 'Pages.common.colIssued'],
                ],
            ],

            // ---- Integrations ----------------------------------------------
            'integration_adapter_allowlist' => [
                'accent'  => '#6366f1',
                'columns' => [
                    ['key' => 'value', 'type' => 'code', 'labelKey' => 'Pages.common.colClass'],
                ],
            ],
            'integration_custom_adapter' => [
                'accent'  => '#6366f1',
                'detail'  => [
                    ['key' => 'id', 'type' => 'code', 'labelKey' => 'Pages.common.colId'],
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName'],
                    ['key' => 'adapter_class', 'type' => 'code', 'labelKey' => 'Pages.common.colClass'],
                    ['key' => 'status', 'type' => 'chip', 'labelKey' => 'Pages.common.colStatus', 'colors' => ['active' => '#22c55e', 'disabled' => '#94a3b8']],
                    ['key' => 'created_at', 'type' => 'date', 'labelKey' => 'Pages.common.colCreated'],
                ],
            ],
            'integration_fallback_matrix' => [
                'accent'  => '#6366f1',
                'enhance' => true,
                'columns' => [
                    ['key' => 'adapter', 'type' => 'strong', 'labelKey' => 'Pages.common.colAdapter'],
                    ['key' => 'primary', 'type' => 'chip', 'labelKey' => 'Pages.common.colPrimary'],
                    ['key' => 'fallback', 'type' => 'chip', 'labelKey' => 'Pages.common.colFallback'],
                ],
            ],
            'integration_adapter_fallback' => [
                'accent'  => '#6366f1',
                'enhance' => true,
                'columns' => [
                    ['key' => 'order', 'type' => 'num', 'labelKey' => 'Pages.common.colOrder'],
                    ['key' => 'adapter', 'type' => 'strong', 'labelKey' => 'Pages.common.colAdapter'],
                    ['key' => 'condition', 'type' => 'text', 'labelKey' => 'Pages.common.colCondition'],
                ],
            ],

            // ---- Streaming --------------------------------------------------
            'stream_access' => [
                'accent'  => '#ec4899',
                'detail'  => [
                    ['key' => 'allowed', 'type' => 'bool', 'labelKey' => 'Pages.common.colAllowed'],
                    ['key' => 'reason', 'type' => 'text', 'labelKey' => 'Pages.common.colReason'],
                ],
            ],
            'stream_acks' => [
                'accent'  => '#ec4899',
                'enhance' => true,
                'columns' => [
                    ['key' => 'donor_name', 'type' => 'strong', 'labelKey' => 'Pages.common.colDonor'],
                    ['key' => 'amount', 'type' => 'num', 'labelKey' => 'Pages.common.colAmount'],
                    ['key' => 'message', 'type' => 'text', 'labelKey' => 'Pages.common.colMessage'],
                    ['key' => 'created_at', 'type' => 'date', 'labelKey' => 'Pages.common.colWhen'],
                ],
            ],

            // ---- Gamification ----------------------------------------------
            'gam_followups_due' => [
                'accent'      => '#f59e0b',
                'enhance'     => true,
                'newHref'     => 'gamification/follow-ups/new',
                'newLabelKey' => 'Pages.views.gam_followups_due.recordNew',
                'columns' => [
                    ['key' => 'subject_name', 'type' => 'strong', 'labelKey' => 'Pages.common.colSubject'],
                    ['key' => 'stage_code', 'type' => 'chip', 'labelKey' => 'Pages.common.colStage'],
                    ['key' => 'type', 'type' => 'chip', 'labelKey' => 'Pages.common.colType'],
                    ['key' => 'due_at', 'type' => 'date', 'labelKey' => 'Pages.common.colDue'],
                    ['key' => 'phase', 'type' => 'chip', 'labelKey' => 'Pages.common.colPhase', 'colors' => ['win' => '#38bdf8', 'build' => '#a78bfa', 'send' => '#22c55e']],
                ],
            ],
            'gam_subject_followups' => [
                'accent'  => '#f59e0b',
                'enhance' => true,
                'columns' => [
                    ['key' => 'type', 'type' => 'chip', 'labelKey' => 'Pages.common.colType'],
                    ['key' => 'method', 'type' => 'chip', 'labelKey' => 'Pages.common.colMethod'],
                    ['key' => 'outcome', 'type' => 'text', 'labelKey' => 'Pages.common.colOutcome'],
                    ['key' => 'occurred_at', 'type' => 'date', 'labelKey' => 'Pages.common.colWhen'],
                ],
            ],
            'gam_subject_badges' => [
                'accent'  => '#f59e0b',
                'enhance' => true,
                'columns' => [
                    ['key' => 'badge_name', 'type' => 'strong', 'labelKey' => 'Pages.common.colBadge'],
                    ['key' => 'tier', 'type' => 'chip', 'labelKey' => 'Pages.common.colTier'],
                    ['key' => 'awarded_at', 'type' => 'date', 'labelKey' => 'Pages.common.colAwarded'],
                    ['key' => 'revoked', 'type' => 'bool', 'labelKey' => 'Pages.common.colRevoked'],
                ],
            ],
            'gam_group_campaigns' => [
                'accent'      => '#f59e0b',
                'enhance'     => true,
                'newHref'     => 'gamification/campaigns/new',
                'newLabelKey' => 'Pages.views.gam_group_campaigns.newCampaign',
                'columns' => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName', 'rowHref' => 'gamification/campaigns/{id}', 'idKey' => 'id'],
                    ['key' => 'status', 'type' => 'chip', 'labelKey' => 'Pages.common.colStatus', 'colors' => ['active' => '#22c55e', 'scheduled' => '#38bdf8', 'ended' => '#94a3b8', 'draft' => '#f59e0b']],
                    ['key' => 'starts_at', 'type' => 'date', 'labelKey' => 'Pages.common.colStarts'],
                    ['key' => 'ends_at', 'type' => 'date', 'labelKey' => 'Pages.common.colEnds'],
                ],
            ],
            'gam_campaign_leaderboard' => [
                'accent'  => '#f59e0b',
                'enhance' => true,
                'columns' => [
                    ['key' => 'rank', 'type' => 'num', 'labelKey' => 'Pages.common.colRank'],
                    ['key' => 'subject_name', 'type' => 'strong', 'labelKey' => 'Pages.common.colSubject'],
                    ['key' => 'score', 'type' => 'num', 'labelKey' => 'Pages.common.colScore'],
                ],
            ],
            'gam_campaign_team_standings' => [
                'accent'  => '#f59e0b',
                'enhance' => true,
                'columns' => [
                    ['key' => 'rank', 'type' => 'num', 'labelKey' => 'Pages.common.colRank'],
                    ['key' => 'team_name', 'type' => 'strong', 'labelKey' => 'Pages.common.colTeam'],
                    ['key' => 'score', 'type' => 'num', 'labelKey' => 'Pages.common.colScore'],
                    ['key' => 'members', 'type' => 'num', 'labelKey' => 'Pages.common.colMembers'],
                ],
            ],
            'gam_campaign_show' => [
                'accent'      => '#f59e0b',
                'detailLinks' => [
                    ['href' => 'gamification/campaigns/{id}/edit', 'labelKey' => 'Pages.views.gam_campaign_show.editCampaign'],
                    ['href' => 'gamification/campaigns/{id}/tiers/manage', 'labelKey' => 'Pages.views.gam_campaign_show.manageTiers'],
                    ['href' => 'gamification/campaigns/{id}/teams/manage', 'labelKey' => 'Pages.views.gam_campaign_show.manageTeams'],
                ],
                'detail'  => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName'],
                    ['key' => 'description', 'type' => 'text', 'labelKey' => 'Pages.common.colDescription'],
                    ['key' => 'status', 'type' => 'chip', 'labelKey' => 'Pages.common.colStatus', 'colors' => ['active' => '#22c55e', 'scheduled' => '#38bdf8', 'ended' => '#94a3b8', 'draft' => '#f59e0b']],
                    ['key' => 'starts_at', 'type' => 'date', 'labelKey' => 'Pages.common.colStarts'],
                    ['key' => 'ends_at', 'type' => 'date', 'labelKey' => 'Pages.common.colEnds'],
                ],
            ],
            'gam_campaign_tiers' => [
                'accent'  => '#f59e0b',
                'enhance' => true,
                'columns' => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colTier'],
                    ['key' => 'threshold', 'type' => 'num', 'labelKey' => 'Pages.common.colThreshold'],
                    ['key' => 'award', 'type' => 'text', 'labelKey' => 'Pages.common.colAward'],
                ],
            ],
            'gam_campaign_teams' => [
                'accent'  => '#f59e0b',
                'enhance' => true,
                'columns' => [
                    ['key' => 'name', 'type' => 'strong', 'labelKey' => 'Pages.common.colTeam'],
                    ['key' => 'members', 'type' => 'num', 'labelKey' => 'Pages.common.colMembers'],
                    ['key' => 'score', 'type' => 'num', 'labelKey' => 'Pages.common.colScore'],
                ],
            ],
        ];
    }
}
