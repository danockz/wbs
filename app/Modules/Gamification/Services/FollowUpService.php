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
 * Configurable FOLLOW-UPS — a first-class Build/Send activity (adapted from the
 * reference schema's follow_ups + method multipliers).
 *
 * Three data-configured pieces:
 *   - follow_up_types   : what kinds of follow-up exist (new_visitor, absent_member,
 *                         prayer_request, integration_check, …), each optionally
 *                         tied to an award rule and a default next-follow-up offset.
 *   - follow_up_methods : the channel (call/visit/sms/whatsapp/kingschat/…), each
 *                         with a multiplier_key fed to the award rule's multipliers.
 *   - follow_ups        : the recorded activity itself. Recording one optionally
 *                         AWARDS POINTS to the follower through the shared
 *                         PointsEngine (same immutable ledger, same anti-gaming).
 *
 * Types/methods are org + optional group scoped (NULL group_id = org-wide),
 * most-specific-wins like the other catalogs.
 */
final class FollowUpService
{
    private const PHASES = ['win', 'build', 'send', 'general'];

    private const STATUSES = ['pending', 'in_progress', 'completed', 'no_response', 'cancelled'];

    private const HEALTH = ['excellent', 'good', 'okay', 'challenged', 'very_challenged'];

    /**
     * The record STATUS vocabulary, in canonical order, for populating the
     * capture/edit form's status picker. Read-only view over the private const.
     *
     * @return list<string>
     */
    public function recordStatuses(): array
    {
        return self::STATUSES;
    }

    /**
     * The spiritual-health vocabulary, for the capture/edit form's health picker.
     *
     * @return list<string>
     */
    public function healthLevels(): array
    {
        return self::HEALTH;
    }

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?PointsEngine $points = null,
        private readonly ?GroupScopeResolver $groupScope = null,
        private readonly ?\WBS\Journey\Services\JourneySignalService $journeySignals = null,
    ) {
    }

    // ---- Types --------------------------------------------------------------

    /**
     * Define (or update) a follow-up type.
     *
     * @param array<string,mixed> $data code, name, phase, description,
     *          requires_outcome, default_next_days, award_rule_code, icon,
     *          color, sort_order, group_id, include_descendants, status
     */
    public function defineType(string $organizationId, array $data): Result
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('BAD_FOLLOWUP_TYPE', 'gamification.bad_followup_type', 422);
        }
        $phase = $data['phase'] ?? 'build';
        if (! in_array($phase, self::PHASES, true)) {
            return Result::fail('BAD_PHASE', 'gamification.bad_phase', 422, ['allowed' => self::PHASES]);
        }

        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;

        $q = $this->db->table('follow_up_types')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $existing = $q->get()->getRowArray();

        $payload = [
            'name'                => mb_substr($name, 0, 100),
            'phase'               => (string) $phase,
            // Optional journey stage link (Option D). Advisory only.
            'stage_code'          => isset($data['stage_code']) && $data['stage_code'] !== '' ? mb_substr((string) $data['stage_code'], 0, 60) : null,
            'description'         => isset($data['description']) ? mb_substr((string) $data['description'], 0, 255) : null,
            'requires_outcome'    => ! empty($data['requires_outcome']) ? 1 : 0,
            'default_next_days'   => isset($data['default_next_days']) && $data['default_next_days'] !== '' ? (int) $data['default_next_days'] : null,
            'award_rule_code'     => isset($data['award_rule_code']) && $data['award_rule_code'] !== '' ? mb_substr((string) $data['award_rule_code'], 0, 80) : null,
            'icon'                => isset($data['icon']) ? mb_substr((string) $data['icon'], 0, 120) : null,
            'color'               => isset($data['color']) ? mb_substr((string) $data['color'], 0, 20) : null,
            'sort_order'          => (int) ($data['sort_order'] ?? 0),
            'include_descendants' => ! empty($data['include_descendants']) ? 1 : 0,
            'status'              => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? (string) ($data['status'] ?? 'active') : 'active',
            'updated_at'          => $this->clock->nowUtcString(),
        ];

        try {
            if ($existing !== null) {
                $this->db->table('follow_up_types')->where('id', $existing['id'])->update($payload);

                return Result::ok(['id' => $existing['id'], 'code' => $code, 'updated' => true]);
            }
            $id = Uuid::v7();
            $this->db->table('follow_up_types')->insert($payload + [
                'id'              => $id,
                'organization_id' => $organizationId,
                'group_id'        => $groupId,
                'code'            => $code,
                'created_at'      => $this->clock->nowUtcString(),
            ]);

            return Result::created(['id' => $id, 'code' => $code]);
        } catch (Throwable) {
            return Result::fail('FOLLOWUP_TYPE_SAVE_FAILED', 'gamification.followup_type_save_failed', 500);
        }
    }

    /** @return list<array<string,mixed>> */
    /**
     * List follow-up types, optionally scoped to a group. With a group the
     * visible set is the UNION of the group's own types, org-wide (NULL) types,
     * and ancestor types flagged include_descendants=1 — collapsed MOST-SPECIFIC-
     * WINS per code so a subgroup override hides the inherited row. Without a
     * group, every type defined for the org is returned (unchanged behaviour).
     */
    public function listTypes(string $organizationId, bool $activeOnly = false, ?string $groupId = null): array
    {
        $q = $this->db->table('follow_up_types')->where('organization_id', $organizationId);
        if ($activeOnly) {
            $q->where('status', 'active');
        }

        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        if ($gid !== null && $this->groupScope !== null) {
            $ancestors = $this->groupScope->ancestors($gid);
            $q->groupStart()
                ->where('group_id', $gid)
                ->orWhere('group_id', null);
            if ($ancestors !== []) {
                $q->orGroupStart()
                    ->where('include_descendants', 1)
                    ->whereIn('group_id', $ancestors)
                  ->groupEnd();
            }
            $q->groupEnd();
        }

        $rows = $q->orderBy('phase', 'ASC')->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')
            ->get()->getResultArray();

        return $gid !== null && $this->groupScope !== null
            ? $this->collapseMostSpecific($rows, $gid, $this->groupScope->ancestors($gid))
            : $rows;
    }

    /**
     * Collapse a union of scoped rows to one per code, most-specific-wins:
     * self beats nearer ancestor beats farther ancestor beats org-wide (NULL).
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $ancestors nearest-first
     * @return list<array<string,mixed>>
     */
    private function collapseMostSpecific(array $rows, string $groupId, array $ancestors): array
    {
        $rank = [$groupId => 0];
        foreach ($ancestors as $i => $aid) {
            $rank[$aid] = $i + 1;
        }

        $best = [];
        foreach ($rows as $r) {
            $code = (string) $r['code'];
            $g    = $r['group_id'] !== null && $r['group_id'] !== '' ? (string) $r['group_id'] : null;
            $rk   = $g === null ? PHP_INT_MAX : ($rank[$g] ?? PHP_INT_MAX - 1);
            if (! isset($best[$code]) || $rk < $best[$code]['_rank']) {
                $r['_rank']  = $rk;
                $best[$code] = $r;
            }
        }

        $out = array_values(array_map(static function (array $r): array {
            unset($r['_rank']);

            return $r;
        }, $best));

        usort($out, static function (array $a, array $b): int {
            return [$a['phase'], (int) $a['sort_order'], $a['name']]
               <=> [$b['phase'], (int) $b['sort_order'], $b['name']];
        });

        return $out;
    }

    /** Read a single follow-up type (org-wide or a group override). 404 if unknown. */
    public function showType(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $q = $this->db->table('follow_up_types')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $row = $q->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.followup_type_not_found', 'FOLLOWUP_TYPE_NOT_FOUND');
        }

        return Result::ok($row);
    }

    /** Soft-delete a follow-up type (status=inactive; history/records untouched). */
    /**
     * PARTIAL update of an existing follow-up type. Merges $changes over the
     * stored row (omitted fields unchanged); `code`/`group_id` immutable;
     * NOT_FOUND when absent. Delegates to defineType() for validation + persist.
     *
     * @param array<string,mixed> $changes any subset of the defineType() fields
     */
    public function updateType(string $organizationId, string $code, array $changes, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $q   = $this->db->table('follow_up_types')
            ->where('organization_id', $organizationId)->where('code', $code);
        $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
        $existing = $q->get()->getRowArray();
        if ($existing === null) {
            return Result::notFound('gamification.followup_type_not_found', 'FOLLOWUP_TYPE_NOT_FOUND');
        }

        $merged             = array_merge($existing, $changes);
        $merged['code']     = $code;
        $merged['group_id'] = $gid;

        return $this->defineType($organizationId, $merged);
    }

    public function disableType(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $q = $this->db->table('follow_up_types')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $row = $q->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.followup_type_not_found', 'FOLLOWUP_TYPE_NOT_FOUND');
        }
        $this->db->table('follow_up_types')->where('id', $row['id'])
            ->update(['status' => 'inactive', 'updated_at' => $this->clock->nowUtcString()]);

        return Result::ok(['code' => $code, 'status' => 'inactive']);
    }

    // ---- Methods ------------------------------------------------------------

    /**
     * Define (or update) a follow-up method (channel).
     *
     * @param array<string,mixed> $data code, name, multiplier_key, sort_order, status
     */
    public function defineMethod(string $organizationId, array $data): Result
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('BAD_FOLLOWUP_METHOD', 'gamification.bad_followup_method', 422);
        }

        $existing = $this->db->table('follow_up_methods')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->get()->getRowArray();

        $payload = [
            'name'           => mb_substr($name, 0, 100),
            'multiplier_key' => isset($data['multiplier_key']) && $data['multiplier_key'] !== '' ? mb_substr((string) $data['multiplier_key'], 0, 50) : null,
            'sort_order'     => (int) ($data['sort_order'] ?? 0),
            'status'         => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? (string) ($data['status'] ?? 'active') : 'active',
            'updated_at'     => $this->clock->nowUtcString(),
        ];

        try {
            if ($existing !== null) {
                $this->db->table('follow_up_methods')->where('id', $existing['id'])->update($payload);

                return Result::ok(['id' => $existing['id'], 'code' => $code, 'updated' => true]);
            }
            $id = Uuid::v7();
            $this->db->table('follow_up_methods')->insert($payload + [
                'id'              => $id,
                'organization_id' => $organizationId,
                'code'            => $code,
                'created_at'      => $this->clock->nowUtcString(),
            ]);

            return Result::created(['id' => $id, 'code' => $code]);
        } catch (Throwable) {
            return Result::fail('FOLLOWUP_METHOD_SAVE_FAILED', 'gamification.followup_method_save_failed', 500);
        }
    }

    /** @return list<array<string,mixed>> */
    public function listMethods(string $organizationId, bool $activeOnly = false): array
    {
        $q = $this->db->table('follow_up_methods')->where('organization_id', $organizationId);
        if ($activeOnly) {
            $q->where('status', 'active');
        }

        return $q->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();
    }

    /** Read a single follow-up method (channel). 404 if unknown. */
    public function showMethod(string $organizationId, string $code): Result
    {
        $row = $this->db->table('follow_up_methods')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.followup_method_not_found', 'FOLLOWUP_METHOD_NOT_FOUND');
        }

        return Result::ok($row);
    }

    /** Soft-delete a follow-up method (status=inactive). */
    /**
     * PARTIAL update of an existing (org-wide) follow-up method. Merges $changes
     * over the stored row (omitted fields unchanged); `code` immutable;
     * NOT_FOUND when absent. Delegates to defineMethod() for validation/persist.
     *
     * @param array<string,mixed> $changes any subset of the defineMethod() fields
     */
    public function updateMethod(string $organizationId, string $code, array $changes): Result
    {
        $existing = $this->db->table('follow_up_methods')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->get()->getRowArray();
        if ($existing === null) {
            return Result::notFound('gamification.followup_method_not_found', 'FOLLOWUP_METHOD_NOT_FOUND');
        }

        $merged         = array_merge($existing, $changes);
        $merged['code'] = $code;

        return $this->defineMethod($organizationId, $merged);
    }

    public function disableMethod(string $organizationId, string $code): Result
    {
        $row = $this->db->table('follow_up_methods')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.followup_method_not_found', 'FOLLOWUP_METHOD_NOT_FOUND');
        }
        $this->db->table('follow_up_methods')->where('id', $row['id'])
            ->update(['status' => 'inactive', 'updated_at' => $this->clock->nowUtcString()]);

        return Result::ok(['code' => $code, 'status' => 'inactive']);
    }

    // ---- Records ------------------------------------------------------------

    /**
     * Record a follow-up. Validates type/method against the configured catalog,
     * derives the next-follow-up date from the type default when not supplied,
     * and — when the type has an award_rule_code — AWARDS POINTS to the follower
     * through the shared PointsEngine (the method's multiplier_key is passed as
     * the `method` multiplier input, so "a visit outweighs an SMS" is config).
     *
     * @param array<string,mixed> $data type_code, method_code, subject_user_id,
     *          status, performed_at, summary, outcome, spiritual_health, needs,
     *          next_follow_up_at, next_notes, group_id, org_timezone
     */
    public function record(string $organizationId, string $followerUserId, array $data): Result
    {
        $typeCode   = trim((string) ($data['type_code'] ?? ''));
        $methodCode = trim((string) ($data['method_code'] ?? ''));
        $subjectId  = trim((string) ($data['subject_user_id'] ?? ''));
        if ($typeCode === '' || $methodCode === '' || $subjectId === '') {
            return Result::fail('BAD_FOLLOWUP', 'gamification.bad_followup', 422);
        }

        $type = $this->resolveType($organizationId, $typeCode, $data['group_id'] ?? null);
        if ($type === null) {
            return Result::fail('UNKNOWN_TYPE', 'gamification.followup_unknown_type', 422, ['type' => $typeCode]);
        }
        $method = $this->db->table('follow_up_methods')
            ->where('organization_id', $organizationId)->where('code', $methodCode)->where('status', 'active')
            ->get()->getRowArray();
        if ($method === null) {
            return Result::fail('UNKNOWN_METHOD', 'gamification.followup_unknown_method', 422, ['method' => $methodCode]);
        }

        $status = in_array($data['status'] ?? 'completed', self::STATUSES, true) ? (string) ($data['status'] ?? 'completed') : 'completed';

        $health = null;
        if (isset($data['spiritual_health']) && $data['spiritual_health'] !== '') {
            $h = strtolower((string) $data['spiritual_health']);
            if (! in_array($h, self::HEALTH, true)) {
                return Result::fail('BAD_HEALTH', 'gamification.bad_spiritual_health', 422, ['allowed' => self::HEALTH]);
            }
            $health = $h;
        }

        if (! empty($type['requires_outcome']) && trim((string) ($data['outcome'] ?? '')) === '' && $status === 'completed') {
            return Result::fail('OUTCOME_REQUIRED', 'gamification.followup_outcome_required', 422);
        }

        $now         = $this->clock->nowUtcString();
        $performedAt = isset($data['performed_at']) && $data['performed_at'] !== '' ? (string) $data['performed_at'] : $now;

        // Next follow-up: explicit value wins; else derive from the type default.
        $nextAt = isset($data['next_follow_up_at']) && $data['next_follow_up_at'] !== ''
            ? (string) $data['next_follow_up_at']
            : ($type['default_next_days'] !== null
                ? $this->clock->now()->modify('+' . (int) $type['default_next_days'] . ' days')->format('Y-m-d H:i:s')
                : null);

        $needs = null;
        if (isset($data['needs'])) {
            $needs = is_string($data['needs']) ? $data['needs'] : (json_encode($data['needs']) ?: null);
        }

        $id = Uuid::v7();
        try {
            $this->db->table('follow_ups')->insert([
                'id'                => $id,
                'organization_id'   => $organizationId,
                'group_id'          => isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null,
                'subject_user_id'   => $subjectId,
                'follower_user_id'  => $followerUserId,
                'type_code'         => $typeCode,
                'method_code'       => $methodCode,
                'status'            => $status,
                'performed_at'      => $performedAt,
                'summary'           => isset($data['summary']) ? mb_substr((string) $data['summary'], 0, 500) : null,
                'outcome'           => isset($data['outcome']) ? mb_substr((string) $data['outcome'], 0, 500) : null,
                'spiritual_health'  => $health,
                'needs'             => $needs,
                'next_follow_up_at' => $nextAt,
                'next_notes'        => isset($data['next_notes']) ? mb_substr((string) $data['next_notes'], 0, 500) : null,
                'award_ledger_id'   => null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        } catch (Throwable) {
            return Result::fail('FOLLOWUP_SAVE_FAILED', 'gamification.followup_save_failed', 500);
        }

        // Award points to the follower when configured and the follow-up counts
        // (a cancelled/no-response record earns nothing). Best-effort: a capped
        // or cooled-down award does not fail the record.
        $award = $this->awardFor(
            $organizationId,
            $id,
            $followerUserId,
            $type,
            $method['multiplier_key'] ?? $methodCode,
            $status,
            (string) ($data['org_timezone'] ?? 'UTC'),
            isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null,
        );

        // Journey signal (Option C): a completed follow-up on the SUBJECT may
        // advance THEIR journey when a membership rule matches (the follower is
        // the natural discipler). Best-effort; never fails the record. Carries
        // the follow-up type/outcome/spiritual_health so rules can gate on them.
        if ($status === 'completed' && $this->journeySignals !== null) {
            try {
                $this->journeySignals->ingest($organizationId, [
                    'action'         => 'journey.signal.follow_up.recorded',
                    'user_id'        => $subjectId,
                    'scope_group_id' => isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : '',
                    // Explicit pass-through only — a follow-up has no intrinsic project.
                    'project_code'   => isset($data['project_code']) && $data['project_code'] !== '' ? (string) $data['project_code'] : '',
                    'discipler_id'   => $followerUserId,
                    'evidence_type'  => 'follow_up',
                    'evidence_ref'   => 'follow_up:' . $id,
                    'attributes'     => array_filter([
                        'follow_up_type'   => $typeCode,
                        'follow_up_phase'  => (string) ($type['phase'] ?? ''),
                        'outcome'          => (string) ($data['outcome'] ?? ''),
                        'spiritual_health' => (string) ($health ?? ''),
                    ], static fn ($v): bool => $v !== ''),
                ]);
            } catch (Throwable) {
                // Journey automation is best-effort.
            }
        }

        return Result::created([
            'id'                => $id,
            'type_code'         => $typeCode,
            'method_code'       => $methodCode,
            'status'            => $status,
            'next_follow_up_at' => $nextAt,
            'award'             => $award,
        ]);
    }

    /**
     * Follow-ups scheduled to happen on/before a cutoff (default now) that are
     * still open — the "who needs following up" work queue.
     *
     * The rows are ENRICHED for the triage view in a RESOURCE-LIGHT way: after
     * the single due-window query, subject/follower display names, the follow-up
     * type's name + WBS phase, and the subject's CURRENT JOURNEY STAGE are each
     * resolved with ONE additional batched `whereIn` query (four bounded reads
     * total, independent of row count). This lets a leader triage the queue by
     * where each person is on their discipleship journey. Passing $stageCode
     * filters the queue to a single journey stage (post-enrichment, since stage
     * lives in a different table); an empty subject-stage never matches a filter.
     *
     * @param array{stage_code?:string,phase?:string} $opts
     * @return list<array<string,mixed>>
     */
    public function dueFollowUps(string $organizationId, ?string $followerUserId = null, ?string $cutoff = null, int $limit = 100, array $opts = []): array
    {
        $cutoff ??= $this->clock->nowUtcString();
        $q = $this->db->table('follow_ups')
            ->where('organization_id', $organizationId)
            ->where('next_follow_up_at IS NOT NULL', null, false)
            ->where('next_follow_up_at <=', $cutoff)
            ->whereIn('status', ['pending', 'in_progress', 'completed', 'no_response']);
        if ($followerUserId !== null) {
            $q->where('follower_user_id', $followerUserId);
        }

        $rows = $q->orderBy('next_follow_up_at', 'ASC')
            ->limit(max(1, min($limit, 500)))
            ->get()->getResultArray();

        return $this->enrichDueRows($organizationId, $rows, $opts);
    }

    /**
     * Attach display names, type name/phase, and current journey stage to a set
     * of follow-up rows using four bounded batch queries (users ×1, types ×1,
     * member_journeys ×1). Also normalises the presenter keys the due-list view
     * expects (subject_name, type, due_at, phase, stage_code, stage_phase).
     *
     * @param list<array<string,mixed>>            $rows
     * @param array{stage_code?:string,phase?:string} $opts
     * @return list<array<string,mixed>>
     */
    private function enrichDueRows(string $organizationId, array $rows, array $opts = []): array
    {
        if ($rows === []) {
            return [];
        }

        // 1) collect the distinct ids/codes we need to resolve.
        $userIds    = [];
        $typeCodes  = [];
        $subjectIds = [];
        foreach ($rows as $r) {
            foreach (['subject_user_id', 'follower_user_id'] as $k) {
                $v = (string) ($r[$k] ?? '');
                if ($v !== '') {
                    $userIds[$v] = true;
                }
            }
            $sc = (string) ($r['subject_user_id'] ?? '');
            if ($sc !== '') {
                $subjectIds[$sc] = true;
            }
            $tc = (string) ($r['type_code'] ?? '');
            if ($tc !== '') {
                $typeCodes[$tc] = true;
            }
        }

        // 2) names — ONE query over users.
        $names = [];
        if ($userIds !== []) {
            foreach (
                $this->db->table('users')->select('id, display_name')
                    ->where('organization_id', $organizationId)
                    ->whereIn('id', array_keys($userIds))
                    ->get()->getResultArray() as $u
            ) {
                $names[(string) $u['id']] = (string) ($u['display_name'] ?? '');
            }
        }

        // 3) type name + phase — ONE query over follow_up_types (any scope; the
        //    most-specific active row per code wins, org-wide last).
        $typeName  = [];
        $typePhase = [];
        if ($typeCodes !== []) {
            foreach (
                $this->db->table('follow_up_types')->select('code, name, phase, group_id, status')
                    ->where('organization_id', $organizationId)
                    ->whereIn('code', array_keys($typeCodes))
                    ->get()->getResultArray() as $t
            ) {
                $code = (string) $t['code'];
                // Prefer a group-scoped active row over the org-wide one.
                $isActive   = ((string) ($t['status'] ?? 'active')) === 'active';
                $isScoped   = ($t['group_id'] ?? null) !== null && $t['group_id'] !== '';
                $haveScoped = isset($typeName[$code . '#scoped']);
                if (! $isActive && isset($typeName[$code])) {
                    continue;
                }
                if ($haveScoped && ! $isScoped) {
                    continue;
                }
                $typeName[$code]  = (string) ($t['name'] ?? $code);
                $typePhase[$code] = (string) ($t['phase'] ?? 'build');
                if ($isScoped) {
                    $typeName[$code . '#scoped'] = true;
                }
            }
        }

        // 4) current journey stage per subject — ONE query over member_journeys.
        //    A person can have several context journeys; prefer the org-wide
        //    primary (group_id NULL), else the most-recently-entered.
        $stageOf = [];
        $phaseOf = [];
        if ($subjectIds !== []) {
            foreach (
                $this->db->table('member_journeys')
                    ->select('user_id, group_id, stage_code, stage_phase, stage_entered_at')
                    ->where('organization_id', $organizationId)
                    ->whereIn('user_id', array_keys($subjectIds))
                    ->whereIn('status', ['active', 'paused'])
                    ->orderBy('stage_entered_at', 'ASC')
                    ->get()->getResultArray() as $j
            ) {
                $uid       = (string) $j['user_id'];
                $isPrimary = ($j['group_id'] ?? null) === null || $j['group_id'] === '';
                // Overwrite unless we already stored the org-wide primary.
                if (isset($stageOf[$uid . '#primary']) && ! $isPrimary) {
                    continue;
                }
                $stageOf[$uid] = (string) ($j['stage_code'] ?? '');
                $phaseOf[$uid] = (string) ($j['stage_phase'] ?? '');
                if ($isPrimary) {
                    $stageOf[$uid . '#primary'] = true;
                }
            }
        }

        // 5) stitch + normalise presenter keys; apply optional filters.
        $stageFilter = isset($opts['stage_code']) && $opts['stage_code'] !== '' ? (string) $opts['stage_code'] : null;
        $phaseFilter = isset($opts['phase']) && $opts['phase'] !== '' ? (string) $opts['phase'] : null;

        $out = [];
        foreach ($rows as $r) {
            $subjId   = (string) ($r['subject_user_id'] ?? '');
            $follId   = (string) ($r['follower_user_id'] ?? '');
            $code     = (string) ($r['type_code'] ?? '');
            $stage    = $stageOf[$subjId] ?? '';
            $stagePh  = $phaseOf[$subjId] ?? '';
            $typePh   = $typePhase[$code] ?? 'build';

            if ($stageFilter !== null && $stage !== $stageFilter) {
                continue;
            }
            if ($phaseFilter !== null && $stagePh !== $phaseFilter && $typePh !== $phaseFilter) {
                continue;
            }

            $out[] = $r + [
                'subject_name'   => $names[$subjId] ?? '',
                'follower_name'  => $names[$follId] ?? '',
                'type'           => $typeName[$code] ?? $code,
                'due_at'         => $r['next_follow_up_at'] ?? null,
                'phase'          => $typePh,
                'stage_code'     => $stage,
                'stage_phase'    => $stagePh,
            ];
        }

        return $out;
    }

    /**
     * History of follow-ups for a subject member (most recent first).
     *
     * @return list<array<string,mixed>>
     */
    public function historyForSubject(string $organizationId, string $subjectUserId, int $limit = 50): array
    {
        return $this->db->table('follow_ups')
            ->where('organization_id', $organizationId)
            ->where('subject_user_id', $subjectUserId)
            ->orderBy('performed_at', 'DESC')
            ->limit(max(1, min($limit, 200)))
            ->get()->getResultArray();
    }

    /** Read a single follow-up record (with its needs JSON decoded). 404 if unknown. */
    public function showRecord(string $organizationId, string $id): Result
    {
        $row = $this->db->table('follow_ups')
            ->where('organization_id', $organizationId)->where('id', $id)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.followup_not_found', 'FOLLOWUP_NOT_FOUND');
        }
        if (isset($row['needs']) && is_string($row['needs'])) {
            $row['needs'] = json_decode($row['needs'], true);
        }

        return Result::ok($row);
    }

    /**
     * Update a follow-up record: edit the outcome/notes, transition its status
     * (e.g. pending → completed), and/or reschedule the next follow-up. The
     * type_code, method_code, subject and follower are immutable (re-record a
     * new follow-up instead).
     *
     * If the record was NOT yet awarded and this update transitions it into a
     * counting state (completed|in_progress) for a type that awards points, the
     * award is posted now — idempotently, since the ledger source_ref is fixed
     * per record. A "requires_outcome" type refuses a completed status without
     * an outcome.
     *
     * @param array<string,mixed> $data status, summary, outcome, spiritual_health,
     *          needs, next_follow_up_at, next_notes, performed_at, org_timezone
     */
    public function updateRecord(string $organizationId, string $id, array $data): Result
    {
        $row = $this->db->table('follow_ups')
            ->where('organization_id', $organizationId)->where('id', $id)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.followup_not_found', 'FOLLOWUP_NOT_FOUND');
        }
        if ($row['status'] === 'cancelled') {
            return Result::fail('FOLLOWUP_CANCELLED', 'gamification.followup_cancelled', 409);
        }

        $type = $this->resolveType($organizationId, (string) $row['type_code'], $row['group_id'] ?? null);

        $newStatus = $row['status'];
        if (isset($data['status']) && $data['status'] !== '') {
            if (! in_array($data['status'], self::STATUSES, true)) {
                return Result::fail('BAD_STATUS', 'gamification.followup_bad_status', 422, ['allowed' => self::STATUSES]);
            }
            $newStatus = (string) $data['status'];
        }

        // Outcome resolution (new value wins; else keep existing).
        $outcome = array_key_exists('outcome', $data)
            ? ($data['outcome'] !== null ? mb_substr((string) $data['outcome'], 0, 500) : null)
            : $row['outcome'];
        if ($type !== null && ! empty($type['requires_outcome']) && $newStatus === 'completed'
            && trim((string) ($outcome ?? '')) === '') {
            return Result::fail('OUTCOME_REQUIRED', 'gamification.followup_outcome_required', 422);
        }

        $health = $row['spiritual_health'];
        if (array_key_exists('spiritual_health', $data)) {
            if ($data['spiritual_health'] === null || $data['spiritual_health'] === '') {
                $health = null;
            } else {
                $h = strtolower((string) $data['spiritual_health']);
                if (! in_array($h, self::HEALTH, true)) {
                    return Result::fail('BAD_HEALTH', 'gamification.bad_spiritual_health', 422, ['allowed' => self::HEALTH]);
                }
                $health = $h;
            }
        }

        $upd = [
            'status'     => $newStatus,
            'outcome'    => $outcome,
            'spiritual_health' => $health,
            'updated_at' => $this->clock->nowUtcString(),
        ];
        if (array_key_exists('summary', $data)) {
            $upd['summary'] = $data['summary'] !== null ? mb_substr((string) $data['summary'], 0, 500) : null;
        }
        if (array_key_exists('next_follow_up_at', $data)) {
            $upd['next_follow_up_at'] = $data['next_follow_up_at'] !== '' ? $data['next_follow_up_at'] : null;
        }
        if (array_key_exists('next_notes', $data)) {
            $upd['next_notes'] = $data['next_notes'] !== null ? mb_substr((string) $data['next_notes'], 0, 500) : null;
        }
        if (array_key_exists('performed_at', $data) && $data['performed_at'] !== '') {
            $upd['performed_at'] = $data['performed_at'];
        }
        if (array_key_exists('needs', $data)) {
            $upd['needs'] = $data['needs'] === null ? null : (is_string($data['needs']) ? $data['needs'] : (json_encode($data['needs']) ?: null));
        }

        try {
            $this->db->table('follow_ups')->where('id', $id)->update($upd);
        } catch (Throwable) {
            return Result::fail('FOLLOWUP_SAVE_FAILED', 'gamification.followup_save_failed', 500);
        }

        // Post a (still-idempotent) award if the record now counts and hasn't
        // been awarded yet.
        $award = null;
        if ($row['award_ledger_id'] === null && $type !== null) {
            $method = $this->db->table('follow_up_methods')
                ->where('organization_id', $organizationId)->where('code', (string) $row['method_code'])
                ->get()->getRowArray();
            $award = $this->awardFor(
                $organizationId,
                $id,
                (string) $row['follower_user_id'],
                $type,
                $method['multiplier_key'] ?? (string) $row['method_code'],
                $newStatus,
                (string) ($data['org_timezone'] ?? 'UTC'),
                isset($row['group_id']) && $row['group_id'] !== '' ? (string) $row['group_id'] : null,
            );
        }

        return Result::ok([
            'id'                => $id,
            'status'            => $newStatus,
            'next_follow_up_at' => $upd['next_follow_up_at'] ?? $row['next_follow_up_at'],
            'award'             => $award,
        ]);
    }

    /**
     * Cancel a follow-up (soft: status=cancelled). Points already awarded are
     * NOT clawed back here — a follow-up genuinely performed still counts; use
     * PointsEngine::reverse('followup:{id}') if a reversal is truly warranted.
     */
    public function cancelRecord(string $organizationId, string $id): Result
    {
        $row = $this->db->table('follow_ups')
            ->where('organization_id', $organizationId)->where('id', $id)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.followup_not_found', 'FOLLOWUP_NOT_FOUND');
        }
        if ($row['status'] === 'cancelled') {
            return Result::ok(['id' => $id, 'status' => 'cancelled'], 200, ['already_cancelled' => true]);
        }
        $this->db->table('follow_ups')->where('id', $id)
            ->update(['status' => 'cancelled', 'updated_at' => $this->clock->nowUtcString()]);

        return Result::ok(['id' => $id, 'status' => 'cancelled']);
    }

    // ------------------------------------------------------------------------

    /**
     * Award points to the follower for a follow-up when the type is award-linked
     * and the status counts (completed|in_progress). Idempotent via a fixed
     * source_ref (followup:{id}); a capped/cooled-down award is reported but
     * never fails the caller. Returns an award summary, a skip reason, or null.
     *
     * @param array<string,mixed>|null $type
     * @return array<string,mixed>|null
     */
    private function awardFor(
        string $organizationId,
        string $followUpId,
        string $followerUserId,
        ?array $type,
        string $methodKey,
        string $status,
        string $orgTimezone,
        ?string $groupId = null,
    ): ?array {
        if ($this->points === null
            || $type === null
            || ($type['award_rule_code'] ?? null) === null
            || ! in_array($status, ['completed', 'in_progress'], true)) {
            return null;
        }

        // Group attribution (design doc Part B): credit the follow-up's own group
        // as the RECEIVING group so the award rolls up to it and every ancestor.
        // When the record carries no group, PointsEngine falls back to the
        // follower's own membership group, else org-level. The follow-up type's
        // configured phase (win|build|send) tags the entry for phase boards.
        $opts = [
            'subject_type' => 'user',
            'org_timezone' => $orgTimezone,
            'data'         => ['method' => $methodKey, 'count' => 1],
        ];
        if ($groupId !== null && $groupId !== '') {
            $opts['receiving_group_id'] = $groupId;
        }
        if (isset($type['phase']) && $type['phase'] !== '' && $type['phase'] !== 'general') {
            $opts['phase'] = (string) $type['phase'];
        }

        $res = $this->points->award(
            $organizationId,
            (string) $type['award_rule_code'],
            $followerUserId,
            'followup:' . $followUpId,
            $opts,
        );

        if ($res->ok && is_array($res->data) && isset($res->data['ledger_id'])) {
            $this->db->table('follow_ups')->where('id', $followUpId)
                ->update(['award_ledger_id' => $res->data['ledger_id']]);

            return ['ledger_id' => $res->data['ledger_id'], 'points' => $res->data['points'] ?? 0, 'state' => $res->data['state'] ?? null];
        }

        return ['skipped' => $res->code];
    }

    /**
     * Resolve a follow-up type most-specific-wins: exact group row, else the
     * org-wide (NULL group) row. @return array<string,mixed>|null
     */
    /**
     * Resolve the active follow-up type for a code within a group scope,
     * MOST-SPECIFIC-WINS across the FULL hierarchy: the group's own row, then
     * each ancestor nearest-first (only when that ancestor is flagged
     * include_descendants=1), then the org-wide (NULL) default. This is the same
     * inheritance every other group-scoped catalog uses — a subgroup transparently
     * inherits a parent's follow-up types unless it defines its own.
     */
    private function resolveType(string $organizationId, string $code, mixed $groupId): ?array
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        if ($this->groupScope !== null) {
            return $this->groupScope->resolveByCode(
                'follow_up_types',
                $organizationId,
                $code,
                $gid,
                ['status' => 'active'],
            );
        }

        // Fallback (resolver not wired): self then org-wide only.
        if ($gid !== null) {
            $row = $this->db->table('follow_up_types')
                ->where('organization_id', $organizationId)->where('code', $code)
                ->where('group_id', $gid)->where('status', 'active')
                ->get()->getRowArray();
            if ($row !== null) {
                return $row;
            }
        }

        return $this->db->table('follow_up_types')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->where('group_id', null)->where('status', 'active')
            ->get()->getRowArray() ?: null;
    }
}
