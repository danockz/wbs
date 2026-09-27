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
 * Admin management of point rules (gamification_rules), including declarative
 * multipliers. Adaptation follow-up: rules must be creatable/editable by an
 * admin.
 *
 * Rules are VERSIONED and effectively immutable once live: "editing" a rule
 * supersedes the current active version with a new version (the old row is
 * retired via effective_to / status), so historical awards always reference the
 * exact rule version that produced them. This preserves the append-only,
 * tamper-evident guarantees the ledger depends on.
 */
final class RuleService
{
    private const PERIODS = ['day', 'week', 'month', 'season'];

    private const POINT_MODES = ['fixed', 'variable', 'formula'];

    /** Multi-group credit modes (design doc B.4.1); default per_group. */
    private const GROUP_CREDIT_MODES = ['per_group', 'individual_once'];

    private const PHASES = ['win', 'build', 'send', 'general'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Create a brand-new rule code at version 1.
     *
     * @param array<string,mixed> $data code, points, event_type, per_period_cap,
     *          period, cooldown_seconds, requires_review, explanation,
     *          multipliers, group_id, effective_from
     */
    public function create(string $organizationId, array $data): Result
    {
        $code      = trim((string) ($data['code'] ?? ''));
        $eventType = trim((string) ($data['event_type'] ?? ''));
        if ($code === '' || $eventType === '') {
            return Result::fail('BAD_RULE', 'gamification.rule_code_event_required', 422);
        }
        if (! isset($data['points']) || ! is_numeric($data['points'])) {
            return Result::fail('BAD_POINTS', 'gamification.rule_points_required', 422);
        }
        $periodErr = $this->validatePeriod($data);
        if ($periodErr !== null) {
            return $periodErr;
        }
        $multErr = $this->validateMultipliers($data['multipliers'] ?? null);
        if ($multErr !== null) {
            return $multErr;
        }
        $activityErr = $this->validateActivity($data);
        if ($activityErr !== null) {
            return $activityErr;
        }

        $exists = $this->db->table('gamification_rules')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->countAllResults() > 0;
        if ($exists) {
            return Result::fail('RULE_EXISTS', 'gamification.rule_exists', 409, ['code' => $code]);
        }

        $id = Uuid::v7();
        try {
            $this->db->table('gamification_rules')->insert($this->row($organizationId, $code, 1, $data));
        } catch (Throwable) {
            return Result::fail('RULE_CREATE_FAILED', 'gamification.rule_create_failed', 500);
        }

        return Result::created(['rule_id' => $id, 'code' => $code, 'version' => 1]);
    }

    /**
     * Supersede the active version of a rule with a new version (immutable edit).
     * The prior version is retired (status=superseded, effective_to=now).
     *
     * @param array<string,mixed> $data any rule fields to change
     */
    public function update(string $organizationId, string $code, array $data): Result
    {
        $current = $this->db->table('gamification_rules')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->orderBy('version', 'DESC')->get()->getRowArray();
        if ($current === null) {
            return Result::notFound('gamification.rule_not_found', 'RULE_NOT_FOUND');
        }

        // Merge current values with the requested changes.
        $merged = array_merge($current, $data);
        $periodErr = $this->validatePeriod($merged);
        if ($periodErr !== null) {
            return $periodErr;
        }
        $multErr = $this->validateMultipliers($merged['multipliers'] ?? null);
        if ($multErr !== null) {
            return $multErr;
        }
        $activityErr = $this->validateActivity($merged);
        if ($activityErr !== null) {
            return $activityErr;
        }

        $nextVersion = (int) $current['version'] + 1;
        $now         = $this->clock->nowUtcString();

        $this->db->transStart();
        // Retire the current version.
        $this->db->table('gamification_rules')->where('id', $current['id'])->update([
            'status'       => 'superseded',
            'effective_to' => $now,
        ]);
        // Insert the new active version.
        $this->db->table('gamification_rules')->insert(
            $this->row($organizationId, $code, $nextVersion, $merged),
        );
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return Result::fail('RULE_UPDATE_FAILED', 'gamification.rule_update_failed', 500);
        }

        return Result::ok(['code' => $code, 'version' => $nextVersion, 'superseded' => (int) $current['version']]);
    }

    /** Deactivate a rule's active version (no new awards; history untouched). */
    public function disable(string $organizationId, string $code): Result
    {
        $current = $this->activeRuleRow($organizationId, $code);
        if ($current === null) {
            return Result::notFound('gamification.rule_not_found', 'RULE_NOT_FOUND');
        }
        $this->db->table('gamification_rules')->where('id', $current['id'])->update([
            'status'       => 'inactive',
            'effective_to' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['code' => $code, 'status' => 'inactive']);
    }

    /** List rules (latest version per code by default). @return list<array<string,mixed>> */
    public function list(string $organizationId, bool $activeOnly = false): array
    {
        $q = $this->db->table('gamification_rules')->where('organization_id', $organizationId);
        if ($activeOnly) {
            $q->where('status', 'active');
        }

        return $q->orderBy('code', 'ASC')->orderBy('version', 'DESC')->get()->getResultArray();
    }

    /**
     * Read a single activity/rule by code: the current (highest) version plus
     * its full version history (newest first). The multipliers JSON is decoded
     * for convenience. 404 when the code is unknown for the org.
     */
    public function show(string $organizationId, string $code): Result
    {
        $versions = $this->db->table('gamification_rules')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->orderBy('version', 'DESC')->get()->getResultArray();
        if ($versions === []) {
            return Result::notFound('gamification.rule_not_found', 'RULE_NOT_FOUND');
        }

        $current = $versions[0];
        if (isset($current['multipliers']) && is_string($current['multipliers'])) {
            $current['multipliers'] = json_decode($current['multipliers'], true) ?? [];
        }

        return Result::ok([
            'code'     => $code,
            'current'  => $current,
            'versions' => array_map(static fn (array $v): array => [
                'version'        => (int) $v['version'],
                'status'         => $v['status'],
                'points'         => (int) $v['points'],
                'effective_from' => $v['effective_from'],
                'effective_to'   => $v['effective_to'],
            ], $versions),
        ]);
    }

    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function row(string $organizationId, string $code, int $version, array $data): array
    {
        $multipliers = $data['multipliers'] ?? null;
        if (is_array($multipliers)) {
            $multipliers = json_encode(array_values($multipliers));
        }

        $intOrNull = static fn (string $k): ?int => isset($data[$k]) && $data[$k] !== '' && $data[$k] !== null ? (int) $data[$k] : null;

        return [
            'id'                 => Uuid::v7(),
            'organization_id'    => $organizationId,
            'group_id'           => $data['group_id'] ?? null,
            // Mirror the sibling catalogs (activity_categories / follow_up_types):
            // ancestor rows inherit to descendants by default (1) unless opted out.
            'include_descendants' => array_key_exists('include_descendants', $data) ? (! empty($data['include_descendants']) ? 1 : 0) : 1,
            'group_credit_mode'  => in_array($data['group_credit_mode'] ?? 'per_group', self::GROUP_CREDIT_MODES, true) ? (string) $data['group_credit_mode'] : 'per_group',
            'category_id'        => isset($data['category_id']) && $data['category_id'] !== '' ? (string) $data['category_id'] : null,
            'phase'              => in_array($data['phase'] ?? 'general', self::PHASES, true) ? (string) $data['phase'] : 'general',
            // Optional journey stage link (Option D). Advisory only.
            'stage_code'         => isset($data['stage_code']) && $data['stage_code'] !== '' ? mb_substr((string) $data['stage_code'], 0, 60) : null,
            'activity_name'      => isset($data['activity_name']) ? mb_substr((string) $data['activity_name'], 0, 150) : null,
            'icon'               => isset($data['icon']) ? mb_substr((string) $data['icon'], 0, 120) : null,
            'color'              => isset($data['color']) ? mb_substr((string) $data['color'], 0, 20) : null,
            'sort_order'         => (int) ($data['sort_order'] ?? 0),
            'code'               => $code,
            'version'            => $version,
            'points'             => (int) $data['points'],
            'point_mode'         => in_array($data['point_mode'] ?? 'fixed', self::POINT_MODES, true) ? (string) $data['point_mode'] : 'fixed',
            'point_formula'      => isset($data['point_formula']) && $data['point_formula'] !== '' ? mb_substr((string) $data['point_formula'], 0, 255) : null,
            'min_points'         => $intOrNull('min_points'),
            'max_points'         => $intOrNull('max_points'),
            'daily_limit'        => $intOrNull('daily_limit'),
            'weekly_limit'       => $intOrNull('weekly_limit'),
            'monthly_limit'      => $intOrNull('monthly_limit'),
            'event_type'         => (string) $data['event_type'],
            'per_period_cap'     => $intOrNull('per_period_cap'),
            'period'             => $data['period'] ?? null,
            'cooldown_seconds'   => $intOrNull('cooldown_seconds'),
            'requires_review'    => ! empty($data['requires_review']) ? 1 : 0,
            'approval_role_code' => isset($data['approval_role_code']) && $data['approval_role_code'] !== '' ? mb_substr((string) $data['approval_role_code'], 0, 80) : null,
            'effective_from'     => $data['effective_from'] ?? $this->clock->nowUtcString(),
            'effective_to'       => null,
            'status'             => 'active',
            'explanation'        => isset($data['explanation']) ? mb_substr((string) $data['explanation'], 0, 255) : null,
            'multipliers'        => $multipliers,
            'created_at'         => $this->clock->nowUtcString(),
        ];
    }

    /**
     * Validate the configurable-activity fields: phase, point_mode, formula
     * (must parse safely over base_points + event fields), and min/max ordering.
     *
     * @param array<string,mixed> $data
     */
    private function validateActivity(array $data): ?Result
    {
        $phase = $data['phase'] ?? null;
        if ($phase !== null && $phase !== '' && ! in_array($phase, self::PHASES, true)) {
            return Result::fail('BAD_PHASE', 'gamification.bad_phase', 422, ['allowed' => self::PHASES]);
        }

        $mode = $data['point_mode'] ?? 'fixed';
        if ($mode !== '' && ! in_array($mode, self::POINT_MODES, true)) {
            return Result::fail('BAD_POINT_MODE', 'gamification.bad_point_mode', 422, ['allowed' => self::POINT_MODES]);
        }

        if ($mode === 'formula') {
            $formula = trim((string) ($data['point_formula'] ?? ''));
            if ($formula === '') {
                return Result::fail('FORMULA_REQUIRED', 'gamification.formula_required', 422);
            }
            // The formula must PARSE and use only whitelisted ops/functions.
            // Variable names it references (beyond base_points) resolve from
            // event data at award time, so we supply a sample map covering every
            // identifier and require the expression to evaluate without error.
            if ((new FormulaEvaluator())->evaluate($formula, $this->sampleVars($formula)) === null) {
                return Result::fail('BAD_FORMULA', 'gamification.bad_formula', 422);
            }
        }

        $min = isset($data['min_points']) && $data['min_points'] !== '' && $data['min_points'] !== null ? (int) $data['min_points'] : null;
        $max = isset($data['max_points']) && $data['max_points'] !== '' && $data['max_points'] !== null ? (int) $data['max_points'] : null;
        if ($min !== null && $max !== null && $min > $max) {
            return Result::fail('BAD_POINT_BOUNDS', 'gamification.bad_point_bounds', 422);
        }

        return null;
    }

    /**
     * Build a sample variable map (all 1.0) covering every identifier used in a
     * formula, so isValid-style evaluation confirms the expression parses and
     * runs regardless of which event fields it references.
     *
     * @return array<string,float>
     */
    private function sampleVars(string $formula): array
    {
        $vars = ['base_points' => 1.0];
        if (preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $formula, $m)) {
            $funcs = ['min', 'max', 'floor', 'ceil', 'round', 'abs'];
            foreach ($m[0] as $ident) {
                $lc = strtolower($ident);
                if (! in_array($lc, $funcs, true)) {
                    $vars[$lc] = 1.0;
                }
            }
        }

        return $vars;
    }

    private function validatePeriod(array $data): ?Result
    {
        $period = $data['period'] ?? null;
        if ($period !== null && $period !== '' && ! in_array($period, self::PERIODS, true)) {
            return Result::fail('BAD_PERIOD', 'gamification.bad_period', 422, ['allowed' => self::PERIODS]);
        }

        return null;
    }

    /**
     * Validate a declarative multipliers spec — same safe shape PointsEngine
     * enforces: a list of {factor: positive number, when?: {field, op, value}}.
     * No expressions, no eval.
     */
    private function validateMultipliers(mixed $spec): ?Result
    {
        if ($spec === null || $spec === '') {
            return null;
        }
        if (is_string($spec)) {
            $spec = json_decode($spec, true);
        }
        if (! is_array($spec)) {
            return Result::fail('BAD_MULTIPLIERS', 'gamification.bad_multipliers', 422);
        }
        $allowedOps = ['==', '=', '!=', '>', '>=', '<', '<=', 'in'];
        foreach ($spec as $m) {
            if (! is_array($m) || ! isset($m['factor']) || ! is_numeric($m['factor']) || (float) $m['factor'] <= 0) {
                return Result::fail('BAD_MULTIPLIERS', 'gamification.bad_multiplier_factor', 422);
            }
            if (isset($m['when'])) {
                $when = $m['when'];
                if (! is_array($when) || ! isset($when['field'])
                    || (isset($when['op']) && ! in_array($when['op'], $allowedOps, true))) {
                    return Result::fail('BAD_MULTIPLIERS', 'gamification.bad_multiplier_condition', 422);
                }
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function activeRuleRow(string $organizationId, string $code): ?array
    {
        return $this->db->table('gamification_rules')
            ->where('organization_id', $organizationId)->where('code', $code)->where('status', 'active')
            ->orderBy('version', 'DESC')->get()->getRowArray() ?: null;
    }

    /**
     * The owning group of a rule (latest version by code), for the controller's
     * authoritative per-group authorization on rule writes (update/disable).
     * Returns null when the code is unknown; a returned string|null group_id is
     * the target for {@see BaseController::authorizeGroupScope()} (null =
     * org-wide rule, only an org-wide grant may edit it).
     *
     * @return array{exists: bool, group_id: ?string}
     */
    public function ruleGroupScope(string $organizationId, string $code): array
    {
        $row = $this->db->table('gamification_rules')
            ->select('group_id')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->orderBy('version', 'DESC')->get()->getRowArray();
        if ($row === null) {
            return ['exists' => false, 'group_id' => null];
        }
        $gid = isset($row['group_id']) && $row['group_id'] !== '' ? (string) $row['group_id'] : null;

        return ['exists' => true, 'group_id' => $gid];
    }
}
