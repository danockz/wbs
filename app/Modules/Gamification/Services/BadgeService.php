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
 * Admin management of the BADGE catalog plus manual badge granting/revocation.
 *
 * Badges are a recognition award distinct from points: a badge DEFINITION lives
 * in `badges` (org + optional group scoped, most-specific-wins like ranks /
 * achievements / streaks), and a badge AWARD lives in `badge_awards` (a subject
 * earned a badge, optionally per season / per source_ref for repeatable wins).
 *
 * Before this service badges could only be seeded; now they are full CRUD:
 *   - define()  — create or update a definition (upsert on scope+code),
 *   - list()    — every definition for the org (optionally group-scoped view),
 *   - show()    — read one definition (most-specific-wins within a group),
 *   - disable() — soft-delete (status=inactive); existing awards are untouched,
 *   - grant()/revoke() — manual award lifecycle (idempotent, never hard-deletes;
 *     revoke posts state=revoked, mirroring the immutable-ledger convention).
 *
 * Deleting is intentionally soft (disable) to preserve the historical meaning of
 * already-granted awards, consistent with the rest of gamification.
 */
final class BadgeService
{
    private const VISIBILITY = ['public', 'group', 'private'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?GroupScopeResolver $groupScope = null,
    ) {
    }

    // ---- Definition CRUD ----------------------------------------------------

    /**
     * Create or update a badge definition. Upserts on (org, group_id, code):
     * re-defining the same code within the same scope edits it in place.
     *
     * @param array<string,mixed> $data code, name, description?, icon?, criteria?,
     *        visibility?, permanent?, expiry_policy?, sort_order?, group_id?,
     *        include_descendants?, status?
     */
    public function define(string $organizationId, array $data): Result
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('BAD_BADGE', 'gamification.bad_badge', 422);
        }

        $visibility = (string) ($data['visibility'] ?? 'public');
        if (! in_array($visibility, self::VISIBILITY, true)) {
            return Result::fail('BAD_VISIBILITY', 'gamification.bad_visibility', 422, ['allowed' => self::VISIBILITY]);
        }

        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;

        $criteria = null;
        if (isset($data['criteria']) && $data['criteria'] !== null && $data['criteria'] !== '') {
            $criteria = is_string($data['criteria']) ? $data['criteria'] : (json_encode($data['criteria']) ?: null);
        }

        $q = $this->db->table('badges')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $existing = $q->get()->getRowArray();

        $payload = [
            'name'                => mb_substr($name, 0, 150),
            'description'         => isset($data['description']) ? mb_substr((string) $data['description'], 0, 255) : null,
            'icon'                => isset($data['icon']) ? mb_substr((string) $data['icon'], 0, 120) : null,
            'criteria'            => $criteria,
            'visibility'          => $visibility,
            'permanent'           => array_key_exists('permanent', $data) ? (! empty($data['permanent']) ? 1 : 0) : 1,
            'expiry_policy'       => isset($data['expiry_policy']) && $data['expiry_policy'] !== '' ? mb_substr((string) $data['expiry_policy'], 0, 20) : null,
            'sort_order'          => (int) ($data['sort_order'] ?? 0),
            'include_descendants' => ! empty($data['include_descendants']) ? 1 : 0,
            'status'              => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? (string) ($data['status'] ?? 'active') : 'active',
            'updated_at'          => $this->clock->nowUtcString(),
        ];

        try {
            if ($existing !== null) {
                $this->db->table('badges')->where('id', $existing['id'])->update($payload);

                return Result::ok(['badge_id' => $existing['id'], 'code' => $code, 'updated' => true]);
            }

            $id = Uuid::v7();
            $this->db->table('badges')->insert($payload + [
                'id'              => $id,
                'organization_id' => $organizationId,
                'group_id'        => $groupId,
                'code'            => $code,
                'created_at'      => $this->clock->nowUtcString(),
            ]);

            return Result::created(['badge_id' => $id, 'code' => $code, 'group_id' => $groupId]);
        } catch (Throwable) {
            return Result::fail('BADGE_SAVE_FAILED', 'gamification.badge_save_failed', 500);
        }
    }

    /**
     * List badge definitions. Org-wide (every row) by default; when $groupId is
     * given, returns the group's visible set (own + org-wide + ancestor rows
     * flagged include_descendants) collapsed MOST-SPECIFIC-WINS per code.
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $organizationId, bool $activeOnly = false, ?string $groupId = null): array
    {
        $q = $this->db->table('badges')->where('organization_id', $organizationId);
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

        $rows = $q->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();

        return $gid !== null && $this->groupScope !== null
            ? $this->collapseMostSpecific($rows, $gid, $this->groupScope->ancestors($gid))
            : $rows;
    }

    /**
     * Read one badge definition by code. Within a group scope, resolution is
     * most-specific-wins over the full ancestor chain (self → ancestor-with-
     * descendants → org-wide); without a group it reads the org-wide row.
     */
    public function show(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        if ($gid !== null && $this->groupScope !== null) {
            $row = $this->groupScope->resolveByCode('badges', $organizationId, $code, $gid, []);
        } else {
            $q = $this->db->table('badges')
                ->where('organization_id', $organizationId)->where('code', $code);
            $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
            $row = $q->get()->getRowArray();
        }

        if ($row === null) {
            return Result::notFound('gamification.badge_not_found', 'BADGE_NOT_FOUND');
        }

        $row['permanent']           = (bool) $row['permanent'];
        $row['include_descendants'] = (bool) ($row['include_descendants'] ?? 0);
        if (isset($row['criteria']) && is_string($row['criteria']) && $row['criteria'] !== '') {
            $row['criteria'] = json_decode($row['criteria'], true) ?? $row['criteria'];
        }

        return Result::ok($row);
    }

    /**
     * PARTIAL update of an existing badge definition. Unlike {@see define()}
     * (a full-replace upsert), this merges $changes over the stored row so
     * omitted fields keep their current values. `code` and `group_id` are
     * immutable (they identify the row); NOT_FOUND when no such row exists in
     * the given scope. Delegates to define() for validation + persistence.
     *
     * @param array<string,mixed> $changes any subset of the define() fields
     */
    public function update(string $organizationId, string $code, array $changes, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $q   = $this->db->table('badges')
            ->where('organization_id', $organizationId)->where('code', $code);
        $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
        $existing = $q->get()->getRowArray();
        if ($existing === null) {
            return Result::notFound('gamification.badge_not_found', 'BADGE_NOT_FOUND');
        }

        $merged                = array_merge($existing, $changes);
        $merged['code']        = $code;   // immutable identity
        $merged['group_id']    = $gid;    // immutable scope

        return $this->define($organizationId, $merged);
    }

    /** Soft-delete a badge definition (status=inactive). Awards are untouched. */
    public function disable(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $q = $this->db->table('badges')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null || $groupId === '' ? $q->where('group_id', null) : $q->where('group_id', (string) $groupId);
        $badge = $q->get()->getRowArray();
        if ($badge === null) {
            return Result::notFound('gamification.badge_not_found', 'BADGE_NOT_FOUND');
        }
        $this->db->table('badges')->where('id', $badge['id'])->update([
            'status'     => 'inactive',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['code' => $code, 'status' => 'inactive']);
    }

    // ---- Award lifecycle ----------------------------------------------------

    /**
     * Manually grant a badge to a subject (user or group). Idempotent on the
     * (badge_id, subject_id, season_id) unique key; re-granting a revoked award
     * re-activates it rather than erroring.
     *
     * @param array<string,mixed> $opts season_id?, source_ref?, subject_type?,
     *                                   visibility?, group_id? (definition scope)
     */
    public function grant(string $organizationId, string $badgeCode, string $subjectId, array $opts = []): Result
    {
        if ($subjectId === '') {
            return Result::fail('BAD_SUBJECT', 'gamification.bad_subject', 422);
        }

        $scopeGroup = isset($opts['group_id']) && $opts['group_id'] !== '' ? (string) $opts['group_id'] : null;
        $show       = $this->show($organizationId, $badgeCode, $scopeGroup);
        if (! $show->ok) {
            return $show; // BADGE_NOT_FOUND
        }
        $badge = $show->data;
        if (($badge['status'] ?? 'active') !== 'active') {
            return Result::fail('BADGE_INACTIVE', 'gamification.badge_inactive', 422);
        }

        $seasonId  = isset($opts['season_id']) && $opts['season_id'] !== '' ? (string) $opts['season_id'] : null;
        $sourceRef = isset($opts['source_ref']) && $opts['source_ref'] !== '' ? (string) $opts['source_ref'] : null;
        $now       = $this->clock->nowUtcString();

        // Re-activate a prior revoked award for the same idempotency triple.
        $existing = $this->db->table('badge_awards')
            ->where('badge_id', $badge['id'])
            ->where('subject_id', $subjectId)
            ->where('season_id', $seasonId)
            ->get()->getRowArray();
        if ($existing !== null) {
            if (($existing['state'] ?? 'awarded') === 'awarded') {
                return Result::ok(['badge_award_id' => $existing['id'], 'already_awarded' => true]);
            }
            $this->db->table('badge_awards')->where('id', $existing['id'])->update([
                'state'      => 'awarded',
                'revoked_at' => null,
                'awarded_at' => $now,
            ]);

            return Result::ok(['badge_award_id' => $existing['id'], 'reinstated' => true]);
        }

        $id = Uuid::v7();
        try {
            $this->db->table('badge_awards')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'badge_id'        => $badge['id'],
                'subject_id'      => $subjectId,
                'season_id'       => $seasonId,
                'source_ref'      => $sourceRef,
                'state'           => 'awarded',
                'visibility'      => (string) ($opts['visibility'] ?? $badge['visibility'] ?? 'public'),
                'awarded_at'      => $now,
            ]);
        } catch (Throwable) {
            return Result::fail('BADGE_AWARD_FAILED', 'gamification.badge_award_failed', 500);
        }

        return Result::created(['badge_award_id' => $id, 'badge_code' => $badgeCode, 'subject_id' => $subjectId]);
    }

    /**
     * Revoke a granted badge (state=revoked). Never hard-deletes: the award row
     * is retained for auditability, matching the immutable-ledger convention.
     */
    public function revoke(string $organizationId, string $badgeCode, string $subjectId, ?string $seasonId = null): Result
    {
        $badge = $this->db->table('badges')
            ->where('organization_id', $organizationId)->where('code', $badgeCode)
            ->get()->getRowArray();
        if ($badge === null) {
            return Result::notFound('gamification.badge_not_found', 'BADGE_NOT_FOUND');
        }

        $sid   = $seasonId !== null && $seasonId !== '' ? $seasonId : null;
        $award = $this->db->table('badge_awards')
            ->where('badge_id', $badge['id'])
            ->where('subject_id', $subjectId)
            ->where('season_id', $sid)
            ->get()->getRowArray();
        if ($award === null) {
            return Result::notFound('gamification.badge_award_not_found', 'BADGE_AWARD_NOT_FOUND');
        }
        if (($award['state'] ?? 'awarded') === 'revoked') {
            return Result::ok(['badge_award_id' => $award['id'], 'state' => 'revoked'], 200, ['already_revoked' => true]);
        }

        $this->db->table('badge_awards')->where('id', $award['id'])->update([
            'state'      => 'revoked',
            'revoked_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['badge_award_id' => $award['id'], 'state' => 'revoked']);
    }

    /**
     * Badge awards held by a subject (default only active/awarded).
     *
     * @return list<array<string,mixed>>
     */
    public function awardsForSubject(string $organizationId, string $subjectId, bool $includeRevoked = false): array
    {
        $q = $this->db->table('badge_awards ba')
            ->select('ba.id, ba.badge_id, ba.subject_id, ba.season_id, ba.state, ba.visibility, ba.awarded_at, ba.revoked_at, b.code, b.name, b.icon')
            ->join('badges b', 'b.id = ba.badge_id')
            ->where('ba.organization_id', $organizationId)
            ->where('ba.subject_id', $subjectId);
        if (! $includeRevoked) {
            $q->where('ba.state', 'awarded');
        }

        return $q->orderBy('ba.awarded_at', 'DESC')->get()->getResultArray();
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * Collapse a union of scoped rows to one per code, most-specific-wins: self
     * beats nearer ancestor beats farther ancestor beats org-wide (NULL).
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
            return [(int) $a['sort_order'], $a['name']] <=> [(int) $b['sort_order'], $b['name']];
        });

        return $out;
    }
}
