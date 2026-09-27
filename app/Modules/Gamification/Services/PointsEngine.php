<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Gamification\Support\FormulaEvaluator;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Immutable point ledger engine (SRS FR-GAM-002/003/004).
 *
 *  - Awards are evaluated from verifiable domain events against DATA-CONFIGURED,
 *    versioned rules — never arbitrary SQL or user code.
 *  - Duplicate awards are impossible: UNIQUE(rule_id, subject_id, source_ref,
 *    entry_type). A replayed event is a no-op.
 *  - Anti-gaming: per-period caps and rules flagged requires_review land as a
 *    HELD entry plus a fraud_review, awarding no final/spendable points.
 *  - Reversals (e.g. a refunded contribution) post a compensating entry; the
 *    original award is never edited.
 */
final class PointsEngine
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly SeasonService $seasons,
        private readonly ?AchievementService $achievements = null,
        private readonly ?CampaignService $campaigns = null,
        private readonly ?RollupService $rollup = null,
    ) {
    }

    /**
     * Award points for a domain event under a rule code.
     *
     * @param array<string,mixed> $opts subject_type, org_timezone, review, data
     *                                  (declarative multiplier inputs)
     */
    public function award(
        string $organizationId,
        string $ruleCode,
        string $subjectId,
        string $sourceRef,
        array $opts = [],
    ): Result {
        $rule = $this->activeRule($organizationId, $ruleCode);
        if ($rule === null) {
            return Result::fail('NO_ACTIVE_RULE', 'gamification.no_rule', 404, ['rule' => $ruleCode]);
        }

        $season = $this->seasons->ensureCurrentSeason($organizationId, $opts['org_timezone'] ?? 'UTC');
        $subjectType = $opts['subject_type'] ?? 'user';

        // Anti-gaming: per-period cap on FINAL awards for this rule+subject.
        // `suppress_limits` is set by awardMultiGroup() on the 2nd..Nth group
        // entry of ONE logical check-in so a single action isn't counted N times
        // against the caps (limits are enforced once, on the primary entry).
        $suppressLimits = ! empty($opts['suppress_limits']);
        if (! $suppressLimits && ! empty($rule['per_period_cap']) && $this->periodCount($organizationId, $rule, $subjectId, (string) $season['id']) >= (int) $rule['per_period_cap']) {
            return Result::fail('CAP_REACHED', 'gamification.cap_reached', 409, ['cap' => (int) $rule['per_period_cap']]);
        }

        // Anti-gaming: cooldown between awards of this rule for this subject.
        if (! $suppressLimits && ! empty($rule['cooldown_seconds']) && $this->inCooldown($organizationId, (string) $rule['id'], $subjectId, (int) $rule['cooldown_seconds'])) {
            return Result::fail('COOLDOWN', 'gamification.cooldown', 429, ['cooldown_seconds' => (int) $rule['cooldown_seconds']]);
        }

        // Anti-gaming: independent rolling daily / weekly / monthly limits
        // (configurable per activity, on top of the season cap above).
        if (! $suppressLimits) {
            foreach (['day' => 'daily_limit', 'week' => 'weekly_limit', 'month' => 'monthly_limit'] as $window => $col) {
                $limit = isset($rule[$col]) && $rule[$col] !== null && $rule[$col] !== '' ? (int) $rule[$col] : null;
                if ($limit !== null && $limit > 0
                    && $this->windowCount($organizationId, (string) $rule['id'], $subjectId, $window) >= $limit) {
                    return Result::fail('LIMIT_REACHED', 'gamification.limit_reached', 409, [
                        'window' => $window,
                        'limit'  => $limit,
                    ]);
                }
            }
        }

        // Base points by point_mode (fixed | variable | formula), then the
        // declarative multipliers (safe, no eval). min/max clamp the base.
        $data       = is_array($opts['data'] ?? null) ? $opts['data'] : [];
        $basePoints = $this->computeBasePoints($rule, $data);
        $mult       = $this->applyMultipliers($rule, $data);
        $points     = (int) round($basePoints * $mult['total']);

        $held  = ! empty($rule['requires_review']) || ! empty($opts['review']);
        $state = $held ? 'held' : 'final';
        $assignedRole = $held && ! empty($rule['approval_role_code']) ? (string) $rule['approval_role_code'] : null;
        $id    = Uuid::v7();
        $now   = $this->clock->nowUtcMicro();

        // Group attribution + cross-cutting dimensions (design doc Part B.3).
        // group_id prefers the receiving group, else the member's own group
        // (membership fallback), else NULL (pure org-level). category/project/
        // phase tag the entry for cross-cutting boards; phase defaults to the
        // rule's own phase. amount_minor carries raw volume for the "volume"
        // ranking measure so the rollup stays a pure function of the ledger.
        $data        = is_array($opts['data'] ?? null) ? $opts['data'] : [];
        $groupId     = $this->rollup !== null
            ? $this->rollup->resolveGroupId($organizationId, $subjectId, $opts + ['subject_type' => $subjectType])
            : ($opts['receiving_group_id'] ?? $opts['group_id'] ?? null);
        $groupId      = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $categoryCode = isset($opts['category_code']) && $opts['category_code'] !== '' ? (string) $opts['category_code'] : null;
        $projectCode  = isset($opts['project_code']) && $opts['project_code'] !== '' ? (string) $opts['project_code'] : null;
        $phase        = isset($opts['phase']) && $opts['phase'] !== '' ? (string) $opts['phase']
            : (isset($rule['phase']) && $rule['phase'] !== '' && $rule['phase'] !== 'general' ? (string) $rule['phase'] : null);
        $amountMinor  = (int) ($opts['amount_minor'] ?? $data['amount_minor'] ?? $data['volume'] ?? 0);

        $this->db->transStart();
        try {
            $this->db->table('point_ledger')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'season_id'       => $season['id'],
                'subject_id'      => $subjectId,
                'subject_type'    => $subjectType,
                'group_id'        => $groupId,
                'category_code'   => $categoryCode,
                'project_code'    => $projectCode,
                'phase'           => $phase,
                'rule_id'         => $rule['id'],
                'rule_version'    => $rule['version'],
                'entry_type'      => 'award',
                'points'          => $points,
                'amount_minor'    => $amountMinor,
                'source_ref'      => $sourceRef,
                'state'           => $state,
                'explanation'     => $rule['explanation'] ?? null,
                'created_at'      => $now,
            ]);
        } catch (Throwable) {
            $this->db->transComplete();

            // UNIQUE violation -> already awarded for this event.
            return Result::ok(['status' => 'duplicate', 'rule' => $ruleCode], 200, ['deduplicated' => true]);
        }

        if ($held) {
            $this->db->table('fraud_reviews')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'subject_id'      => $subjectId,
                'source_ref'      => $sourceRef,
                'reason'          => ! empty($opts['review']) ? (string) $opts['review'] : 'rule_requires_review',
                'assigned_role'   => $assignedRole,
                'ledger_id'       => $id,
                'status'          => 'open',
                'created_at'      => $this->clock->nowUtcString(),
            ]);
        }

        // Roll up the credited group and all its ancestors — but ONLY for
        // spendable (final) entries. Held entries roll up later, on approval /
        // clearHeld, so pending points never inflate a ranking.
        if (! $held && $this->rollup !== null && $groupId !== null) {
            $this->rollup->applyDelta(
                $organizationId,
                (string) $season['id'],
                $groupId,
                $categoryCode,
                $projectCode,
                $phase,
                $points,
                $amountMinor,
                1,
            );
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('AWARD_FAILED', 'gamification.award_failed', 500);
        }

        // Evaluate achievements ONLY for spendable (final) awards. Bonus-point
        // achievements re-enter award() with a distinct rule/source_ref, so this
        // never recurses unboundedly (each achievement unlocks once via UNIQUE).
        $unlocked = [];
        if (! $held && $this->achievements !== null && empty($opts['skip_achievements'])) {
            $eval = $this->achievements->evaluateForSubject($organizationId, $subjectId, $sourceRef);
            $unlocked = is_array($eval->data) ? ($eval->data['unlocked'] ?? []) : [];
        }

        // HOOK: feed this verified activity into any group campaigns/"projects"
        // the subject is eligible for. Only for spendable (final) awards, and
        // never for the campaign roll-up rule itself (prevents feedback loops).
        // Best-effort: feedFromActivity() swallows its own errors.
        $fedCampaigns = [];
        if (! $held && $this->campaigns !== null && empty($opts['skip_campaigns'])) {
            $data    = is_array($opts['data'] ?? null) ? $opts['data'] : [];
            $metrics = [
                'count'  => 1,
                'points' => $points,
                'amount' => (int) ($data['amount_minor'] ?? 0),
                'volume' => (int) ($data['volume'] ?? 0),
            ];
            $feed = $this->campaigns->feedFromActivity(
                $organizationId,
                $subjectId,
                $ruleCode,
                $metrics,
                $sourceRef,
                ['subject_type' => $subjectType],
            );
            $fedCampaigns = $feed['campaigns'] ?? [];
        }

        $meta = [];
        if ($unlocked !== []) {
            $meta['achievements_unlocked'] = $unlocked;
        }
        if ($fedCampaigns !== []) {
            $meta['campaigns_fed'] = $fedCampaigns;
        }

        return Result::created(
            ['ledger_id' => $id, 'points' => $points, 'state' => $state, 'multipliers' => $mult['applied']],
            $meta,
        );
    }

    /**
     * Award ONE logical activity that credits SEVERAL groups — the multi-group
     * check-in case (design doc B.4.1). A member may attend/participate in ANY
     * allowed group's event, so the credited groups are the event's own group
     * plus every authorized `event_attendance_group_attribution` row; they need
     * NOT be groups the member belongs to. The rule's `group_credit_mode` decides
     * how the credit is shaped:
     *
     *   - `per_group` (default) — post ONE ledger entry per DISTINCT credited
     *     group, each idempotent on (rule, subject, source_ref, entry_type,
     *     group_id) so the individual's org-wide total counts the activity once
     *     per credited group and each group's standing is independent. Anti-gaming
     *     caps/cooldowns are enforced ONCE (on the first entry); the rest carry
     *     `suppress_limits` so a single action isn't rejected as a "repeat".
     *
     *   - `individual_once` — post ONE individual ledger entry against the primary
     *     (first) group; the OTHER credited groups get rollup-only deltas so their
     *     standings still rise without multiplying the individual's total.
     *
     * All N per-group entries share the SAME `source_ref`; the idempotency UNIQUE
     * `(rule_id, subject_id, source_ref, entry_type, group_id)` already includes
     * `group_id`, so distinct groups write distinct rows while a redelivery of the
     * same check-in de-dupes per group. Because the source_ref is unchanged, a
     * plain {@see reverse()} on that ref compensates ALL credited groups at once.
     *
     * @param list<string>        $groupIds credited group ids (event group + attributions)
     * @param array<string,mixed> $opts     as award(); `subject_type`, `phase`, etc.
     */
    public function awardMultiGroup(
        string $organizationId,
        string $ruleCode,
        string $subjectId,
        string $sourceRef,
        array $groupIds,
        array $opts = [],
    ): Result {
        // De-dupe + drop empties while preserving order (first = primary group).
        $groups = [];
        foreach ($groupIds as $g) {
            $g = (string) $g;
            if ($g !== '' && ! in_array($g, $groups, true)) {
                $groups[] = $g;
            }
        }

        // No credited group -> fall back to the ordinary single award, which
        // resolves the membership fallback / org-level itself.
        if ($groups === []) {
            return $this->award($organizationId, $ruleCode, $subjectId, $sourceRef, $opts);
        }

        $rule = $this->activeRule($organizationId, $ruleCode);
        if ($rule === null) {
            return Result::fail('NO_ACTIVE_RULE', 'gamification.no_rule', 404, ['rule' => $ruleCode]);
        }
        $mode = ($rule['group_credit_mode'] ?? 'per_group') === 'individual_once'
            ? 'individual_once'
            : 'per_group';

        // ---- individual_once: one ledger entry (primary group) + rollup-only
        //      deltas for the remaining groups so their standings still rise. ---
        if ($mode === 'individual_once') {
            $primary = $groups[0];
            $res     = $this->award(
                $organizationId,
                $ruleCode,
                $subjectId,
                $sourceRef,
                ['receiving_group_id' => $primary] + $opts,
            );
            if (! $res->ok) {
                return $res;
            }

            // Rollup-only for the other groups (best-effort; requires the rollup
            // service + a resolved season). Uses the SAME awarded points so each
            // secondary group's standing reflects the activity once.
            $points = is_array($res->data) ? (int) ($res->data['points'] ?? 0) : 0;
            $extra  = array_slice($groups, 1);
            if ($this->rollup !== null && $extra !== [] && ($res->data['state'] ?? null) === 'final') {
                $season = $this->seasons->ensureCurrentSeason($organizationId, $opts['org_timezone'] ?? 'UTC');
                $data   = is_array($opts['data'] ?? null) ? $opts['data'] : [];
                $phase  = isset($opts['phase']) && $opts['phase'] !== '' ? (string) $opts['phase']
                    : (isset($rule['phase']) && $rule['phase'] !== '' && $rule['phase'] !== 'general' ? (string) $rule['phase'] : null);
                $amount = (int) ($opts['amount_minor'] ?? $data['amount_minor'] ?? $data['volume'] ?? 0);
                foreach ($extra as $g) {
                    $this->rollup->applyDelta(
                        $organizationId,
                        (string) $season['id'],
                        $g,
                        $opts['category_code'] ?? null,
                        $opts['project_code'] ?? null,
                        $phase,
                        $points,
                        $amount,
                        1,
                    );
                }
            }

            return Result::created([
                'mode'          => 'individual_once',
                'primary_group' => $primary,
                'rollup_groups' => $extra,
                'ledger_id'     => is_array($res->data) ? ($res->data['ledger_id'] ?? null) : null,
                'points'        => $points,
                'state'         => is_array($res->data) ? ($res->data['state'] ?? null) : null,
            ], is_array($res->meta ?? null) ? $res->meta : []);
        }

        // ---- per_group: one full ledger entry per credited group. -----------
        $entries = [];
        $skipped = [];
        $first   = true;
        foreach ($groups as $g) {
            $perOpts = [
                'receiving_group_id' => $g,
                // Enforce caps/cooldown once (primary); suppress for the rest so a
                // single action isn't rejected as a repeat of itself.
                'suppress_limits'    => ! $first,
            ] + $opts;

            // SAME source_ref for every group — the idempotency UNIQUE includes
            // group_id, so each group gets its own row and reverse() undoes all.
            $res = $this->award(
                $organizationId,
                $ruleCode,
                $subjectId,
                $sourceRef,
                $perOpts,
            );
            if ($res->ok) {
                $entries[] = [
                    'group_id'  => $g,
                    'ledger_id' => is_array($res->data) ? ($res->data['ledger_id'] ?? null) : null,
                    'points'    => is_array($res->data) ? (int) ($res->data['points'] ?? 0) : 0,
                    'state'     => is_array($res->data) ? ($res->data['state'] ?? null) : null,
                    'status'    => is_array($res->data) ? ($res->data['status'] ?? 'awarded') : 'awarded',
                ];
            } else {
                // A cap hit on the PRIMARY aborts the whole action; otherwise
                // record the per-group skip and keep crediting the rest.
                if ($first) {
                    return $res;
                }
                $skipped[] = ['group_id' => $g, 'code' => $res->code];
            }
            $first = false;
        }

        return Result::created([
            'mode'          => 'per_group',
            'credited'      => $entries,
            'skipped'       => $skipped,
            'groups_count'  => count($entries),
        ]);
    }

    /**
     * Reverse points tied to a source ref (e.g. refunded contribution). Posts a
     * compensating negative entry per original award; never edits the original.
     */
    public function reverse(string $organizationId, string $sourceRef, string $reason = 'reversal'): Result
    {
        $awards = $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)
            ->where('source_ref', $sourceRef)
            ->where('entry_type', 'award')
            ->whereIn('state', ['final', 'held'])
            ->get()->getResultArray();

        if ($awards === []) {
            return Result::ok(['status' => 'nothing_to_reverse'], 200);
        }

        $now      = $this->clock->nowUtcMicro();
        $reversed = 0;
        $this->db->transStart();
        foreach ($awards as $a) {
            try {
                $this->db->table('point_ledger')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $organizationId,
                    'season_id'       => $a['season_id'],
                    'subject_id'      => $a['subject_id'],
                    'subject_type'    => $a['subject_type'],
                    'group_id'        => $a['group_id'] ?? null,
                    'category_code'   => $a['category_code'] ?? null,
                    'project_code'    => $a['project_code'] ?? null,
                    'phase'           => $a['phase'] ?? null,
                    'rule_id'         => $a['rule_id'],
                    'rule_version'    => $a['rule_version'],
                    'entry_type'      => 'reversal',
                    'points'          => -1 * (int) $a['points'],
                    'amount_minor'    => -1 * (int) ($a['amount_minor'] ?? 0),
                    'source_ref'      => $sourceRef,
                    'state'           => 'final',
                    'explanation'     => $reason,
                    'created_at'      => $now,
                ]);
                $this->db->table('point_ledger')->where('id', $a['id'])->update(['state' => 'reversed']);

                // Compensating roll-up delta — but only for entries that were
                // FINAL (i.e. already counted). A 'held' entry never rolled up,
                // so reversing it must not double-subtract.
                if ($this->rollup !== null && ($a['state'] ?? null) === 'final' && ! empty($a['group_id'])) {
                    $this->rollup->applyDelta(
                        $organizationId,
                        (string) $a['season_id'],
                        (string) $a['group_id'],
                        $a['category_code'] ?? null,
                        $a['project_code'] ?? null,
                        $a['phase'] ?? null,
                        -1 * (int) $a['points'],
                        -1 * (int) ($a['amount_minor'] ?? 0),
                        -1,
                    );
                }
                $reversed++;
            } catch (Throwable) {
                // reversal already posted -> skip
            }
        }
        $this->db->transComplete();

        return Result::ok(['reversed_entries' => $reversed]);
    }

    /** Current final balance for a subject in the active (or given) season. */
    public function balance(string $organizationId, string $subjectId, ?string $seasonId = null): int
    {
        if ($seasonId === null) {
            $season   = $this->seasons->activeSeason($organizationId);
            $seasonId = $season['id'] ?? null;
        }
        if ($seasonId === null) {
            return 0;
        }
        $row = $this->db->query(
            'SELECT COALESCE(SUM(points),0) AS total FROM point_ledger
             WHERE organization_id = ? AND subject_id = ? AND season_id = ? AND state = "final"',
            [$organizationId, $subjectId, $seasonId],
        )->getRowArray();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Clear a held entry to final after fraud review passes. Behaviourally
     * identical downstream of "points are now spendable" to `approveAward`
     * (gap G3): both doors run the SAME finalize side effects — rollup AND
     * achievement evaluation — and both record the resolving actor on the review
     * row, so releasing an award via the fraud-clear path can never deny an
     * achievement the approval path would have unlocked.
     */
    public function clearHeld(string $ledgerId, ?string $actorId = null): Result
    {
        $entry = $this->db->table('point_ledger')->where('id', $ledgerId)->get()->getRowArray();
        $wasHeld = $entry !== null && ($entry['state'] ?? null) === 'held';

        $this->db->table('point_ledger')->where('id', $ledgerId)->where('state', 'held')->update(['state' => 'final']);
        $this->db->table('fraud_reviews')->where('ledger_id', $ledgerId)->update([
            'status'      => 'cleared',
            'resolved_at' => $this->clock->nowUtcString(),
            'resolved_by' => $actorId,
        ]);

        // Unify the "entry became final" side effects across both doors.
        if ($wasHeld) {
            $this->finalizeEntry($entry);
        }

        return Result::ok(['ledger_id' => $ledgerId, 'state' => 'final', 'resolved_by' => $actorId]);
    }

    /**
     * Side effects of an entry becoming FINAL (held → final via clear OR approve,
     * gap G3). Kept in ONE place so the fraud-clear and approval doors are
     * behaviourally identical for everything downstream of "points are now
     * spendable": (1) roll up the credited group + ancestors, then (2) evaluate
     * achievements for the subject now that the points count.
     *
     * @param array<string,mixed>|null $entry
     */
    private function finalizeEntry(?array $entry): void
    {
        if ($entry === null) {
            return;
        }
        $this->rollUpEntry($entry);
        if ($this->achievements !== null) {
            $this->achievements->evaluateForSubject(
                (string) $entry['organization_id'],
                (string) $entry['subject_id'],
                (string) $entry['source_ref'],
            );
        }
    }

    /**
     * Roll up a ledger entry that has just become FINAL (held → final via clear
     * or approve). No-op when there is no rollup service or no credited group.
     *
     * @param array<string,mixed>|null $entry
     */
    private function rollUpEntry(?array $entry): void
    {
        if ($entry === null || $this->rollup === null || empty($entry['group_id'])) {
            return;
        }
        $this->rollup->applyDelta(
            (string) $entry['organization_id'],
            (string) $entry['season_id'],
            (string) $entry['group_id'],
            $entry['category_code'] ?? null,
            $entry['project_code'] ?? null,
            $entry['phase'] ?? null,
            (int) $entry['points'],
            (int) ($entry['amount_minor'] ?? 0),
            1,
        );
    }

    /**
     * Approval workflow (distinct from fraud clearing): an approver promotes a
     * held entry to final. Records the approver on the review row. Idempotent —
     * a non-held entry is reported as already resolved.
     */
    public function approveAward(string $ledgerId, string $approverId, ?string $notes = null): Result
    {
        $entry = $this->db->table('point_ledger')->where('id', $ledgerId)->get()->getRowArray();
        if ($entry === null) {
            return Result::notFound('gamification.ledger_not_found', 'LEDGER_NOT_FOUND');
        }
        if ($entry['state'] !== 'held') {
            return Result::ok(['ledger_id' => $ledgerId, 'state' => $entry['state']], 200, ['already_resolved' => true]);
        }

        $this->db->table('point_ledger')->where('id', $ledgerId)->update(['state' => 'final']);
        $this->db->table('fraud_reviews')->where('ledger_id', $ledgerId)->update([
            'status'      => 'cleared',
            'reason'      => $notes !== null ? mb_substr('approved:' . $notes, 0, 120) : 'approved',
            'resolved_at' => $this->clock->nowUtcString(),
            'resolved_by' => $approverId,
        ]);

        // Same finalize side effects as the fraud-clear door (rollup + achievement
        // eval) so both "held → final" paths are behaviourally identical (G3).
        $this->finalizeEntry($entry);

        return Result::ok(['ledger_id' => $ledgerId, 'state' => 'final', 'approved_by' => $approverId]);
    }

    /**
     * Reject a held award: post a compensating reversal so the held points never
     * become spendable, and mark the review rejected. Never edits history.
     */
    public function rejectAward(string $ledgerId, string $approverId, string $reason): Result
    {
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'gamification.reason_required', 422);
        }
        $entry = $this->db->table('point_ledger')->where('id', $ledgerId)->get()->getRowArray();
        if ($entry === null) {
            return Result::notFound('gamification.ledger_not_found', 'LEDGER_NOT_FOUND');
        }
        if ($entry['state'] !== 'held') {
            return Result::ok(['ledger_id' => $ledgerId, 'state' => $entry['state']], 200, ['already_resolved' => true]);
        }

        $this->db->transStart();
        $this->db->table('point_ledger')->where('id', $ledgerId)->update(['state' => 'reversed']);
        $this->db->table('fraud_reviews')->where('ledger_id', $ledgerId)->update([
            'status'      => 'rejected',
            'reason'      => mb_substr('rejected:' . $reason, 0, 120),
            'resolved_at' => $this->clock->nowUtcString(),
            'resolved_by' => $approverId,
        ]);
        $this->db->transComplete();

        return Result::ok(['ledger_id' => $ledgerId, 'state' => 'reversed', 'rejected_by' => $approverId]);
    }

    /**
     * Pending (held) awards awaiting approval for an org.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingAwards(string $organizationId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)
            ->where('entry_type', 'award')
            ->where('state', 'held')
            ->orderBy('created_at', 'ASC')
            ->limit(max(1, min($limit, 200)), max(0, $offset))
            ->get()->getResultArray();
    }

    /** Fetch a single ledger entry by id (for scoped approve/reject checks). */
    public function findEntry(string $ledgerId): ?array
    {
        return $this->db->table('point_ledger')->where('id', $ledgerId)->get()->getRowArray() ?: null;
    }

    /**
     * The group ids an award SUBJECT belongs to, for approval-queue scoping:
     *   - a group/team subject IS its own group;
     *   - a user subject resolves to their active group memberships.
     * Returns an empty list when the user has no group (an org-wide-only award
     * that only an org-wide approver may act on).
     *
     * @return list<string>
     */
    public function subjectGroupIds(string $organizationId, string $subjectId, string $subjectType = 'user'): array
    {
        if ($subjectType === 'group' || $subjectType === 'team') {
            return [$subjectId];
        }

        $rows = $this->db->table('group_members')
            ->select('group_id')
            ->where('organization_id', $organizationId)
            ->where('user_id', $subjectId)
            ->where('status', 'active')
            ->get()->getResultArray();

        return array_values(array_unique(array_map(static fn ($r): string => (string) $r['group_id'], $rows)));
    }

    /** @return array<string,mixed>|null */
    private function activeRule(string $organizationId, string $ruleCode): ?array
    {
        $now = $this->clock->nowUtcString();

        return $this->db->table('gamification_rules')
            ->where('organization_id', $organizationId)
            ->where('code', $ruleCode)
            ->where('status', 'active')
            ->where('effective_from <=', $now)
            ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $now)->groupEnd()
            ->orderBy('version', 'DESC')
            ->get()->getRowArray() ?: null;
    }

    private function periodCount(string $organizationId, array $rule, string $subjectId, string $seasonId): int
    {
        $q = $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)
            ->where('rule_id', $rule['id'])
            ->where('subject_id', $subjectId)
            ->where('entry_type', 'award')
            ->whereIn('state', ['final', 'held']);

        $period = $rule['period'] ?? 'season';
        if ($period === 'season') {
            $q->where('season_id', $seasonId);
        } else {
            $since = match ($period) {
                'day'   => $this->clock->now()->modify('-1 day'),
                'week'  => $this->clock->now()->modify('-7 days'),
                'month' => $this->clock->now()->modify('-1 month'),
                default => $this->clock->now()->modify('-1 day'),
            };
            $q->where('created_at >=', $since->format('Y-m-d H:i:s'));
        }

        return $q->countAllResults();
    }

    /** True if the subject earned this rule within the cooldown window. */
    private function inCooldown(string $organizationId, string $ruleId, string $subjectId, int $cooldownSeconds): bool
    {
        $since = $this->clock->now()->modify("-{$cooldownSeconds} seconds")->format('Y-m-d H:i:s.u');

        return $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)
            ->where('rule_id', $ruleId)
            ->where('subject_id', $subjectId)
            ->where('entry_type', 'award')
            ->whereIn('state', ['final', 'held'])
            ->where('created_at >=', $since)
            ->countAllResults() > 0;
    }

    /**
     * Compute the pre-multiplier base points for an activity by point_mode:
     *
     *   fixed    → the rule's configured points (default, backward compatible).
     *   variable → a caller-supplied value (data.value / data.points), clamped;
     *              falls back to the rule points when absent.
     *   formula  → a SAFE arithmetic formula over {base_points + numeric event
     *              fields}; on any error it falls back to the rule points.
     *
     * The result is clamped to [min_points, max_points] when configured. Base
     * points can be negative (penalties) only when the rule's own points are.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $data
     */
    private function computeBasePoints(array $rule, array $data): int
    {
        $rulePoints = (int) $rule['points'];
        $mode       = (string) ($rule['point_mode'] ?? 'fixed');

        $base = match ($mode) {
            'variable' => $this->numericOr($data['value'] ?? ($data['points'] ?? null), $rulePoints),
            'formula'  => $this->evalFormula((string) ($rule['point_formula'] ?? ''), $rulePoints, $data),
            default    => (float) $rulePoints, // fixed
        };

        // Clamp to the configured band, when present.
        $min = isset($rule['min_points']) && $rule['min_points'] !== null && $rule['min_points'] !== '' ? (int) $rule['min_points'] : null;
        $max = isset($rule['max_points']) && $rule['max_points'] !== null && $rule['max_points'] !== '' ? (int) $rule['max_points'] : null;
        if ($min !== null) {
            $base = max($base, (float) $min);
        }
        if ($max !== null) {
            $base = min($base, (float) $max);
        }

        return (int) round($base);
    }

    private function numericOr(mixed $value, int $fallback): float
    {
        return is_numeric($value) ? (float) $value : (float) $fallback;
    }

    /**
     * Evaluate a point formula safely; fall back to the rule points on any
     * error. Variables = base_points plus every numeric field in $data.
     *
     * @param array<string,mixed> $data
     */
    private function evalFormula(string $formula, int $rulePoints, array $data): float
    {
        if (trim($formula) === '') {
            return (float) $rulePoints;
        }
        $vars = ['base_points' => $rulePoints];
        foreach ($data as $k => $v) {
            if (is_numeric($v) && is_string($k)) {
                $vars[strtolower($k)] = (float) $v;
            }
        }
        $result = (new FormulaEvaluator())->evaluate($formula, $vars);

        return $result ?? (float) $rulePoints;
    }

    /**
     * Count this subject's award+held ledger entries for a rule within a rolling
     * window ending now (day = last 24h, week = last 7d, month = last 1 month).
     */
    private function windowCount(string $organizationId, string $ruleId, string $subjectId, string $window): int
    {
        $since = match ($window) {
            'week'  => $this->clock->now()->modify('-7 days'),
            'month' => $this->clock->now()->modify('-1 month'),
            default => $this->clock->now()->modify('-1 day'),
        };

        return $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)
            ->where('rule_id', $ruleId)
            ->where('subject_id', $subjectId)
            ->where('entry_type', 'award')
            ->whereIn('state', ['final', 'held'])
            ->where('created_at >=', $since->format('Y-m-d H:i:s'))
            ->countAllResults();
    }

    /**
     * Compute the total multiplier from a rule's DECLARATIVE multipliers spec.
     * NO eval(): each entry is {factor: float, when: {field, op, value}} and the
     * condition is checked against the supplied event data with a fixed operator
     * set. Missing/!matching conditions simply don't apply.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $data
     * @return array{total:float, applied:list<array<string,mixed>>}
     */
    private function applyMultipliers(array $rule, array $data): array
    {
        $spec = $rule['multipliers'] ?? null;
        if (is_string($spec)) {
            $spec = json_decode($spec, true);
        }
        if (! is_array($spec) || $spec === []) {
            return ['total' => 1.0, 'applied' => []];
        }

        $total   = 1.0;
        $applied = [];
        foreach ($spec as $m) {
            if (! is_array($m) || ! isset($m['factor']) || ! is_numeric($m['factor'])) {
                continue;
            }
            $factor = (float) $m['factor'];
            if ($factor <= 0) {
                continue; // never zero-out or negate points via a multiplier
            }

            $when = $m['when'] ?? null;
            if (is_array($when) && isset($when['field'])) {
                $actual   = $data[$when['field']] ?? null;
                $expected = $when['value'] ?? null;
                if (! $this->conditionMet($actual, (string) ($when['op'] ?? '=='), $expected)) {
                    continue;
                }
            }

            $total    *= $factor;
            $applied[] = ['factor' => $factor, 'when' => $when['field'] ?? null];
        }

        // Clamp to a sane band so a misconfiguration can't mint runaway points.
        $total = max(0.1, min($total, 10.0));

        return ['total' => $total, 'applied' => $applied];
    }

    private function conditionMet(mixed $actual, string $op, mixed $expected): bool
    {
        return match ($op) {
            '==', '=' => $actual == $expected,
            '!='      => $actual != $expected,
            '>'       => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            '>='      => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            '<'       => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            '<='      => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'in'      => is_array($expected) && in_array($actual, $expected, true),
            default   => false,
        };
    }
}
