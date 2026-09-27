<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Production CampaignAudiencePort (N2). Resolves a campaign's recipients from the
 * platform directory, deliberately simple and bounded:
 *
 *   - a GROUP-scoped campaign (`group_id` set) targets that group AND all its
 *     descendants (via `group_closure`), so a Region campaign reaches every
 *     Area/Assembly/Cell beneath it — the same down-tree semantics used
 *     elsewhere;
 *   - an ORG-wide campaign (`group_id` null) targets every distinct member of
 *     the organization.
 *
 * Returns DISTINCT user ids only; per-recipient consent (opt-out / quiet-hours /
 * verified-channel) is enforced downstream by the RetentionPolicyGate on each
 * send(), so this does not pre-filter for consent. The richer `audience_filter`
 * predicate tree is intentionally NOT interpreted here yet — this establishes the
 * concrete fan-out path; filter evaluation can layer on without changing callers.
 */
final class CampaignAudienceResolver implements CampaignAudiencePort
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string,mixed> $campaign
     * @return list<string>
     */
    public function resolve(array $campaign): array
    {
        $orgId   = (string) ($campaign['organization_id'] ?? '');
        $groupId = isset($campaign['group_id']) ? (string) $campaign['group_id'] : '';
        if ($orgId === '') {
            return [];
        }

        if ($groupId !== '') {
            // The group + every descendant (group_closure includes the self row).
            $descendants = $this->db->table('group_closure')
                ->select('descendant_id')
                ->where('ancestor_id', $groupId)
                ->get()->getResultArray();
            $groupIds = array_map(static fn ($r) => (string) $r['descendant_id'], $descendants);
            if ($groupIds === []) {
                $groupIds = [$groupId];
            }

            $rows = $this->db->table('group_members')
                ->select('user_id')
                ->where('organization_id', $orgId)
                ->whereIn('group_id', $groupIds)
                ->get()->getResultArray();
        } else {
            $rows = $this->db->table('group_members')
                ->select('user_id')
                ->where('organization_id', $orgId)
                ->get()->getResultArray();
        }

        // Distinct, non-empty user ids.
        $seen = [];
        foreach ($rows as $r) {
            $uid = (string) ($r['user_id'] ?? '');
            if ($uid !== '') {
                $seen[$uid] = true;
            }
        }

        return array_keys($seen);
    }
}
