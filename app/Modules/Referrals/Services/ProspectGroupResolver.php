<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Where a prospect BELONGS (onboarding model).
 *
 * Two rules drive this resolver:
 *
 *  1. **Every system user belongs to a group.** A user's own group — the one
 *     they are a member of — is what makes them a mentor with somewhere to bring
 *     people. {@see homeGroupOf()} picks it out of their active memberships.
 *
 *  2. **A prospect is NOT offered a choice of group.** They are placed in the
 *     group of the mentor/sponsor who owns them, so placement is derived from a
 *     person, never submitted by the prospect (or picked in a form). That is what
 *     {@see placementFor()} does — and why it takes the mentor's id, not a
 *     group id.
 *
 * Placement therefore follows the mentor. When the mentor changes (see
 * {@see ProspectTransferService}), the placement changes with them.
 */
final class ProspectGroupResolver
{
    /** Roles that make a membership the user's "home" (they lead there). */
    private const LEAD_ROLES = ['leader', 'coordinator', 'admin'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * The group a user belongs to — their home for placement purposes.
     *
     * A user can hold several memberships (a cell leader who also sits on an
     * area team), so one is chosen deterministically: a group they LEAD beats a
     * group they merely attend; then the DEEPEST group in the hierarchy
     * (National→Region→Area→Local Assembly→Fellowship→Senior Cell→Cell), because
     * the most local body is where they actually bring people; then the earliest
     * joined. Returns null when the user holds no active membership — which the
     * "everyone belongs" invariant treats as something to repair, not to guess.
     */
    public function homeGroupOf(string $organizationId, string $userId): ?string
    {
        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }

        $rows = $this->db->table('group_members')
            ->select('group_id, role, membership_type, joined_at')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->get()->getResultArray();
        if ($rows === []) {
            return null;
        }

        // Second bounded read for the hierarchy facts (depth + group status)
        // rather than a JOIN: the ranking happens in PHP anyway, and keeping the
        // two selects explicit avoids both a dialect-specific ORDER BY expression
        // and an ambiguous `status` column (membership status vs group status).
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $r) => (string) ($r['group_id'] ?? ''),
            $rows,
        ))));
        if ($ids === []) {
            return null;
        }

        /** @var array<string,array<string,mixed>> $groups */
        $groups = [];
        foreach ($this->db->table('groups')->select('id, depth, status')->whereIn('id', $ids)->get()->getResultArray() as $g) {
            $groups[(string) ($g['id'] ?? '')] = $g;
        }

        $ranked = [];
        foreach ($rows as $r) {
            $gid = (string) ($r['group_id'] ?? '');
            $g   = $groups[$gid] ?? null;
            // A torn-down/archived group (or a dangling membership) is not a home.
            if ($g === null || (string) ($g['status'] ?? 'active') !== 'active') {
                continue;
            }
            $ranked[] = [
                'group_id' => $gid,
                'lead'     => in_array((string) ($r['role'] ?? ''), self::LEAD_ROLES, true) ? 1 : 0,
                'depth'    => (int) ($g['depth'] ?? 0),
                'joined'   => (string) ($r['joined_at'] ?? ''),
            ];
        }
        if ($ranked === []) {
            return null;
        }

        usort($ranked, static fn (array $a, array $b) => ($b['lead'] <=> $a['lead'])
            ?: ($b['depth'] <=> $a['depth'])
            ?: strcmp($a['joined'], $b['joined']));

        return $ranked[0]['group_id'];
    }

    /**
     * The group a prospect is placed in: THEIR MENTOR'S group.
     *
     * $requestedGroupId is deliberately advisory only — a prospect is not given a
     * choice, so a submitted value is honoured ONLY when it already agrees with
     * the mentor's home group (i.e. it carries no choice at all). Staff bulk
     * sign-up passes the leader's own group, which satisfies that; a prospect
     * picking "somewhere else" does not and is overridden.
     *
     * Falls back to the requested group when the mentor has no home group (so a
     * brand-new leader's contacts are still placed rather than orphaned), and to
     * null when neither is known.
     */
    public function placementFor(string $organizationId, string $mentorUserId, ?string $requestedGroupId = null): ?string
    {
        $home = $this->homeGroupOf($organizationId, $mentorUserId);
        if ($home !== null) {
            return $home;
        }

        $requested = is_string($requestedGroupId) ? trim($requestedGroupId) : '';

        return $requested !== '' ? $requested : null;
    }

    /**
     * Days since the prospect was last touched — `last_contacted_at`, falling
     * back to `created_at` for a contact nobody has followed up yet. Null when
     * neither is set (treated as "not inactive": we never transfer on a guess).
     *
     * @param array<string,mixed> $contact
     */
    public static function daysSinceContact(array $contact, ?\DateTimeImmutable $now = null): ?int
    {
        $raw = $contact['last_contacted_at'] ?? $contact['created_at'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $ts = strtotime(trim($raw));
        if ($ts === false) {
            return null;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return (int) floor(($now->getTimestamp() - $ts) / 86400);
    }
}
