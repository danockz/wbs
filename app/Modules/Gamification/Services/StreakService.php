<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Activity streaks with freeze/grace support (adaptation of awardlib.md streaks).
 *
 * Adapted to WBS conventions: UUID subject ids, BaseConnection+Clock DI, Result
 * returns, and the existing `user_streaks` table (current_count/best_count/
 * last_event_date + the freeze_until/grace_days columns added in 000030).
 *
 *  - record(): idempotent per calendar day. A same-day repeat is a no-op; a
 *    consecutive day (or a day covered by an active freeze) extends the streak;
 *    otherwise it resets to 1.
 *  - Streaks are season-scoped when a season_id is supplied, matching the
 *    UNIQUE(org, subject, streak_code, season_id) key.
 */
final class StreakService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?GroupScopeResolver $groupScope = null,
    ) {
    }

    /**
     * Read one streak definition by code. Within a group scope, resolution is
     * most-specific-wins over the ancestor chain (self → ancestor-with-
     * descendants → org-wide); without a group it reads the org-wide row.
     */
    public function show(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        if ($gid !== null && $this->groupScope !== null) {
            $row = $this->groupScope->resolveByCode('streak_definitions', $organizationId, $code, $gid, []);
        } else {
            $q = $this->db->table('streak_definitions')
                ->where('organization_id', $organizationId)->where('code', $code);
            $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
            $row = $q->get()->getRowArray();
        }

        if ($row === null) {
            return Result::notFound('gamification.streak_not_found', 'STREAK_NOT_FOUND');
        }
        $row['include_descendants'] = (bool) ($row['include_descendants'] ?? 0);

        return Result::ok($row);
    }

    /**
     * Record activity for a streak on "today" (org/UTC calendar date).
     *
     * @return Result data: current_count, best_count, state (new|extended|maintained|reset)
     */
    public function record(string $organizationId, string $subjectId, string $streakCode, ?string $seasonId = null): Result
    {
        if ($streakCode === '') {
            return Result::fail('BAD_STREAK', 'gamification.bad_streak', 422);
        }

        $today = $this->clock->now()->format('Y-m-d');
        $row   = $this->find($organizationId, $subjectId, $streakCode, $seasonId);

        if ($row === null) {
            $id = Uuid::v7();
            $this->db->table('user_streaks')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'subject_id'      => $subjectId,
                'streak_code'     => $streakCode,
                'season_id'       => $seasonId,
                'current_count'   => 1,
                'best_count'      => 1,
                'last_event_date' => $today,
                'freeze_until'    => null,
                'grace_days'      => 0,
                'updated_at'      => $this->clock->nowUtcString(),
            ]);

            return Result::created(['current_count' => 1, 'best_count' => 1, 'state' => 'new']);
        }

        $last = $row['last_event_date'] ?? null;
        if ($last === $today) {
            return Result::ok([
                'current_count' => (int) $row['current_count'],
                'best_count'    => (int) $row['best_count'],
                'state'         => 'maintained',
            ], 200, ['already_recorded' => true]);
        }

        $yesterday = $this->clock->now()->modify('-1 day')->format('Y-m-d');
        $frozen    = ! empty($row['freeze_until']) && $row['freeze_until'] >= $today;

        if ($last === $yesterday || $frozen) {
            $current = (int) $row['current_count'] + 1;
            $state   = 'extended';
        } else {
            $current = 1;
            $state   = 'reset';
        }
        $best = max((int) $row['best_count'], $current);

        $this->db->table('user_streaks')->where('id', $row['id'])->update([
            'current_count'   => $current,
            'best_count'      => $best,
            'last_event_date' => $today,
            'freeze_until'    => null, // consumed
            'updated_at'      => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['current_count' => $current, 'best_count' => $best, 'state' => $state]);
    }

    /**
     * Freeze a streak for N days so a missed day does not reset it (grace).
     */
    public function freeze(string $organizationId, string $subjectId, string $streakCode, int $days = 1, ?string $seasonId = null): Result
    {
        $days = max(1, min($days, 30));
        $row  = $this->find($organizationId, $subjectId, $streakCode, $seasonId);
        if ($row === null) {
            return Result::notFound('gamification.streak_not_found', 'STREAK_NOT_FOUND');
        }

        $until = $this->clock->now()->modify("+{$days} days")->format('Y-m-d');
        $this->db->table('user_streaks')->where('id', $row['id'])->update([
            'freeze_until' => $until,
            'grace_days'   => $days,
            'updated_at'   => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['streak_code' => $streakCode, 'freeze_until' => $until, 'days' => $days]);
    }

    /** Current streak state (0 counts when none). */
    public function get(string $organizationId, string $subjectId, string $streakCode, ?string $seasonId = null): array
    {
        $row = $this->find($organizationId, $subjectId, $streakCode, $seasonId);
        if ($row === null) {
            return ['streak_code' => $streakCode, 'current_count' => 0, 'best_count' => 0, 'last_event_date' => null, 'freeze_until' => null];
        }

        return [
            'streak_code'     => $streakCode,
            'current_count'   => (int) $row['current_count'],
            'best_count'      => (int) $row['best_count'],
            'last_event_date' => $row['last_event_date'],
            'freeze_until'    => $row['freeze_until'],
        ];
    }

    /** @return list<array<string,mixed>> All streaks for a subject. */
    public function getAll(string $organizationId, string $subjectId, ?string $seasonId = null): array
    {
        $q = $this->db->table('user_streaks')
            ->where('organization_id', $organizationId)
            ->where('subject_id', $subjectId);
        if ($seasonId !== null) {
            $q->where('season_id', $seasonId);
        }

        return array_map(static fn ($r) => [
            'streak_code'   => $r['streak_code'],
            'current_count' => (int) $r['current_count'],
            'best_count'    => (int) $r['best_count'],
            'last_event_date' => $r['last_event_date'],
            'freeze_until'  => $r['freeze_until'],
        ], $q->get()->getResultArray());
    }

    // -------------------------------------------------------------------------
    // Admin management of the streak-type catalog (streak_definitions)
    // -------------------------------------------------------------------------

    /**
     * Create or update a streak definition (admin). Upsert by code.
     *
     * @param array<string,mixed> $data code, name, cadence, default_grace_days,
     *          description, icon, color, status, sort_order
     */
    public function define(string $organizationId, array $data): Result
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('BAD_STREAK_DEF', 'gamification.streak_code_name_required', 422);
        }
        $cadence = (string) ($data['cadence'] ?? 'daily');
        if (! in_array($cadence, ['daily', 'weekly'], true)) {
            return Result::fail('BAD_CADENCE', 'gamification.bad_cadence', 422);
        }

        $payload = [
            'name'               => mb_substr($name, 0, 150),
            'description'        => isset($data['description']) ? mb_substr((string) $data['description'], 0, 255) : null,
            'cadence'            => $cadence,
            'default_grace_days' => (int) ($data['default_grace_days'] ?? 0),
            'icon'               => $data['icon'] ?? null,
            'color'              => $data['color'] ?? null,
            'status'             => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? $data['status'] : 'active',
            'sort_order'         => (int) ($data['sort_order'] ?? 0),
        ];

        // Optional group scope: NULL = org-wide default (backward compatible).
        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;
        $payload['include_descendants'] = ! empty($data['include_descendants']) ? 1 : 0;

        $q = $this->db->table('streak_definitions')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $existing = $q->get()->getRowArray();

        if ($existing !== null) {
            $payload['updated_at'] = $this->clock->nowUtcString();
            $this->db->table('streak_definitions')->where('id', $existing['id'])->update($payload);

            return Result::ok(['streak_id' => $existing['id'], 'code' => $code, 'updated' => true]);
        }

        $id = Uuid::v7();
        $this->db->table('streak_definitions')->insert($payload + [
            'id'              => $id,
            'organization_id' => $organizationId,
            'group_id'        => $groupId,
            'code'            => $code,
            'created_at'      => $this->clock->nowUtcString(),
        ]);

        return Result::created(['streak_id' => $id, 'code' => $code, 'group_id' => $groupId]);
    }

    /** Deactivate a streak definition (org-wide by default, or a group's). */
    /**
     * PARTIAL update of an existing streak definition. Merges $changes over the
     * stored row (omitted fields unchanged); `code`/`group_id` immutable;
     * NOT_FOUND when absent. Delegates to define() for validation + persistence.
     *
     * @param array<string,mixed> $changes any subset of the define() fields
     */
    public function updateDefinition(string $organizationId, string $code, array $changes, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $q   = $this->db->table('streak_definitions')
            ->where('organization_id', $organizationId)->where('code', $code);
        $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
        $existing = $q->get()->getRowArray();
        if ($existing === null) {
            return Result::notFound('gamification.streak_def_not_found', 'STREAK_DEF_NOT_FOUND');
        }

        $merged             = array_merge($existing, $changes);
        $merged['code']     = $code;
        $merged['group_id'] = $gid;

        return $this->define($organizationId, $merged);
    }

    public function disableDefinition(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $q = $this->db->table('streak_definitions')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $def = $q->get()->getRowArray();
        if ($def === null) {
            return Result::notFound('gamification.streak_def_not_found', 'STREAK_DEF_NOT_FOUND');
        }
        $this->db->table('streak_definitions')->where('id', $def['id'])->update([
            'status'     => 'inactive',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['code' => $code, 'status' => 'inactive']);
    }

    /** @return list<array<string,mixed>> Active streak definitions. */
    public function definitions(string $organizationId): array
    {
        return $this->db->table('streak_definitions')
            ->where('organization_id', $organizationId)->where('status', 'active')
            ->where('group_id', null)
            ->orderBy('sort_order', 'ASC')->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    private function find(string $organizationId, string $subjectId, string $streakCode, ?string $seasonId): ?array
    {
        $q = $this->db->table('user_streaks')
            ->where('organization_id', $organizationId)
            ->where('subject_id', $subjectId)
            ->where('streak_code', $streakCode);
        // Match the NULL-or-value season semantics of the UNIQUE key.
        $seasonId === null ? $q->where('season_id', null) : $q->where('season_id', $seasonId);

        return $q->get()->getRowArray() ?: null;
    }
}
