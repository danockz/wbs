<?php

declare(strict_types=1);

namespace WBS\Community\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Content reporting + moderation (SRS FR-COM-003).
 *
 * Members report content/users; moderators act with a MANDATORY reason and
 * recorded policy basis. Every action is appended to moderation_actions as
 * evidence — content is hidden/deleted by state transition, never physically
 * erased here, so restore and audit remain possible. Deleted/hidden content is
 * excluded from feed reads (and callers must evict it from any search index).
 */
final class ModerationService
{
    private const ACTIONS = ['hide', 'delete', 'restore', 'lock', 'unlock', 'mute', 'ban', 'escalate'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    public function report(
        string $organizationId,
        string $subjectType,
        string $subjectId,
        string $reporterId,
        string $reasonCode,
        string $detail = '',
    ): Result {
        if (! in_array($subjectType, ['post', 'comment', 'user'], true)) {
            return Result::fail('BAD_SUBJECT', 'community.bad_subject', 422);
        }
        if (trim($reasonCode) === '') {
            return Result::fail('REASON_REQUIRED', 'community.reason_required', 422);
        }

        $id = Uuid::v7();
        try {
            $this->db->table('content_reports')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'subject_type'    => $subjectType,
                'subject_id'      => $subjectId,
                'reporter_id'     => $reporterId,
                'reason_code'     => $reasonCode,
                'detail'          => mb_substr($detail, 0, 1000),
                'status'          => 'open',
                'created_at'      => $this->clock->nowUtcMicro(),
            ]);
        } catch (\Throwable) {
            // UNIQUE(subject_type, subject_id, reporter_id): one report per reporter.
            return Result::ok(['status' => 'already_reported'], 200, ['deduplicated' => true]);
        }

        return Result::created(['report_id' => $id, 'status' => 'open']);
    }

    /**
     * Apply a moderator action. Reason is mandatory; the action is recorded as
     * evidence and the target's state transitioned accordingly.
     */
    public function act(
        string $organizationId,
        string $moderatorId,
        string $subjectType,
        string $subjectId,
        string $action,
        string $reason,
        ?string $policyBasis = null,
        ?string $reportId = null,
    ): Result {
        if (! in_array($action, self::ACTIONS, true)) {
            return Result::fail('BAD_ACTION', 'community.bad_action', 422);
        }
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'community.reason_required', 422);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->transStart();

        $this->applyStateChange($subjectType, $subjectId, $action, $now);

        $actionId = Uuid::v7();
        $this->db->table('moderation_actions')->insert([
            'id'              => $actionId,
            'organization_id' => $organizationId,
            'moderator_id'    => $moderatorId,
            'subject_type'    => $subjectType,
            'subject_id'      => $subjectId,
            'action'          => $action,
            'reason'          => mb_substr($reason, 0, 1000),
            'policy_basis'    => $policyBasis,
            'report_id'       => $reportId,
            'created_at'      => $now,
        ]);

        if ($reportId !== null) {
            $this->db->table('content_reports')->where('id', $reportId)->update([
                'status'      => $action === 'escalate' ? 'escalated' : 'actioned',
                'resolved_at' => $now,
            ]);
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return Result::fail('MODERATION_FAILED', 'community.moderation_failed', 500);
        }

        return Result::ok(['action_id' => $actionId, 'action' => $action]);
    }

    /**
     * CM4 — RETENTION PURGE. Soft-deleted content (`status='deleted'`) is kept so
     * moderators can restore it and the audit trail (`moderation_actions`) stays
     * intact, but nothing ever hard-removed it — deleted rows lingered forever.
     * This bounded, idempotent pass hard-deletes posts whose `deleted_at` is
     * older than the grace window, cascading to their comments, reactions and
     * topic links; it also purges comments that were deleted DIRECTLY (their post
     * still active) past the same window. The append-only `moderation_actions`
     * evidence is NEVER touched, so the record that content once existed and was
     * removed survives the purge.
     *
     * PRECEDENCE: only `status='deleted'` rows with a non-null `deleted_at` past
     * grace are eligible. `hidden` and `archived` are reversible/serving states,
     * not terminal, and are never purged.
     *
     * NEVER touches an active/hidden row (guards `status='deleted'` AND
     * `deleted_at <= cutoff` in the SELECT and re-asserts it in the DELETE), so a
     * restored row can never be purged. Bounded batch; a second pass in the same
     * window purges 0.
     *
     * @return array{scanned:int, posts:int, comments:int}
     */
    public function purgeDeletedContent(?string $organizationId = null, int $graceDays = 30, int $limit = 500): array
    {
        $graceDays = max(0, $graceDays);
        $limit     = max(1, min(5000, $limit));
        $cutoff    = $this->clock->now()->modify('-' . $graceDays . ' days')->format('Y-m-d H:i:s.u');

        // ---- posts past grace (cascade to their children) ---------------------
        $pq = $this->db->table('community_posts')
            ->select('id')
            ->where('status', 'deleted')
            ->where('deleted_at IS NOT NULL', null, false)
            ->where('deleted_at <=', $cutoff)
            ->orderBy('deleted_at', 'ASC');
        if ($organizationId !== null && $organizationId !== '') {
            $pq->where('organization_id', $organizationId);
        }
        $postIds = array_map(static fn ($r) => (string) $r['id'], $pq->get($limit)->getResultArray());

        $postsPurged    = 0;
        $commentsPurged = 0;

        if ($postIds !== []) {
            $this->db->transStart();
            // Cascade children first (no FKs in schema — purge explicitly).
            $childComments = $this->db->table('community_comments')->whereIn('post_id', $postIds)->countAllResults();
            $this->db->table('community_comments')->whereIn('post_id', $postIds)->delete();
            $this->db->table('community_reactions')->whereIn('post_id', $postIds)->delete();
            $this->db->table('community_post_topics')->whereIn('post_id', $postIds)->delete();
            // Re-assert the terminal predicate so a row restored between SELECT and
            // here is never hard-deleted.
            $this->db->table('community_posts')
                ->whereIn('id', $postIds)
                ->where('status', 'deleted')
                ->where('deleted_at <=', $cutoff)
                ->delete();
            $affected = $this->db->affectedRows();
            $this->db->transComplete();

            if ($this->db->transStatus() !== false) {
                $postsPurged    = is_int($affected) && $affected >= 0 ? $affected : count($postIds);
                $commentsPurged += $childComments;
            }
        }

        // ---- comments deleted directly on a still-living post -----------------
        // (bounded remainder of the batch, so one pass stays capped).
        $remaining = $limit - count($postIds);
        if ($remaining > 0) {
            $cq = $this->db->table('community_comments')
                ->select('id')
                ->where('status', 'deleted')
                ->where('deleted_at IS NOT NULL', null, false)
                ->where('deleted_at <=', $cutoff)
                ->orderBy('deleted_at', 'ASC');
            $commentIds = array_map(static fn ($r) => (string) $r['id'], $cq->get($remaining)->getResultArray());

            if ($commentIds !== []) {
                $this->db->table('community_comments')
                    ->whereIn('id', $commentIds)
                    ->where('status', 'deleted')
                    ->where('deleted_at <=', $cutoff)
                    ->delete();
                $affected = $this->db->affectedRows();
                $commentsPurged += is_int($affected) && $affected >= 0 ? $affected : count($commentIds);
            }
        }

        return [
            'scanned'  => count($postIds),
            'posts'    => $postsPurged,
            'comments' => $commentsPurged,
        ];
    }

    private function applyStateChange(string $subjectType, string $subjectId, string $action, string $now): void
    {
        $table = match ($subjectType) {
            'post'    => 'community_posts',
            'comment' => 'community_comments',
            default   => null,
        };
        if ($table === null) {
            return; // user-level mute/ban handled by AccessControl elsewhere.
        }

        $update = match ($action) {
            'hide'    => ['status' => 'hidden'],
            // CM4: stamp deleted_at so the retention purge measures the grace
            // window from the moment content entered the terminal deleted state.
            'delete'  => ['status' => 'deleted', 'deleted_at' => $now],
            // Restore clears the retention anchor so a restored row is never purged.
            'restore' => ['status' => 'active', 'deleted_at' => null],
            'lock'    => $table === 'community_posts' ? ['locked' => 1] : [],
            'unlock'  => $table === 'community_posts' ? ['locked' => 0] : [],
            default   => [],
        };
        if ($update === []) {
            return;
        }
        $update['updated_at'] = $now;
        $this->db->table($table)->where('id', $subjectId)->update($update);
    }
}
