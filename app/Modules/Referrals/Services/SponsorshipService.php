<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Unilevel sponsorship graph (SRS FR-MEM-001/002).
 *
 * Guarantees:
 *  - ACYCLIC: a member cannot sponsor an ancestor (walk the chain up first).
 *  - SINGLE-ACTIVE: exactly one active sponsor per member, enforced by the
 *    active_key UNIQUE index (active_key = member_id while active, NULL when
 *    superseded).
 *  - Re-parenting NEVER rewrites historical attribution/ledger — it closes the
 *    old edge (active=0, effective_to set) and opens a new one.
 */
final class SponsorshipService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /** Assign (or reassign) a member's active sponsor. */
    public function assign(string $organizationId, string $memberId, string $sponsorId, string $reason = ''): Result
    {
        if ($memberId === $sponsorId) {
            return Result::fail('SELF_SPONSOR', 'sponsorship.self', 422);
        }
        if ($this->wouldCycle($memberId, $sponsorId)) {
            return Result::fail('CYCLE', 'sponsorship.cycle', 422);
        }

        $now = $this->clock->nowUtcString();

        $this->db->transStart();

        // Close the current active edge, if any.
        $current = $this->db->table('sponsorships')
            ->where('member_id', $memberId)->where('active', 1)
            ->get()->getRowArray();
        if ($current !== null) {
            if ((string) $current['sponsor_id'] === $sponsorId) {
                $this->db->transComplete();

                return Result::ok(['member_id' => $memberId, 'sponsor_id' => $sponsorId], 200, ['unchanged' => true]);
            }
            $this->db->table('sponsorships')->where('id', $current['id'])->update([
                'active'         => 0,
                'active_key'     => null,
                'effective_to'   => $now,
            ]);
        }

        try {
            $id = Uuid::v7();
            $this->db->table('sponsorships')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'member_id'       => $memberId,
                'sponsor_id'      => $sponsorId,
                'active'          => 1,
                'active_key'      => $memberId, // UNIQUE -> one active per member
                'effective_from'  => $now,
                'effective_to'    => null,
                'reason'          => $reason !== '' ? $reason : null,
                'created_at'      => $now,
            ]);
        } catch (Throwable) {
            $this->db->transComplete();

            return Result::fail('ASSIGN_FAILED', 'sponsorship.assign_failed', 409);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('ASSIGN_FAILED', 'sponsorship.assign_failed', 500);
        }

        return Result::created(['id' => $id, 'member_id' => $memberId, 'sponsor_id' => $sponsorId]);
    }

    /**
     * Account teardown (Theme B, R7) — a gone/frozen person must stop attracting
     * NEW sponsorship links. Disables the subject's ACTIVE cloaked referral links
     * so a fresh click/capture can no longer auto-link a prospect under a sponsor
     * who is no longer active (ReferralService::resolve only returns `active`
     * links, so a disabled link resolves to nothing).
     *
     * Historical sponsorship edges are deliberately left intact — this platform
     * never rewrites attribution history, and moving an existing downline to a
     * live sponsor is the reviewed reassignment workflow's job, not a blind
     * teardown. SYSTEM authority, idempotent (a re-run disables 0), empty inputs
     * -> 0.
     *
     * @return int number of referral links disabled
     */
    public function onAccountTornDown(string $organizationId, string $subjectId, string $reasonCode): int
    {
        if ($organizationId === '' || $subjectId === '') {
            return 0;
        }

        $this->db->table('referral_links')
            ->where('organization_id', $organizationId)
            ->where('referrer_id', $subjectId)
            ->where('status', 'active')
            ->update([
                'status' => 'disabled',
            ]);

        return $this->db->affectedRows();
    }

    /**
     * Person merge (Theme B, R7 merge half) — re-point the LOSER's sponsorship
     * edges to the SURVIVOR through the reassignment path (close-old / open-new,
     * history preserved). Runs after the loser's authority is torn down:
     *
     *   - the loser's DOWNLINE (edges where sponsor_id = loser) is re-parented to
     *     the survivor via {@see assign()}, which enforces acyclicity +
     *     single-active and never rewrites issued attribution. A downline member
     *     who IS the survivor (survivor cannot sponsor itself) or whose re-parent
     *     would cycle is left on its historical edge and counted as skipped,
     *     rather than being forced into an invalid graph;
     *   - the loser's OWN active member edge (member_id = loser) is CLOSED — the
     *     loser no longer exists as a distinct member and the survivor keeps their
     *     own sponsor (no active_key collision, history retained);
     *   - the loser's cloaked referral LINKS (referrer_id = loser) are re-pointed
     *     to the survivor so their in-flight funnels now credit the survivor.
     *
     * loser == survivor / empty inputs -> no-op. Idempotent: a re-run finds no
     * remaining loser edges/links and reports zeros.
     *
     * @return array{downline_repointed:int,downline_skipped:int,member_edges_closed:int,links_repointed:int}
     */
    public function reassignForMerge(string $organizationId, string $loserId, string $survivorId): array
    {
        $zero = [
            'downline_repointed'  => 0,
            'downline_skipped'    => 0,
            'member_edges_closed' => 0,
            'links_repointed'     => 0,
        ];
        if ($organizationId === '' || $loserId === '' || $survivorId === '' || $loserId === $survivorId) {
            return $zero;
        }

        $now    = $this->clock->nowUtcString();
        $reason = 'merge:' . $loserId . '->' . $survivorId;

        // 1) Re-parent the loser's active downline to the survivor.
        $downline = $this->db->table('sponsorships')
            ->select('member_id')
            ->where('organization_id', $organizationId)
            ->where('sponsor_id', $loserId)
            ->where('active', 1)
            ->get()->getResultArray();

        $repointed = 0;
        $skipped   = 0;
        foreach ($downline as $r) {
            $memberId = (string) $r['member_id'];
            if ($memberId === $survivorId) {
                // The survivor cannot sponsor themselves: close the historical
                // edge only, never re-open it.
                $this->closeActiveEdge($organizationId, $memberId, $loserId, $now, $reason);
                $skipped++;

                continue;
            }
            // assign() closes the member's current (loser) edge and opens a fresh
            // survivor edge, guarding self-sponsor + cycles.
            $res = $this->assign($organizationId, $memberId, $survivorId, $reason);
            $res->ok ? $repointed++ : $skipped++;
        }

        // 2) Close the loser's OWN active member edge (they are gone as a member).
        $this->db->table('sponsorships')
            ->where('organization_id', $organizationId)
            ->where('member_id', $loserId)
            ->where('active', 1)
            ->update([
                'active'       => 0,
                'active_key'   => null,
                'effective_to' => $now,
                'reason'       => $reason,
            ]);
        $memberEdgesClosed = $this->db->affectedRows();

        // 3) Re-point the loser's cloaked referral links to the survivor so their
        //    in-flight funnels now credit the survivor.
        $this->db->table('referral_links')
            ->where('organization_id', $organizationId)
            ->where('referrer_id', $loserId)
            ->update([
                'referrer_id' => $survivorId,
            ]);
        $linksRepointed = $this->db->affectedRows();

        return [
            'downline_repointed'  => $repointed,
            'downline_skipped'    => $skipped,
            'member_edges_closed' => $memberEdgesClosed,
            'links_repointed'     => $linksRepointed,
        ];
    }

    /** Close a specific active edge (member sponsored by sponsor) without re-opening. */
    private function closeActiveEdge(string $organizationId, string $memberId, string $sponsorId, string $now, string $reason): void
    {
        $this->db->table('sponsorships')
            ->where('organization_id', $organizationId)
            ->where('member_id', $memberId)
            ->where('sponsor_id', $sponsorId)
            ->where('active', 1)
            ->update([
                'active'       => 0,
                'active_key'   => null,
                'effective_to' => $now,
                'reason'       => $reason,
            ]);
    }

    /** The member's current active sponsor id, or null. */
    public function activeSponsor(string $memberId): ?string
    {
        $row = $this->db->table('sponsorships')
            ->select('sponsor_id')
            ->where('member_id', $memberId)->where('active', 1)
            ->get()->getRowArray();

        return $row !== null ? (string) $row['sponsor_id'] : null;
    }

    /**
     * Upline chain (nearest sponsor first), bounded to avoid runaway loops.
     *
     * @return list<string>
     */
    public function upline(string $memberId, int $maxLevels = 20): array
    {
        $chain   = [];
        $current = $memberId;
        for ($i = 0; $i < $maxLevels; $i++) {
            $sponsor = $this->activeSponsor($current);
            if ($sponsor === null || in_array($sponsor, $chain, true)) {
                break;
            }
            $chain[] = $sponsor;
            $current = $sponsor;
        }

        return $chain;
    }

    /** Direct (level-1) active recruits of a member. @return list<string> */
    public function directRecruits(string $sponsorId): array
    {
        $rows = $this->db->table('sponsorships')
            ->select('member_id')
            ->where('sponsor_id', $sponsorId)->where('active', 1)
            ->get()->getResultArray();

        return array_map(static fn ($r): string => (string) $r['member_id'], $rows);
    }

    /**
     * Full downline (every member whose active sponsor chain leads back to
     * $sponsorId), resolved by breadth-first walk over the sponsorships graph.
     * Bounded by $maxLevels and cycle-guarded. Pass $maxLevels=1 for direct
     * recruits only. Replaces the reference library's raw recursive CTE over an
     * integer users.sponsor_id column.
     *
     * @return list<string>
     */
    public function downline(string $sponsorId, int $maxLevels = 7): array
    {
        $collected = [];
        $frontier  = [$sponsorId];
        $seen      = [$sponsorId => true];

        for ($level = 0; $level < $maxLevels && $frontier !== []; $level++) {
            $rows = $this->db->table('sponsorships')
                ->select('member_id')
                ->whereIn('sponsor_id', $frontier)->where('active', 1)
                ->get()->getResultArray();

            $next = [];
            foreach ($rows as $r) {
                $m = (string) $r['member_id'];
                if (isset($seen[$m])) {
                    continue; // cycle / diamond guard
                }
                $seen[$m]    = true;
                $collected[] = $m;
                $next[]      = $m;
            }
            $frontier = $next;
        }

        return $collected;
    }

    /** True if making $sponsorId sponsor $memberId would create a cycle. */
    private function wouldCycle(string $memberId, string $sponsorId): bool
    {
        // If memberId is already anywhere in sponsorId's upline, it's a cycle.
        return in_array($memberId, $this->upline($sponsorId), true);
    }
}
