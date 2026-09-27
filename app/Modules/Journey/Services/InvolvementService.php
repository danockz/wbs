<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * INVOLVEMENT-based triage engine for the Membership Journey pipeline.
 *
 * Supersedes the placeholder time-in-stage triage: a member's HOT / WARM / COLD
 * band is derived from how INVOLVED they are — recency of activity, participation
 * rate against a configured target, and the quantum of work they (and their
 * disciples) have produced — classified by explicit ORDERED RULE BANDS, not a
 * single weighted score. See docs/TODO_INVOLVEMENT_BASED_TRIAGE.md.
 *
 * THREE involvement inputs (per the spec):
 *   1. last activity   — recency of the member's most recent participation;
 *   2. participation   — activities in the window / a configured target;
 *   3. quantum of work — a configurable weighted composite of sponsorship count
 *                        (Referrals), money given (Contributions) and points
 *                        (gamification point_ledger), PLUS a rolled-up share of
 *                        the member's downline/mentees' own quantum (a fruitful
 *                        discipler is "involved" even if their direct activity is
 *                        moderate).
 *
 * RESOURCE-LIGHT (standing constraint): involvement is NOT recomputed by fanning
 * out across four modules on every pipeline render. It is aggregated once into a
 * materialized snapshot (member_involvement_snapshots), refreshed on a journey
 * transition (this service is a JourneyTransitionListener) or by a batch
 * recompute; the pipeline / roster hot path then reads pre-classified bands and
 * pre-computed figures with plain grouped aggregate queries.
 *
 * CONFIG is hierarchical and default-safe (EffectiveConfigResolver, walking the
 * group's ancestry to the org root): window length, per-stage/group activity
 * target, band thresholds, and the quantum weights are all configurable, with
 * sensible built-in defaults so the feature is safe when nothing is set. The
 * org-wide context (no group) resolves against the org-root group so an org-level
 * default applies there too.
 *
 * Point computation is arithmetic only — no eval() (standing constraint).
 */
final class InvolvementService implements JourneyTransitionListener, InvolvementTriagePort
{
    /** hot|warm|cold, hottest-first. */
    public const BANDS = ['hot', 'warm', 'cold'];

    /** Config capability keys (resolved via EffectiveConfigResolver). */
    public const CAP_ENABLED     = 'journey.involvement.enabled';
    public const CAP_WINDOW      = 'journey.involvement.window_days';
    public const CAP_TARGET      = 'journey.involvement.activity_target';
    public const CAP_THRESHOLDS  = 'journey.involvement.band_thresholds';
    public const CAP_WEIGHTS     = 'journey.involvement.quantum_weights';

    /**
     * M5 dormancy capabilities (hierarchical, DEFAULT OFF). `dormancy.enabled`
     * switches the mark-dormant sweep on for a context; `dormancy.after_days` is
     * how long a member must sit `cold` with no activity before being marked
     * dormant (defaults to twice the involvement window, floored at 60 days).
     */
    public const CAP_DORMANCY_ENABLED = 'journey.dormancy.enabled';
    public const CAP_DORMANCY_DAYS    = 'journey.dormancy.after_days';

    /** Floor for the dormancy threshold, so a tiny window can't lapse members instantly. */
    public const MIN_DORMANCY_DAYS = 60;

    /** Default rolling window; the spec's honoured minimum is 30 days (>= a month). */
    private const DEFAULT_WINDOW_DAYS = 90;
    // Public so the admin config console can render the same clamp bounds it
    // enforces (single source of truth for the allowed range).
    public const MIN_WINDOW_DAYS = 30;
    public const MAX_WINDOW_DAYS = 365;

    /** Default expected activities per window (participation denominator). */
    private const DEFAULT_ACTIVITY_TARGET = 4;
    public const MAX_ACTIVITY_TARGET      = 100;

    /**
     * Default quantum weights. quantum figures are integers; giving is counted in
     * MAJOR units (minor / 100) so a currency amount doesn't dwarf the others.
     * downline_share_pct = the % of each disciple's own quantum that rolls up.
     */
    private const DEFAULT_WEIGHTS = [
        'points'             => 1,   // 1 point of gamification = 1 quantum
        'per_sponsorship'    => 50,  // each active recruit
        'per_giving_major'   => 1,   // each major currency unit given
        'downline_share_pct' => 25,  // 25% of a disciple's own quantum rolls up
    ];

    /**
     * Default band thresholds. Ordered rule evaluation (see classify()):
     *   COLD if no activity in window OR participation below cold_floor_bps;
     *   HOT  if recent activity AND (participation >= hot_participation_bps
     *        OR effective quantum >= hot_quantum);
     *   WARM otherwise.
     * "recent activity" = last activity within recent_activity_days.
     */
    private const DEFAULT_THRESHOLDS = [
        'cold_floor_bps'         => 2500,  // < 25% participation and low quantum => cold
        'cold_quantum'           => 100,   // …unless quantum reaches this (keeps fruitful disciplers off cold)
        'hot_participation_bps'  => 7500,  // >= 75% participation => hot (if recent)
        'hot_quantum'            => 500,   // …or this much quantum => hot (if recent)
        'recent_activity_days'   => 30,    // activity within N days counts as "recent"
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?GroupScopeResolver $groupScope = null,
        // Config + source aggregators are injected as callables so the engine
        // stays decoupled and unit-testable without booting four modules. Each is
        // optional; a missing source contributes zero (default-safe).
        private readonly ?ConfigResolverPort $config = null,
        private readonly ?InvolvementSourcePort $sources = null,
        // Optional write port: persist involvement config through the platform's
        // single hierarchical config store. Null in unit tests that only read.
        private readonly ?ConfigWriterPort $configWriter = null,
    ) {
    }

    // -------------------------------------------------------------------------
    // Transition listener — keep the snapshot fresh on every journey move.
    // -------------------------------------------------------------------------

    /**
     * A committed journey transition means the member's context (and possibly
     * their involvement-relevant history) changed; refresh their snapshot. MUST
     * NOT throw (the interface contract) — a snapshot refresh can never undo a
     * durable stage change.
     *
     * @param array<string,mixed> $event
     */
    public function onTransition(array $event): void
    {
        try {
            $org   = (string) ($event['organization_id'] ?? '');
            $user  = (string) ($event['user_id'] ?? '');
            if ($org === '' || $user === '') {
                return;
            }
            $group = isset($event['group_id']) && $event['group_id'] !== '' ? (string) $event['group_id'] : null;
            $this->refreshForMember($org, $user, $group, [
                'stage_code'  => (string) ($event['to_stage'] ?? ''),
                'stage_phase' => (string) ($event['to_phase'] ?? ''),
            ]);
        } catch (Throwable) {
            // Swallow — snapshot freshness is best-effort; a batch recompute or
            // the next transition will reconcile.
        }
    }

    // -------------------------------------------------------------------------
    // Snapshot refresh (the write path).
    // -------------------------------------------------------------------------

    /**
     * Recompute and persist one member's involvement snapshot for a context.
     *
     * @param array{stage_code?:string,stage_phase?:string} $stage optional current
     *        stage hint (from the transition event); when omitted it is read from
     *        member_journeys.
     * @return Result data: the computed snapshot row.
     */
    public function refreshForMember(string $organizationId, string $userId, ?string $groupId = null, array $stage = []): Result
    {
        $cfg = $this->effectiveConfig($organizationId, $groupId);

        // Resolve the current stage if not supplied.
        $stageCode  = (string) ($stage['stage_code'] ?? '');
        $stagePhase = (string) ($stage['stage_phase'] ?? '');
        if ($stageCode === '') {
            $j = $this->db->table('member_journeys')
                ->select('stage_code, stage_phase')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId);
            $groupId === null ? $j->where('group_id', null) : $j->where('group_id', $groupId);
            $row = $j->get()->getRowArray();
            if ($row !== null) {
                $stageCode  = (string) ($row['stage_code'] ?? '');
                $stagePhase = (string) ($row['stage_phase'] ?? '');
            }
        }

        $metrics = $this->gatherMetrics($organizationId, $userId, $groupId, $cfg['window_days']);
        $snap    = $this->compose($metrics, $cfg);

        $now = $this->clock->nowUtcString();
        $payload = [
            'organization_id'   => $organizationId,
            'user_id'           => $userId,
            'group_id'          => $groupId,
            'stage_code'        => $stageCode,
            'stage_phase'       => $stagePhase,
            'last_activity_at'  => $metrics['last_activity_at'],
            'activity_count'    => $metrics['activity_count'],
            'activity_target'   => $cfg['activity_target'],
            'participation_bps' => $snap['participation_bps'],
            'sponsorship_count' => $metrics['sponsorship_count'],
            'giving_minor'      => $metrics['giving_minor'],
            'points'            => $metrics['points'],
            'own_quantum'       => $snap['own_quantum'],
            'downline_quantum'  => $snap['downline_quantum'],
            'quantum'           => $snap['quantum'],
            'band'              => $snap['band'],
            'window_days'       => $cfg['window_days'],
            'computed_at'       => $now,
        ];

        $this->upsert($organizationId, $userId, $groupId, $payload);

        return Result::ok($payload);
    }

    /**
     * Batch recompute every active journey in a context. Bounded read of the
     * (org, group) journeys, then a per-member refresh. Intended for a scheduled
     * job or an admin "recompute" action — NOT the render hot path.
     *
     * @return Result data: {refreshed:int}
     */
    public function recomputeContext(string $organizationId, ?string $groupId = null, int $limit = 5000): Result
    {
        $q = $this->db->table('member_journeys')
            ->select('user_id, stage_code, stage_phase')
            ->where('organization_id', $organizationId)
            ->where('status', 'active');
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $rows = $q->limit(max(1, min(20000, $limit)))->get()->getResultArray();

        $n = 0;
        foreach ($rows as $r) {
            $this->refreshForMember($organizationId, (string) $r['user_id'], $groupId, [
                'stage_code'  => (string) ($r['stage_code'] ?? ''),
                'stage_phase' => (string) ($r['stage_phase'] ?? ''),
            ]);
            $n++;
        }

        return Result::ok(['refreshed' => $n]);
    }

    /**
     * M5 — dormancy sweep. Config-gated (DEFAULT OFF) and resource-light: it
     * reads the ALREADY-materialised involvement snapshots (no per-member
     * recompute) and, for members whose band is `cold` AND whose last activity is
     * older than the dormancy threshold, flips their `member_journeys` dormancy
     * axis to `dormant` (stamping `dormant_since`). A member who has since become
     * active/warm/hot again is RE-ENGAGED (`dormant` → `active`, watermark
     * cleared). The hard `status` lifecycle is untouched — dormancy is a soft,
     * reversible re-engagement signal, not a deactivation.
     *
     * Idempotent: a member already in the correct dormancy state is not rewritten
     * (the update is state-guarded), so `marked`/`reengaged` count only real
     * transitions and a second pass in the same window changes nothing.
     *
     * @return array{scanned:int, marked:int, reengaged:int, skipped_gated:int}
     */
    public function processDormancy(?string $organizationId, ?string $groupId = null, int $limit = 5000): array
    {
        $limit = max(1, min(20000, $limit));

        // Which org contexts to sweep. When an org is given we sweep it; when not,
        // we sweep every org that has snapshots (bounded by $limit rows overall).
        $q = $this->db->table('member_involvement_snapshots')
            ->select('organization_id, user_id, group_id, band, last_activity_at');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        if ($groupId !== null) {
            $q->where('group_id', $groupId);
        }
        $rows = $q->limit($limit)->get()->getResultArray();

        $now       = $this->clock->nowUtcString();
        $nowTs     = strtotime($now) ?: time();
        $scanned   = 0;
        $marked    = 0;
        $reengaged = 0;
        $skipped   = 0;

        // Cache the enabled + threshold decision per (org, group) context.
        $ctxCache = [];

        foreach ($rows as $r) {
            $scanned++;
            $org      = (string) $r['organization_id'];
            $ctxGroup = $r['group_id'] !== null ? (string) $r['group_id'] : null;
            $ctxKey   = $org . '|' . ($ctxGroup ?? '');

            if (! isset($ctxCache[$ctxKey])) {
                $enabled = $this->isDormancyEnabled($org, $ctxGroup);
                $ctxCache[$ctxKey] = [
                    'enabled' => $enabled,
                    'days'    => $enabled ? $this->dormancyAfterDays($org, $ctxGroup) : 0,
                ];
            }
            if (! $ctxCache[$ctxKey]['enabled']) {
                $skipped++;
                continue;
            }

            $band        = (string) $r['band'];
            $lastActive  = (string) ($r['last_activity_at'] ?? '');
            $cutoff      = date('Y-m-d H:i:s', $nowTs - ($ctxCache[$ctxKey]['days'] * 86400));

            // Dormant when cold AND (never active, or last activity before cutoff).
            $isDormant = $band === 'cold' && ($lastActive === '' || $lastActive <= $cutoff);

            if ($isDormant) {
                $marked += $this->setDormancy($org, (string) $r['user_id'], $ctxGroup, 'dormant', $now);
            } else {
                // Re-engaged: warm/hot, or recent activity while cold.
                $reengaged += $this->setDormancy($org, (string) $r['user_id'], $ctxGroup, 'active', $now);
            }
        }

        return ['scanned' => $scanned, 'marked' => $marked, 'reengaged' => $reengaged, 'skipped_gated' => $skipped];
    }

    /**
     * Flip a member journey's dormancy axis, guarded on the current value so an
     * unchanged row is never rewritten (keeps the transition counts honest and
     * the pass idempotent). Returns 1 if a real transition happened, else 0.
     */
    private function setDormancy(string $organizationId, string $userId, ?string $groupId, string $to, string $now): int
    {
        $q = $this->db->table('member_journeys')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('dormancy_state !=', $to);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);

        $q->update([
            'dormancy_state' => $to,
            'dormant_since'  => $to === 'dormant' ? $now : null,
            'updated_at'     => $now,
        ]);

        return (int) $this->db->affectedRows() > 0 ? 1 : 0;
    }

    /** Dormancy feature gate for a context (hierarchical, default OFF). */
    public function isDormancyEnabled(string $organizationId, ?string $groupId): bool
    {
        $ctxGroup = $groupId ?? $this->orgRootGroup($organizationId);
        if ($ctxGroup === null || $this->config === null) {
            return false;
        }
        $v = $this->config->value($ctxGroup, self::CAP_DORMANCY_ENABLED);
        if (is_array($v)) {
            $v = $v['value'] ?? null;
        }

        return $v === true || $v === 1 || $v === '1'
            || (is_string($v) && in_array(strtolower($v), ['true', 'on', 'yes'], true));
    }

    /** Configured dormancy threshold in days (floored), default = 2× window. */
    private function dormancyAfterDays(string $organizationId, ?string $groupId): int
    {
        $ctxGroup = $groupId ?? $this->orgRootGroup($organizationId);
        $default  = 2 * $this->effectiveConfig($organizationId, $groupId)['window_days'];
        $days     = (int) $this->cfgScalar($ctxGroup, self::CAP_DORMANCY_DAYS, $default);

        return max(self::MIN_DORMANCY_DAYS, $days);
    }

    // -------------------------------------------------------------------------
    // Read side (the pipeline / roster hot path — snapshot reads only).
    // -------------------------------------------------------------------------

    /**
     * Whether involvement-based triage is switched ON for a context. Hierarchical
     * config, DEFAULT OFF (the standing feature-gating rule) — when off, callers
     * keep the legacy time-in-stage triage. The org-wide context resolves against
     * the org-root group so an org-level default can enable it everywhere.
     */
    public function isEnabled(string $organizationId, ?string $groupId): bool
    {
        $ctxGroup = $groupId ?? $this->orgRootGroup($organizationId);
        if ($ctxGroup === null || $this->config === null) {
            return false;
        }
        $v = $this->config->value($ctxGroup, self::CAP_ENABLED);
        if (is_array($v)) {
            $v = $v['value'] ?? null;
        }

        return $v === true || $v === 1 || $v === '1'
            || (is_string($v) && in_array(strtolower($v), ['true', 'on', 'yes'], true));
    }

    /**
     * Per-stage HOT/WARM/COLD counts for a context, read from the materialized
     * snapshot in ONE grouped aggregate query (no per-member fan-out).
     *
     * @return array<string,array{hot:int,warm:int,cold:int}> keyed by stage_code
     */
    public function bandCountsByStage(string $organizationId, ?string $groupId): array
    {
        $q = $this->db->table('member_involvement_snapshots')
            ->select('stage_code, band, COUNT(*) AS total')
            ->where('organization_id', $organizationId);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);

        $out = [];
        foreach ($q->groupBy('stage_code, band')->get()->getResultArray() as $row) {
            $code = (string) $row['stage_code'];
            $band = (string) $row['band'];
            $out[$code] ??= ['hot' => 0, 'warm' => 0, 'cold' => 0];
            if (isset($out[$code][$band])) {
                $out[$code][$band] = (int) $row['total'];
            }
        }

        return $out;
    }

    /**
     * The involvement snapshots for the members at a given stage in a context,
     * keyed by user_id — the quantum-of-work figures the roster surfaces beside
     * each member. ONE bounded read.
     *
     * @return array<string,array<string,mixed>>
     */
    public function snapshotsAtStage(string $organizationId, string $stageCode, ?string $groupId): array
    {
        $q = $this->db->table('member_involvement_snapshots')
            ->where('organization_id', $organizationId)
            ->where('stage_code', $stageCode);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);

        $out = [];
        foreach ($q->get()->getResultArray() as $row) {
            $out[(string) $row['user_id']] = $row;
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Band classification (the pure rule engine — no I/O, fully unit-testable).
    // -------------------------------------------------------------------------

    /**
     * Classify one member's involvement into a band using ORDERED RULE BANDS.
     * Pure function of the metrics + thresholds — no side effects, no eval.
     *
     * @param array{activity_count:int,activity_target:int,participation_bps:int,
     *              last_activity_at:?string,quantum:int} $m
     * @param array{cold_floor_bps:int,cold_quantum:int,hot_participation_bps:int,
     *              hot_quantum:int,recent_activity_days:int} $t
     */
    public function classify(array $m, array $t, string $nowUtc): string
    {
        $participation = (int) $m['participation_bps'];
        $quantum       = (int) $m['quantum'];
        $lastActivity  = $m['last_activity_at'] ?? null;

        $recent = false;
        if ($lastActivity !== null && $lastActivity !== '') {
            $cutoff = $this->daysAgo($nowUtc, (int) $t['recent_activity_days']);
            $recent = (string) $lastActivity >= $cutoff;
        }

        // Rule 1 (COLD): no activity in window, OR participation below the floor
        // AND not enough quantum to be considered involved. A fruitful discipler
        // (high quantum) is kept OFF cold even with low direct participation.
        $noActivity = (int) $m['activity_count'] <= 0 && $quantum < (int) $t['cold_quantum'];
        if ($noActivity) {
            return 'cold';
        }
        if ($participation < (int) $t['cold_floor_bps'] && $quantum < (int) $t['cold_quantum']) {
            return 'cold';
        }

        // Rule 2 (HOT): recent activity AND (high participation OR high quantum,
        // where quantum already includes rolled-up downline effort).
        if ($recent && ($participation >= (int) $t['hot_participation_bps'] || $quantum >= (int) $t['hot_quantum'])) {
            return 'hot';
        }

        // Rule 3 (WARM): everything else.
        return 'warm';
    }

    // -------------------------------------------------------------------------
    // Composition (metrics + config -> snapshot fields + band).
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $metrics from gatherMetrics()
     * @param array<string,mixed> $cfg     from effectiveConfig()
     * @return array{participation_bps:int,own_quantum:int,downline_quantum:int,quantum:int,band:string}
     */
    private function compose(array $metrics, array $cfg): array
    {
        $w      = $cfg['weights'];
        $target = max(1, (int) $cfg['activity_target']);

        // Participation rate in basis points, capped at 100%.
        $participation = (int) round(((int) $metrics['activity_count'] / $target) * 10000);
        $participation = max(0, min(10000, $participation));

        // Own quantum = weighted sum of the three quantum-of-work components.
        $givingMajor = intdiv((int) $metrics['giving_minor'], 100);
        $ownQuantum  = (int) $metrics['points'] * (int) $w['points']
            + (int) $metrics['sponsorship_count'] * (int) $w['per_sponsorship']
            + $givingMajor * (int) $w['per_giving_major'];

        // Downline quantum = a configurable share of disciples' own quantum.
        $downline = (int) round(((int) $metrics['downline_own_quantum']) * ((int) $w['downline_share_pct'] / 100));

        $quantum = $ownQuantum + $downline;

        $band = $this->classify([
            'activity_count'    => (int) $metrics['activity_count'],
            'activity_target'   => $target,
            'participation_bps' => $participation,
            'last_activity_at'  => $metrics['last_activity_at'],
            'quantum'           => $quantum,
        ], $cfg['thresholds'], $this->clock->nowUtcString());

        return [
            'participation_bps' => $participation,
            'own_quantum'       => $ownQuantum,
            'downline_quantum'  => $downline,
            'quantum'           => $quantum,
            'band'              => $band,
        ];
    }

    // -------------------------------------------------------------------------
    // Source aggregation (delegated to the injected port; default-safe zeros).
    // -------------------------------------------------------------------------

    /**
     * @return array{last_activity_at:?string,activity_count:int,sponsorship_count:int,
     *               giving_minor:int,points:int,downline_own_quantum:int}
     */
    private function gatherMetrics(string $organizationId, string $userId, ?string $groupId, int $windowDays): array
    {
        $since = $this->daysAgo($this->clock->nowUtcString(), $windowDays);

        $base = [
            'last_activity_at'     => null,
            'activity_count'       => 0,
            'sponsorship_count'    => 0,
            'giving_minor'         => 0,
            'points'               => 0,
            'downline_own_quantum' => 0,
        ];

        if ($this->sources === null) {
            return $base;
        }

        $m = $this->sources->metricsFor($organizationId, $userId, $groupId, $since);

        return [
            'last_activity_at'     => $m['last_activity_at'] ?? null,
            'activity_count'       => max(0, (int) ($m['activity_count'] ?? 0)),
            'sponsorship_count'    => max(0, (int) ($m['sponsorship_count'] ?? 0)),
            'giving_minor'         => max(0, (int) ($m['giving_minor'] ?? 0)),
            'points'               => (int) ($m['points'] ?? 0),
            'downline_own_quantum' => max(0, (int) ($m['downline_own_quantum'] ?? 0)),
        ];
    }

    // -------------------------------------------------------------------------
    // Effective (hierarchical) config resolution.
    // -------------------------------------------------------------------------

    /**
     * Resolve window / activity target / thresholds / weights for a context,
     * merging any configured values over the built-in defaults. The org-wide
     * context (groupId null) resolves against the org-root group so an org-level
     * default still applies.
     *
     * @return array{window_days:int,activity_target:int,thresholds:array<string,int>,weights:array<string,int>}
     */
    public function effectiveConfig(string $organizationId, ?string $groupId): array
    {
        $ctxGroup = $groupId ?? $this->orgRootGroup($organizationId);

        $window = (int) $this->cfgScalar($ctxGroup, self::CAP_WINDOW, self::DEFAULT_WINDOW_DAYS);
        $window = max(self::MIN_WINDOW_DAYS, min(self::MAX_WINDOW_DAYS, $window));

        $target = (int) $this->cfgScalar($ctxGroup, self::CAP_TARGET, self::DEFAULT_ACTIVITY_TARGET);
        $target = max(1, $target);

        $thresholds = self::DEFAULT_THRESHOLDS;
        foreach ($this->cfgArray($ctxGroup, self::CAP_THRESHOLDS) as $k => $v) {
            if (array_key_exists($k, $thresholds) && is_numeric($v)) {
                $thresholds[$k] = (int) $v;
            }
        }

        $weights = self::DEFAULT_WEIGHTS;
        foreach ($this->cfgArray($ctxGroup, self::CAP_WEIGHTS) as $k => $v) {
            if (array_key_exists($k, $weights) && is_numeric($v)) {
                $weights[$k] = (int) $v;
            }
        }

        return [
            'window_days'     => $window,
            'activity_target' => $target,
            'thresholds'      => $thresholds,
            'weights'         => $weights,
        ];
    }

    // -------------------------------------------------------------------------
    // Admin config console (read the current settings + persist edits).
    // -------------------------------------------------------------------------

    /**
     * A view-model for the involvement-config admin console: the resolved
     * effective values for a context, the enable flag, the built-in defaults, and
     * the clamp bounds — everything the form needs to render and explain itself.
     * A pure read; safe to call regardless of whether a write port is wired.
     *
     * @return array{
     *   group_id:?string, enabled:bool, window_days:int, activity_target:int,
     *   thresholds:array<string,int>, weights:array<string,int>,
     *   defaults:array{window_days:int,activity_target:int,thresholds:array<string,int>,weights:array<string,int>},
     *   bounds:array{window_min:int,window_max:int,target_max:int},
     *   threshold_keys:list<string>, weight_keys:list<string>, writable:bool
     * }
     */
    public function configView(string $organizationId, ?string $groupId): array
    {
        $cfg = $this->effectiveConfig($organizationId, $groupId);

        return [
            'group_id'        => $groupId,
            'enabled'         => $this->isEnabled($organizationId, $groupId),
            'window_days'     => $cfg['window_days'],
            'activity_target' => $cfg['activity_target'],
            'thresholds'      => $cfg['thresholds'],
            'weights'         => $cfg['weights'],
            'defaults'        => [
                'window_days'     => self::DEFAULT_WINDOW_DAYS,
                'activity_target' => self::DEFAULT_ACTIVITY_TARGET,
                'thresholds'      => self::DEFAULT_THRESHOLDS,
                'weights'         => self::DEFAULT_WEIGHTS,
            ],
            'bounds' => [
                'window_min' => self::MIN_WINDOW_DAYS,
                'window_max' => self::MAX_WINDOW_DAYS,
                'target_max' => self::MAX_ACTIVITY_TARGET,
            ],
            'threshold_keys' => array_keys(self::DEFAULT_THRESHOLDS),
            'weight_keys'    => array_keys(self::DEFAULT_WEIGHTS),
            'writable'       => $this->configWriter !== null,
        ];
    }

    /**
     * Validate + persist the involvement config for a context through the single
     * hierarchical config store. Every value is CLAMPED to the same bounds the
     * reader enforces (window 30..365, target 1..100, thresholds/weights >= 0),
     * and only the KNOWN keys are written (unknown keys ignored) — so a bad form
     * post can never poison the resolver. The org-wide context (groupId null)
     * writes against the org-root group so it resolves everywhere.
     *
     * Arithmetic/clamp only — no eval (standing constraint).
     *
     * @param array<string,mixed> $in raw form input
     * @return Result data: {group_id, target_group_id, saved:list<string>}
     */
    public function saveConfig(string $organizationId, ?string $groupId, array $in, ?string $actorId = null): Result
    {
        if ($this->configWriter === null) {
            return Result::fail('NO_WRITER', 'journey.involvement.config_not_writable', 500);
        }

        // Resolve the concrete group the config attaches to (org-root for org-wide).
        $target = $groupId ?? $this->orgRootGroup($organizationId);
        if ($target === null) {
            return Result::fail('NO_GROUP', 'journey.involvement.config_needs_group', 422);
        }

        $enabled = $this->truthyInput($in['enabled'] ?? null);

        $window = $this->clampInt($in['window_days'] ?? self::DEFAULT_WINDOW_DAYS, self::MIN_WINDOW_DAYS, self::MAX_WINDOW_DAYS, self::DEFAULT_WINDOW_DAYS);
        $target_activities = $this->clampInt($in['activity_target'] ?? self::DEFAULT_ACTIVITY_TARGET, 1, self::MAX_ACTIVITY_TARGET, self::DEFAULT_ACTIVITY_TARGET);

        // Thresholds + weights: only known keys, each a non-negative int; a
        // missing field falls back to the built-in default (never left unset).
        $thresholds = [];
        foreach (self::DEFAULT_THRESHOLDS as $k => $def) {
            $thresholds[$k] = $this->clampInt($in['th_' . $k] ?? ($in['thresholds'][$k] ?? $def), 0, 1000000, (int) $def);
        }
        $weights = [];
        foreach (self::DEFAULT_WEIGHTS as $k => $def) {
            $max = $k === 'downline_share_pct' ? 100 : 1000000;
            $weights[$k] = $this->clampInt($in['wt_' . $k] ?? ($in['weights'][$k] ?? $def), 0, $max, (int) $def);
        }

        $writes = [
            self::CAP_ENABLED    => $enabled,
            self::CAP_WINDOW     => $window,
            self::CAP_TARGET     => $target_activities,
            self::CAP_THRESHOLDS => $thresholds,
            self::CAP_WEIGHTS    => $weights,
        ];

        $saved = [];
        foreach ($writes as $cap => $val) {
            if ($this->configWriter->set($organizationId, $target, $cap, $val, $actorId)) {
                $saved[] = $cap;
            }
        }

        if ($saved === []) {
            return Result::fail('SAVE_FAILED', 'journey.involvement.config_save_failed', 500);
        }

        return Result::ok([
            'group_id'        => $groupId,
            'target_group_id' => $target,
            'saved'           => $saved,
        ]);
    }

    /** Coerce a loose form value to a non-negative int, clamped to [min,max]. */
    private function clampInt(mixed $raw, int $min, int $max, int $default): int
    {
        if (is_bool($raw) || $raw === null || $raw === '' || ! is_numeric((string) $raw)) {
            $v = $default;
        } else {
            $v = (int) $raw;
        }

        return max($min, min($max, $v));
    }

    /** Truthy coercion for the enable checkbox/flag (matches isEnabled's stance). */
    private function truthyInput(mixed $v): bool
    {
        if (is_array($v)) {
            $v = $v['value'] ?? null;
        }

        return $v === true || $v === 1 || $v === '1'
            || (is_string($v) && in_array(strtolower($v), ['true', 'on', 'yes', 'checked'], true));
    }

    private function cfgScalar(?string $groupId, string $capability, int $default): int
    {
        if ($groupId === null || $this->config === null) {
            return $default;
        }
        $v = $this->config->value($groupId, $capability);
        if (is_numeric($v)) {
            return (int) $v;
        }
        if (is_array($v) && isset($v['value']) && is_numeric($v['value'])) {
            return (int) $v['value'];
        }

        return $default;
    }

    /** @return array<string,mixed> */
    private function cfgArray(?string $groupId, string $capability): array
    {
        if ($groupId === null || $this->config === null) {
            return [];
        }
        $v = $this->config->value($groupId, $capability);

        return is_array($v) ? $v : [];
    }

    /** The org-root group id (distance-max ancestor), for org-level config defaults. */
    private function orgRootGroup(string $organizationId): ?string
    {
        // Best-effort: the org's shallowest active group is treated as the root
        // config node. Returns null when the org has no groups (defaults apply).
        $row = $this->db->table('groups')
            ->select('id')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->orderBy('depth', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get()->getRowArray();

        return $row !== null ? (string) $row['id'] : null;
    }

    // -------------------------------------------------------------------------
    // Persistence helpers.
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $payload */
    private function upsert(string $organizationId, string $userId, ?string $groupId, array $payload): void
    {
        $q = $this->db->table('member_involvement_snapshots')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $existing = $q->get()->getRowArray();

        if ($existing === null) {
            $this->db->table('member_involvement_snapshots')->insert($payload + ['id' => Uuid::v7()]);

            return;
        }

        $u = $this->db->table('member_involvement_snapshots')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId);
        $groupId === null ? $u->where('group_id', null) : $u->where('group_id', $groupId);
        $u->update($payload);
    }

    /** Y-m-d H:i:s of $nowUtc minus N days. Arithmetic only (no eval). */
    private function daysAgo(string $nowUtc, int $days): string
    {
        $ts = strtotime($nowUtc);
        if ($ts === false) {
            $ts = time();
        }

        return date('Y-m-d H:i:s', $ts - max(0, $days) * 86400);
    }
}
