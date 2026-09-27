<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Duolingo-style achievements engine (adaptation of awardlib.md achievements).
 *
 * Adapted to WBS conventions and invariants:
 *  - Progress for every trigger is computed from the IMMUTABLE point_ledger and
 *    the streak/rank services — never from a mutable user_awards table.
 *  - SPENDABLE bonus points are posted to the ledger via a configured bonus rule
 *    (source_ref 'achievement:{code}'), so they remain single-source-of-truth
 *    and idempotent. XP is a non-spendable vanity stat stored on the unlock row.
 *  - Unlocks are idempotent via UNIQUE(achievement_id, subject_id).
 *  - There is NO eval() anywhere; triggers are a fixed, safe set.
 */
final class AchievementService
{
    private const TRIGGERS = [
        'points', 'count', 'streak', 'combo', 'first_time',
        'cumulative_points', 'rank_reached', 'custom',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly PointsEngine $points,
        private readonly SeasonService $seasons,
        private readonly StreakService $streaks,
        private readonly RankService $ranks,
        private readonly ?GroupScopeResolver $groupScope = null,
    ) {
    }

    /**
     * Read one achievement definition by code. Within a group scope, resolution
     * is most-specific-wins over the ancestor chain (self → ancestor-with-
     * descendants → org-wide); without a group it reads the org-wide row.
     */
    public function show(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        if ($gid !== null && $this->groupScope !== null) {
            $row = $this->groupScope->resolveByCode('achievement_definitions', $organizationId, $code, $gid, []);
        } else {
            $q = $this->db->table('achievement_definitions')
                ->where('organization_id', $organizationId)->where('code', $code);
            $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
            $row = $q->get()->getRowArray();
        }

        if ($row === null) {
            return Result::notFound('gamification.achievement_not_found', 'ACHIEVEMENT_NOT_FOUND');
        }
        $row['secret']              = (bool) ($row['secret'] ?? 0);
        $row['include_descendants'] = (bool) ($row['include_descendants'] ?? 0);
        if (isset($row['trigger_config']) && is_string($row['trigger_config']) && $row['trigger_config'] !== '') {
            $row['trigger_config'] = json_decode($row['trigger_config'], true) ?? $row['trigger_config'];
        }

        return Result::ok($row);
    }

    /**
     * Evaluate all active achievements for a subject after an award/event.
     * Unlocks those newly met; refreshes stored progress for the rest.
     *
     * @return Result data: unlocked (list of codes), evaluated (int)
     */
    public function evaluateForSubject(string $organizationId, string $subjectId, string $triggerSourceRef = '', array $opts = []): Result
    {
        // Org-wide definitions only for automatic evaluation. Group-scoped
        // definitions (group_id NOT NULL) are managed/evaluated via the group
        // path; the automatic group-evaluation hook is a documented follow-up.
        $defs = $this->db->table('achievement_definitions')
            ->where('organization_id', $organizationId)->where('status', 'active')
            ->where('group_id', null)
            ->get()->getResultArray();
        if ($defs === []) {
            return Result::ok(['unlocked' => [], 'evaluated' => 0]);
        }

        $already = $this->unlockedCodes($organizationId, $subjectId);
        $unlocked = [];

        foreach ($defs as $def) {
            if (in_array($def['code'], $already, true)) {
                continue;
            }
            if ($def['trigger_type'] === 'custom') {
                continue; // custom achievements unlock only via unlockManually()
            }

            $progress = $this->computeProgress($organizationId, $subjectId, $def);
            if ($progress['met']) {
                $res = $this->unlock($organizationId, $subjectId, $def, $triggerSourceRef, $progress);
                if ($res->ok) {
                    $unlocked[] = $def['code'];
                }
            } else {
                $this->storeProgress($organizationId, $subjectId, $def, $progress);
            }
        }

        return Result::ok(['unlocked' => $unlocked, 'evaluated' => count($defs)]);
    }

    /**
     * Compute progress toward one achievement definition.
     *
     * @return array{met:bool,current:float,required:float,percentage:float}
     */
    public function computeProgress(string $organizationId, string $subjectId, array $def): array
    {
        $cfg      = $this->decodeConfig($def['trigger_config'] ?? null);
        $required = (float) ($cfg['threshold'] ?? $cfg['count'] ?? 1);
        $current  = 0.0;
        $met      = false;

        switch ($def['trigger_type']) {
            case 'points':
                $current = (float) $this->points->balance($organizationId, $subjectId);
                $met     = $current >= $required;
                break;

            case 'count':
                $current = (float) $this->awardCount($organizationId, $subjectId, $cfg['activity_code'] ?? null);
                $met     = $current >= $required;
                break;

            case 'first_time':
                $current  = (float) $this->awardCount($organizationId, $subjectId, $cfg['activity_code'] ?? null);
                $required = 1.0;
                $met      = $current >= 1.0;
                break;

            case 'cumulative_points':
                $current = (float) $this->awardPointsSum($organizationId, $subjectId, $cfg['activity_code'] ?? null);
                $met     = $current >= $required;
                break;

            case 'streak':
                $streak  = $this->streaks->get($organizationId, $subjectId, (string) ($cfg['streak_code'] ?? 'daily_login'));
                $current = (float) $streak['current_count'];
                $met     = $current >= $required;
                break;

            case 'combo':
                $current = (float) $this->distinctRulesToday($organizationId, $subjectId);
                $met     = $current >= $required;
                break;

            case 'rank_reached':
                $target  = (string) ($cfg['rank_code'] ?? '');
                $rank    = $this->ranks->determineRank($organizationId, $this->points->balance($organizationId, $subjectId));
                $met     = $target !== '' && $rank !== null && (string) $rank['code'] === $target;
                $current = $met ? 1.0 : 0.0;
                $required = 1.0;
                break;
        }

        $pct = $required > 0 ? min(100.0, round($current / $required * 100, 2)) : 0.0;

        return ['met' => $met, 'current' => $current, 'required' => $required, 'percentage' => $pct];
    }

    /** Manually unlock a custom (or any) achievement. */
    public function unlockManually(string $organizationId, string $subjectId, string $achievementCode, string $grantedBy, ?string $notes = null): Result
    {
        $def = $this->db->table('achievement_definitions')
            ->where('organization_id', $organizationId)->where('code', $achievementCode)
            ->get()->getRowArray();
        if ($def === null) {
            return Result::notFound('gamification.achievement_not_found', 'ACHIEVEMENT_NOT_FOUND');
        }

        return $this->unlock($organizationId, $subjectId, $def, 'manual:' . $grantedBy, [
            'met' => true, 'current' => 1.0, 'required' => 1.0, 'percentage' => 100.0,
        ], $grantedBy, $notes);
    }

    /** Retroactively re-evaluate every achievement for a subject. */
    public function reevaluateAll(string $organizationId, string $subjectId): Result
    {
        return $this->evaluateForSubject($organizationId, $subjectId, 'reevaluate');
    }

    /** @return list<array<string,mixed>> Unlocked achievements for a subject. */
    public function getUserAchievements(string $organizationId, string $subjectId, bool $includeProgress = false): array
    {
        $rows = $this->db->table('user_achievements')
            ->where('organization_id', $organizationId)->where('subject_id', $subjectId)
            ->orderBy('unlocked_at', 'DESC')->get()->getResultArray();

        if (! $includeProgress) {
            return $rows;
        }
        $prog = $this->db->table('user_achievement_progress')
            ->where('organization_id', $organizationId)->where('subject_id', $subjectId)
            ->get()->getResultArray();

        return ['unlocked' => $rows, 'progress' => $prog];
    }

    /** @return list<array<string,mixed>> All definitions (secret hidden unless asked). */
    /**
     * The achievement TRIGGER vocabulary, for populating the admin catalog's
     * trigger-type picker. Read-only view over the private const.
     *
     * @return list<string>
     */
    public function triggerTypes(): array
    {
        return self::TRIGGERS;
    }

    public function listAll(string $organizationId, bool $includeSecret = false): array
    {
        $q = $this->db->table('achievement_definitions')
            ->where('organization_id', $organizationId)->where('status', 'active')
            ->where('group_id', null);
        if (! $includeSecret) {
            $q->where('secret', 0);
        }

        return $q->orderBy('sort_order', 'ASC')->get()->getResultArray();
    }

    /**
     * All org-wide achievement definitions for the ADMIN catalog — including
     * SECRET and DISABLED (inactive) rows, since an admin manages them all.
     * Ordered for a scannable catalog (sort_order, then name). Group-scoped
     * overrides are managed on their own group pages, so this stays org-wide.
     *
     * @return list<array<string,mixed>>
     */
    public function listForAdmin(string $organizationId): array
    {
        return $this->db->table('achievement_definitions')
            ->where('organization_id', $organizationId)
            ->where('group_id', null)
            ->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')
            ->get()->getResultArray();
    }

    // -------------------------------------------------------------------------
    // Admin management (create/update/disable definitions)
    // -------------------------------------------------------------------------

    /**
     * Create or update an achievement definition (admin). Upsert by code.
     *
     * @param array<string,mixed> $data code, name, trigger_type, trigger_config,
     *          xp, bonus_points, bonus_rule_code, category, icon, color, secret,
     *          sort_order, description
     */
    public function define(string $organizationId, array $data): Result
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('BAD_ACHIEVEMENT', 'gamification.achievement_code_name_required', 422);
        }
        $trigger = (string) ($data['trigger_type'] ?? '');
        if (! in_array($trigger, self::TRIGGERS, true)) {
            return Result::fail('BAD_TRIGGER', 'gamification.bad_trigger', 422, ['allowed' => self::TRIGGERS]);
        }

        $config = $data['trigger_config'] ?? null;
        if (is_array($config)) {
            $config = json_encode($config);
        }
        // Non-custom triggers need a threshold/count (or specific ref) to be meaningful.
        if ($trigger !== 'custom') {
            $cfg = $this->decodeConfig($config);
            if (! isset($cfg['threshold']) && ! isset($cfg['count'])
                && ! in_array($trigger, ['first_time', 'rank_reached'], true)) {
                return Result::fail('BAD_TRIGGER_CONFIG', 'gamification.trigger_needs_threshold', 422);
            }
        }
        // Bonus points require a bonus rule so they can post to the ledger.
        if ((int) ($data['bonus_points'] ?? 0) > 0 && trim((string) ($data['bonus_rule_code'] ?? '')) === '') {
            return Result::fail('BONUS_RULE_REQUIRED', 'gamification.bonus_rule_required', 422);
        }

        $payload = [
            'name'            => mb_substr($name, 0, 150),
            'description'     => isset($data['description']) ? mb_substr((string) $data['description'], 0, 255) : null,
            'category'        => $data['category'] ?? null,
            'phase'           => in_array($data['phase'] ?? 'general', ['win', 'build', 'send', 'general'], true) ? (string) ($data['phase'] ?? 'general') : 'general',
            'icon'            => $data['icon'] ?? null,
            'color'           => $data['color'] ?? null,
            'trigger_type'    => $trigger,
            'trigger_config'  => $config,
            'xp'              => (int) ($data['xp'] ?? 0),
            'bonus_points'    => (int) ($data['bonus_points'] ?? 0),
            'bonus_rule_code' => $data['bonus_rule_code'] ?? null,
            'secret'          => ! empty($data['secret']) ? 1 : 0,
            'status'          => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? $data['status'] : 'active',
            'sort_order'      => (int) ($data['sort_order'] ?? 0),
        ];

        // Optional group scope: NULL = org-wide default (backward compatible).
        // A group definition overrides an org-wide code for that group.
        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;
        $payload['include_descendants'] = ! empty($data['include_descendants']) ? 1 : 0;

        $q = $this->db->table('achievement_definitions')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $existing = $q->get()->getRowArray();

        if ($existing !== null) {
            $this->db->table('achievement_definitions')->where('id', $existing['id'])->update($payload);

            return Result::ok(['achievement_id' => $existing['id'], 'code' => $code, 'updated' => true]);
        }

        $id = Uuid::v7();
        $this->db->table('achievement_definitions')->insert($payload + [
            'id'              => $id,
            'organization_id' => $organizationId,
            'group_id'        => $groupId,
            'code'            => $code,
            'created_at'      => $this->clock->nowUtcString(),
        ]);

        return Result::created(['achievement_id' => $id, 'code' => $code, 'group_id' => $groupId]);
    }

    /** Deactivate a definition (existing unlocks are untouched). */
    /**
     * PARTIAL update of an existing achievement definition. Merges $changes over
     * the stored row (omitted fields unchanged); `code`/`group_id` immutable;
     * NOT_FOUND when absent. Delegates to define() for validation + persistence.
     * The stored `trigger_config` is a JSON string, which define() accepts as-is
     * when not overridden.
     *
     * @param array<string,mixed> $changes any subset of the define() fields
     */
    public function update(string $organizationId, string $code, array $changes, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $q   = $this->db->table('achievement_definitions')
            ->where('organization_id', $organizationId)->where('code', $code);
        $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
        $existing = $q->get()->getRowArray();
        if ($existing === null) {
            return Result::notFound('gamification.achievement_not_found', 'ACHIEVEMENT_NOT_FOUND');
        }

        $merged             = array_merge($existing, $changes);
        $merged['code']     = $code;
        $merged['group_id'] = $gid;

        return $this->define($organizationId, $merged);
    }

    public function disable(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $q = $this->db->table('achievement_definitions')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $def = $q->get()->getRowArray();
        if ($def === null) {
            return Result::notFound('gamification.achievement_not_found', 'ACHIEVEMENT_NOT_FOUND');
        }
        $this->db->table('achievement_definitions')->where('id', $def['id'])->update(['status' => 'inactive']);

        return Result::ok(['code' => $code, 'status' => 'inactive']);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function unlock(
        string $organizationId,
        string $subjectId,
        array $def,
        string $triggerSourceRef,
        array $progress,
        ?string $grantedBy = null,
        ?string $notes = null,
    ): Result {
        $seasonId    = $this->seasons->activeSeason($organizationId)['id'] ?? null;
        $bonusPoints = (int) ($def['bonus_points'] ?? 0);
        $bonusRule   = (string) ($def['bonus_rule_code'] ?? '');
        $bonusPosted = 0;

        $this->db->transStart();

        try {
            $this->db->table('user_achievements')->insert([
                'id'                   => Uuid::v7(),
                'organization_id'      => $organizationId,
                'achievement_id'       => $def['id'],
                'achievement_code'     => $def['code'],
                'subject_id'           => $subjectId,
                'season_id'            => $seasonId,
                'xp_awarded'           => (int) ($def['xp'] ?? 0),
                'bonus_points_awarded' => 0, // set below once the ledger posts
                'trigger_source_ref'   => $triggerSourceRef !== '' ? $triggerSourceRef : null,
                'granted_by'           => $grantedBy,
                'metadata'             => $notes !== null ? json_encode(['notes' => $notes]) : null,
                'unlocked_at'          => $this->clock->nowUtcMicro(),
            ]);
        } catch (Throwable) {
            $this->db->transComplete();

            // UNIQUE(achievement_id, subject_id) -> already unlocked.
            return Result::ok(['status' => 'duplicate', 'code' => $def['code']], 200, ['deduplicated' => true]);
        }

        // Post SPENDABLE bonus points through the ledger via the configured rule.
        if ($bonusPoints > 0 && $bonusRule !== '') {
            // Group attribution (design doc Part B): a group-scoped achievement
            // credits its own group as the RECEIVING group so the bonus rolls up
            // to it and every ancestor; an org-wide achievement (group_id NULL)
            // falls back to the earner's membership group in PointsEngine. The
            // achievement's own phase (win|build|send) tags the entry.
            $opts = ['subject_type' => 'user'];
            if (isset($def['group_id']) && $def['group_id'] !== null && $def['group_id'] !== '') {
                $opts['receiving_group_id'] = (string) $def['group_id'];
            }
            if (isset($def['phase']) && $def['phase'] !== '' && $def['phase'] !== 'general') {
                $opts['phase'] = (string) $def['phase'];
            }

            $award = $this->points->award(
                $organizationId,
                $bonusRule,
                $subjectId,
                'achievement:' . $def['code'],
                $opts,
            );
            if ($award->ok && is_array($award->data) && isset($award->data['points'])) {
                $bonusPosted = (int) $award->data['points'];
                $this->db->table('user_achievements')
                    ->where('achievement_id', $def['id'])->where('subject_id', $subjectId)
                    ->update(['bonus_points_awarded' => $bonusPosted]);
            }
        }

        // Clear any stored progress row now that it is unlocked.
        $this->db->table('user_achievement_progress')
            ->where('achievement_id', $def['id'])->where('subject_id', $subjectId)->delete();

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('UNLOCK_FAILED', 'gamification.unlock_failed', 500);
        }

        return Result::created([
            'code'         => $def['code'],
            'xp'           => (int) ($def['xp'] ?? 0),
            'bonus_points' => $bonusPosted,
        ]);
    }

    private function storeProgress(string $organizationId, string $subjectId, array $def, array $progress): void
    {
        $existing = $this->db->table('user_achievement_progress')
            ->where('achievement_id', $def['id'])->where('subject_id', $subjectId)
            ->get()->getRowArray();

        $row = [
            'organization_id' => $organizationId,
            'achievement_id'  => $def['id'],
            'subject_id'      => $subjectId,
            'current_value'   => $progress['current'],
            'required_value'  => $progress['required'],
            'percentage'      => $progress['percentage'],
            'updated_at'      => $this->clock->nowUtcMicro(),
        ];
        if ($existing === null) {
            $row['id'] = Uuid::v7();
            $this->db->table('user_achievement_progress')->insert($row);
        } else {
            $this->db->table('user_achievement_progress')->where('id', $existing['id'])->update($row);
        }
    }

    /** @return list<string> */
    private function unlockedCodes(string $organizationId, string $subjectId): array
    {
        return array_column(
            $this->db->table('user_achievements')
                ->select('achievement_code')
                ->where('organization_id', $organizationId)->where('subject_id', $subjectId)
                ->get()->getResultArray(),
            'achievement_code',
        );
    }

    /** Count of FINAL award entries, optionally for one rule code. */
    private function awardCount(string $organizationId, string $subjectId, ?string $ruleCode): int
    {
        $q = $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)->where('subject_id', $subjectId)
            ->where('entry_type', 'award')->where('state', 'final');
        if ($ruleCode !== null && $ruleCode !== '') {
            $ruleId = $this->ruleId($organizationId, $ruleCode);
            if ($ruleId === null) {
                return 0;
            }
            $q->where('rule_id', $ruleId);
        }

        return $q->countAllResults();
    }

    /** SUM of FINAL points, optionally for one rule code. */
    private function awardPointsSum(string $organizationId, string $subjectId, ?string $ruleCode): int
    {
        $q = $this->db->table('point_ledger')
            ->selectSum('points')
            ->where('organization_id', $organizationId)->where('subject_id', $subjectId)
            ->where('state', 'final');
        if ($ruleCode !== null && $ruleCode !== '') {
            $ruleId = $this->ruleId($organizationId, $ruleCode);
            if ($ruleId === null) {
                return 0;
            }
            $q->where('rule_id', $ruleId);
        }

        return (int) ($q->get()->getRowArray()['points'] ?? 0);
    }

    /** Distinct rules a subject earned FINAL awards under today. */
    private function distinctRulesToday(string $organizationId, string $subjectId): int
    {
        $today = $this->clock->now()->format('Y-m-d');
        $row   = $this->db->query(
            'SELECT COUNT(DISTINCT rule_id) AS n FROM point_ledger
             WHERE organization_id = ? AND subject_id = ? AND entry_type = "award"
               AND state = "final" AND DATE(created_at) = ?',
            [$organizationId, $subjectId, $today],
        )->getRowArray();

        return (int) ($row['n'] ?? 0);
    }

    private function ruleId(string $organizationId, string $ruleCode): ?string
    {
        $row = $this->db->table('gamification_rules')
            ->select('id')
            ->where('organization_id', $organizationId)->where('code', $ruleCode)
            ->orderBy('version', 'DESC')->get()->getRowArray();

        return $row['id'] ?? null;
    }

    /** @return array<string,mixed> */
    private function decodeConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }
        if (is_string($config) && $config !== '') {
            $decoded = json_decode($config, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
