<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Group lifecycle state machine — archive / merge / dissolve with evidence
 * (SRS FR-GRP-005). Mirrors the identity account-lifecycle model (FR-ID-009):
 *
 *   active  ⇄ archived            (reversible; archived is a soft, hidden state)
 *   active  → dissolved           (terminal; only an EMPTY leaf may dissolve)
 *   archived→ dissolved           (terminal)
 *   active  → merged              (terminal; children re-parented + members moved
 *   archived→ merged               into the surviving group)
 *
 * Every transition demands a stated reason, writes an append-only evidence row
 * to `group_lifecycle_transitions`, and records an immutable audit-log entry.
 * `dissolved` and `merged` are terminal — nothing resurrects them.
 */
final class GroupLifecycleService
{
    /** Allowed target states per current state. Terminal states have none. */
    private const TRANSITIONS = [
        'active'    => ['archived', 'dissolved', 'merged'],
        'archived'  => ['active', 'dissolved', 'merged'],
        'dissolved' => [],
        'merged'    => [],
    ];

    public const STATES = ['active', 'archived', 'dissolved', 'merged'];

    /**
     * Pure state-machine rule: may a group move from $from to $to? Terminal
     * states (`dissolved`, `merged`) permit nothing; an unknown legacy status
     * permits any known forward move but never resurrects a terminal state.
     * Exposed static for unit testing the transition matrix.
     */
    public static function canTransition(string $from, string $to): bool
    {
        if (! in_array($to, self::STATES, true) || $from === $to) {
            return false;
        }
        $allowed = self::TRANSITIONS[$from] ?? self::STATES;

        return in_array($to, $allowed, true);
    }

    /**
     * Canonical group teardown topics per terminal/hidden state (GR-emit). The
     * group-side analogue of the Identity ID1 account emitter: the single signal
     * every group-scoped dependent (grants, invites, causes, journeys) subscribes
     * to. `merged` is emitted by the merge flow (it carries the survivor); the
     * others map straight from the target status.
     */
    private const LIFECYCLE_TOPIC = [
        'archived'  => 'group.archived',
        'dissolved' => 'group.dissolved',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly GroupService $groups,
        private readonly ?OutboxService $outbox = null,
    ) {
    }

    // ---- convenience wrappers (each still requires a reason) ---------------

    public function archive(string $organizationId, string $groupId, string $reason, array $opts = []): Result
    {
        return $this->transition($organizationId, $groupId, 'archived', $reason, $opts);
    }

    public function reactivate(string $organizationId, string $groupId, string $reason, array $opts = []): Result
    {
        return $this->transition($organizationId, $groupId, 'active', $reason, $opts);
    }

    public function dissolve(string $organizationId, string $groupId, string $reason, array $opts = []): Result
    {
        return $this->transition($organizationId, $groupId, 'dissolved', $reason, $opts);
    }

    /**
     * Merge $groupId INTO $survivorId: re-parent the merged group's children
     * under the survivor, transfer its active members, then mark it `merged`.
     *
     * @param array<string,mixed> $opts actor_id, approval_ref, evidence[]
     */
    public function merge(string $organizationId, string $groupId, string $survivorId, string $reason, array $opts = []): Result
    {
        $survivorId = trim($survivorId);
        if ($survivorId === '') {
            return Result::fail('SURVIVOR_REQUIRED', 'group.merge_survivor_required', 422);
        }
        if ($survivorId === $groupId) {
            return Result::fail('SELF_MERGE', 'group.merge_self', 422);
        }

        $survivor = $this->groupInOrg($organizationId, $survivorId);
        if ($survivor === null) {
            return Result::notFound('group.merge_survivor_not_found', 'SURVIVOR_NOT_FOUND');
        }
        if (($survivor['status'] ?? '') === 'dissolved' || ($survivor['status'] ?? '') === 'merged') {
            return Result::fail('SURVIVOR_TERMINAL', 'group.merge_survivor_terminal', 409, ['status' => $survivor['status']]);
        }
        // The survivor must not live inside the merged group's own subtree, or
        // re-parenting its children would create a cycle.
        $survivorInSubtree = $this->db->table('group_closure')
            ->where('ancestor_id', $groupId)
            ->where('descendant_id', $survivorId)
            ->countAllResults() > 0;
        if ($survivorInSubtree) {
            return Result::fail('SURVIVOR_IN_SUBTREE', 'group.merge_survivor_in_subtree', 422);
        }

        return $this->transition($organizationId, $groupId, 'merged', $reason, $opts + ['merged_into_id' => $survivorId]);
    }

    /**
     * Current lifecycle status + display name for a group, or null when the group
     * is not in this org. A read helper for the lifecycle console so it can gate
     * the governance controls to the transitions actually allowed from here.
     *
     * @return array{id:string,name:string,status:string}|null
     */
    public function current(string $organizationId, string $groupId): ?array
    {
        $row = $this->groupInOrg($organizationId, $groupId);
        if ($row === null) {
            return null;
        }

        return [
            'id'     => (string) $row['id'],
            'name'   => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
        ];
    }

    /** @return list<array<string,mixed>> newest-first lifecycle history */
    public function history(string $organizationId, string $groupId): array
    {
        return $this->db->table('group_lifecycle_transitions')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }

    // ---- core --------------------------------------------------------------

    /**
     * Transition a group to a new lifecycle state with a stated reason + evidence.
     *
     * @param array<string,mixed> $opts actor_id, approval_ref, evidence[], merged_into_id
     */
    public function transition(string $organizationId, string $groupId, string $toStatus, string $reason, array $opts = []): Result
    {
        $toStatus = strtolower(trim($toStatus));
        $reason   = trim($reason);
        if ($reason === '') {
            return Result::fail('REASON_REQUIRED', 'group.status_reason_required', 422);
        }
        if (! in_array($toStatus, self::STATES, true)) {
            return Result::fail('BAD_STATUS', 'group.status_unknown', 422, ['status' => $toStatus]);
        }

        $group = $this->groupInOrg($organizationId, $groupId);
        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }

        $from = (string) ($group['status'] ?? '');
        if ($from === $toStatus) {
            return Result::fail('NO_CHANGE', 'group.status_unchanged', 409, ['status' => $from]);
        }
        if (! self::canTransition($from, $toStatus)) {
            return Result::fail('ILLEGAL_TRANSITION', 'group.status_illegal_transition', 409, [
                'from' => $from,
                'to'   => $toStatus,
            ]);
        }

        // Dissolving must not orphan a subtree or strand members. A group may be
        // dissolved only when it has no ACTIVE child groups and no ACTIVE members
        // (archive or merge those first — this keeps dissolve a clean tear-down).
        if ($toStatus === 'dissolved') {
            if ($this->activeChildCount($groupId) > 0) {
                return Result::fail('HAS_CHILDREN', 'group.dissolve_has_children', 409);
            }
            if ($this->activeMemberCount($organizationId, $groupId) > 0) {
                return Result::fail('HAS_MEMBERS', 'group.dissolve_has_members', 409);
            }
        }

        $now      = $this->clock->nowUtcString();
        $nowMicro = $this->clock->nowUtcMicro();
        $actorId  = isset($opts['actor_id']) ? (string) $opts['actor_id'] : null;
        $survivorId = $toStatus === 'merged' && ! empty($opts['merged_into_id'])
            ? (string) $opts['merged_into_id']
            : null;

        $update = [
            'status'            => $toStatus,
            'status_reason'     => $reason,
            'status_changed_at' => $now,
            'status_changed_by' => $actorId,
            'updated_at'        => $now,
        ];
        if ($toStatus === 'archived') {
            $update['archived_at'] = $now;
        }
        if ($toStatus === 'active') {
            $update['archived_at'] = null; // clear on reactivation
        }
        if ($toStatus === 'dissolved') {
            $update['dissolved_at'] = $now;
        }
        if ($toStatus === 'merged') {
            $update['merged_into_id'] = $survivorId;
        }

        $moved = ['children' => 0, 'members' => 0];

        $this->db->transStart();
        try {
            // A merge restructures the tree BEFORE the merged node is retired.
            if ($toStatus === 'merged' && $survivorId !== null) {
                $moved['children'] = $this->reparentChildren($organizationId, $groupId, $survivorId, $now);
                $moved['members']  = $this->transferMembers($organizationId, $groupId, $survivorId, $actorId, $now);
            }

            $this->db->table('groups')->where('id', $groupId)->update($update);

            // GR2 — a TERMINAL group (dissolved / merged) must leave the closure
            // projection so no resolver (scope / config / rollup / sponsor) keeps
            // resolving a dead node. Archived is reversible, so its closure is
            // deliberately preserved; only the two terminal states prune. On a
            // merge the children were already re-parented under the survivor above
            // (reparentChildren -> GroupService::move rebuilds their closure), so
            // only the merged node's own rows remain to be removed.
            if ($toStatus === 'dissolved' || $toStatus === 'merged') {
                $this->pruneClosure($groupId);
            }

            $this->db->table('group_lifecycle_transitions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'group_id'        => $groupId,
                'from_status'     => $from !== '' ? $from : null,
                'to_status'       => $toStatus,
                'reason'          => $reason,
                'actor_id'        => $actorId,
                'approval_ref'    => $opts['approval_ref'] ?? null,
                'merged_into_id'  => $survivorId,
                'evidence'        => isset($opts['evidence']) ? json_encode($opts['evidence']) : null,
                'created_at'      => $nowMicro,
            ]);

            // GR-emit — canonical group teardown signal, staged INSIDE the
            // transaction so the event is durable exactly with the state change
            // (no signal on a rolled-back transition, no lost signal on a
            // committed one). The group-side analogue of the Identity ID1
            // account emitter. archived / dissolved map straight from status …
            if ($this->outbox !== null && isset(self::LIFECYCLE_TOPIC[$toStatus])) {
                $this->outbox->stage('group', $groupId, self::LIFECYCLE_TOPIC[$toStatus], [
                    'group_id'        => $groupId,
                    'organization_id' => $organizationId,
                    // The dead group's parent, captured BEFORE the closure is
                    // pruned, so dissolve consumers can roll belongings up to the
                    // nearest surviving ancestor (C6 contribution attribution).
                    'parent_id'       => isset($group['parent_id']) ? (string) $group['parent_id'] : null,
                    'from_status'     => $from !== '' ? $from : null,
                    'to_status'       => $toStatus,
                    'actor_id'        => $actorId,
                    'reason'          => $reason,
                    'source_ref'      => 'group_transition:' . $groupId . ':' . $toStatus,
                ], $organizationId);
            }

            // … and merge carries the SURVIVOR so consumers can re-point the
            // merged group's scoped belongings to it (through their own authorized
            // path). Emitted only when a survivor is known.
            if ($this->outbox !== null && $toStatus === 'merged' && $survivorId !== null) {
                $this->outbox->stage('group', $groupId, 'group.merged', [
                    'group_id'         => $groupId,
                    'from_group_id'    => $groupId,
                    'into_group_id'    => $survivorId,
                    'survivor_group_id' => $survivorId,
                    'organization_id'  => $organizationId,
                    'parent_id'        => isset($group['parent_id']) ? (string) $group['parent_id'] : null,
                    'from_status'      => $from !== '' ? $from : null,
                    'to_status'        => $toStatus,
                    'actor_id'         => $actorId,
                    'reason'           => $reason,
                    'approval_ref'     => $opts['approval_ref'] ?? null,
                    'source_ref'       => 'group_merge:' . $groupId . ':' . $survivorId,
                ], $organizationId);
            }
        } catch (Throwable) {
            $this->db->transComplete();

            return Result::fail('TRANSITION_FAILED', 'group.status_transition_failed', 500);
        }
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('TRANSITION_FAILED', 'group.status_transition_failed', 500);
        }

        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'group.lifecycle.' . $toStatus,
            'object_type' => 'group',
            'object_id'   => $groupId,
            'metadata'    => [
                'from'           => $from,
                'to'             => $toStatus,
                'reason'         => $reason,
                'approval_ref'   => $opts['approval_ref'] ?? null,
                'merged_into_id' => $survivorId,
                'moved'          => $survivorId !== null ? $moved : null,
            ],
        ]);

        return Result::ok([
            'group_id'       => $groupId,
            'from'           => $from,
            'to'             => $toStatus,
            'merged_into_id' => $survivorId,
            'moved'          => $survivorId !== null ? $moved : null,
        ]);
    }

    // ---- merge helpers -----------------------------------------------------

    /**
     * Re-parent the merged group's DIRECT children under the survivor, reusing
     * GroupService::move so the closure table + paths/depths stay correct.
     *
     * @return int number of children re-parented
     */
    private function reparentChildren(string $organizationId, string $groupId, string $survivorId, string $now): int
    {
        $children = $this->db->table('groups')
            ->select('id')
            ->where('organization_id', $organizationId)
            ->where('parent_id', $groupId)
            ->get()->getResultArray();

        $count = 0;
        foreach ($children as $child) {
            $res = $this->groups->move((string) $child['id'], $survivorId);
            if ($res->ok) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Transfer the merged group's ACTIVE members to the survivor. Respects the
     * one-active-per-(user, group, type) invariant: a member already active in
     * the survivor with the same membership_type is simply left ended in the old
     * group (no duplicate active row). Otherwise the membership is re-pointed to
     * the survivor and its active_key recomputed.
     *
     * @return int number of memberships moved into the survivor
     */
    private function transferMembers(string $organizationId, string $groupId, string $survivorId, ?string $actorId, string $now): int
    {
        $members = $this->db->table('group_members')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->get()->getResultArray();

        $moved = 0;
        foreach ($members as $m) {
            $userId = (string) $m['user_id'];
            $type   = (string) ($m['membership_type'] ?? 'member');

            $existing = $this->db->table('group_members')
                ->where('organization_id', $organizationId)
                ->where('group_id', $survivorId)
                ->where('user_id', $userId)
                ->where('membership_type', $type)
                ->where('status', 'active')
                ->countAllResults() > 0;

            if ($existing) {
                // Already active in the survivor → end the old row (no dup).
                $this->db->table('group_members')->where('id', $m['id'])->update([
                    'status'      => 'ended',
                    'left_at'     => $now,
                    'leave_reason' => 'merged_into_survivor',
                    'active_key'  => null,
                    'updated_at'  => $now,
                ]);
                continue;
            }

            // Re-point to the survivor; recompute the active_key guard hash.
            $newKey = hash('sha256', $userId . ':' . $survivorId . ':' . $type);
            $this->db->table('group_members')->where('id', $m['id'])->update([
                'group_id'   => $survivorId,
                'active_key' => $newKey,
                'updated_at' => $now,
            ]);
            $moved++;
        }

        return $moved;
    }

    /**
     * GR2 — remove a terminal group from the `group_closure` projection.
     *
     * Deletes every closure row that mentions the node as ancestor OR descendant
     * (including its own distance-0 self row). Dissolve is only permitted for an
     * empty leaf, so a dissolved node has no descendant rows to strand; a merged
     * node's children were re-parented before this runs, so their edges already
     * point at the survivor's subtree — this just drops the merged node's own
     * ancestor/descendant/self rows. Idempotent: a re-run deletes nothing.
     */
    private function pruneClosure(string $groupId): void
    {
        $this->db->table('group_closure')->where('ancestor_id', $groupId)->delete();
        $this->db->table('group_closure')->where('descendant_id', $groupId)->delete();
    }

    // ---- guards ------------------------------------------------------------

    private function activeChildCount(string $groupId): int
    {
        return $this->db->table('groups')
            ->where('parent_id', $groupId)
            ->whereNotIn('status', ['dissolved', 'merged'])
            ->countAllResults();
    }

    private function activeMemberCount(string $organizationId, string $groupId): int
    {
        return $this->db->table('group_members')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->countAllResults();
    }

    /** @return array<string,mixed>|null */
    private function groupInOrg(string $organizationId, string $groupId): ?array
    {
        return $this->db->table('groups')
            ->where('id', $groupId)
            ->where('organization_id', $organizationId)
            ->get()->getRowArray();
    }
}
