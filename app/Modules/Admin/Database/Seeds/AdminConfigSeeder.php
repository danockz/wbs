<?php

declare(strict_types=1);

namespace WBS\Admin\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\Uuid;

/**
 * Administrative configuration bootstrap (FOUNDATION, production-safe).
 *
 * Seeds the CONFIG/SETTINGS layers that the rest of the foundation set leaves
 * empty, plus a canonical group hierarchy to anchor scoped configuration and
 * role assignments. Everything here is production-safe defaults: feature flags
 * default OFF (feature gating is opt-in per the hierarchical-config model),
 * settings carry sensible org-wide values, and the demonstration role grants use
 * the seeded admin as subject so they never reference demo users.
 *
 * Runs AFTER RbacBootstrapSeeder + AdminAccountSeeder (needs the 'wbs' org, the
 * role catalogue and the admin user). Fully IDEMPOTENT — every write is keyed on
 * its natural unique key and skipped if already present, so re-running (and
 * running alongside migrate:refresh) never duplicates or collides.
 *
 * Covers:
 *   1. Canonical 7-level group hierarchy (National -> Region -> Area -> Local
 *      Assembly -> Fellowship -> Senior Cell -> Cell) with correct
 *      closure/path/depth — the scope anchors for everything below.
 *   2. platform_settings   — org-wide administrative defaults.
 *   3. feature_flags       — org-wide defaults (OFF) + illustrative group override.
 *   4. gamification_config — extra org-level config keys (idempotent upsert).
 *   5. group_configurations — capability configs at several hierarchy levels,
 *      exercising every inheritance mode.
 *   6. payment_provider_configs — org-wide + one group-scoped provider.
 *   7. stream_giving_configs — giving config for a seeded standalone stream.
 *   8. role_assignments in ALL FOUR scope modes (self, self_and_descendants,
 *      descendants_only, groups) + the grant_scope_groups set for 'groups'.
 *
 *   php spark db:seed "WBS\Admin\Database\Seeds\AdminConfigSeeder"
 */
class AdminConfigSeeder extends Seeder
{
    /**
     * The canonical WBS group ladder, deepest-nesting last. Each entry:
     *   slug => [name, kind_code|null, parent slug|null]
     * Slugs are the idempotency key (groups_org_slug_uq), so re-runs are safe.
     */
    private const HIERARCHY = [
        'wbs-national'     => ['WBS National',              null,           null],
        'greater-accra'    => ['Greater Accra Region',      null,           'wbs-national'],
        'accra-central'    => ['Accra Central Area',        null,           'greater-accra'],
        'ridge-assembly'   => ['Ridge Local Assembly',      null,           'accra-central'],
        'ridge-fellowship' => ['Ridge Fellowship',          null,           'ridge-assembly'],
        'ridge-senior-cell'=> ['Ridge Senior Cell',         null,           'ridge-fellowship'],
        'ridge-cell-1'     => ['Ridge Cell 1',              null,           'ridge-senior-cell'],
    ];

    /** Org-wide platform settings: setting_key => value (stored as JSON). */
    private const SETTINGS = [
        'org.display_name'            => 'Winning-Building-Sending',
        'org.default_locale'          => 'en',
        'org.default_timezone'        => 'Africa/Accra',
        'org.default_currency'        => 'GHS',
        'org.default_phone_region'    => 'GH',
        'security.session_idle_minutes' => 30,
        'security.mfa_required_for_admins' => true,
        'privacy.location_consent_required' => true,
        'notifications.default_channel' => 'email',
        'events.default_show_rate'    => 0.6,
        'ranking.default_measure'     => 'points',
        'ranking.rollup_awards'       => false,
        'checkin.group_credit_mode'   => 'per_group',
        // J6 — pending journey-proposal aging (remind → escalate → optional
        // timeout). Hours/days; timeout 0 = never auto-terminate (a human must
        // decide). timeout_action = the terminal status when it does fire.
        'journey.proposal_remind_hours'   => 48,
        'journey.proposal_escalate_hours' => 120,
        'journey.proposal_timeout_days'   => 0,
        'journey.proposal_timeout_action' => 'rejected',
    ];

    /**
     * Feature flags. Default OFF (gating is opt-in per the hierarchical config
     * model): flag_key => [enabled, description].
     */
    private const FLAGS = [
        'events.paid_ticketing'      => [false, 'Paid ticketing + promo codes for events (FR-EVT-016).'],
        'events.kiosk_checkin'       => [false, 'Kiosk/offline check-in devices (FR-EVT-017).'],
        'streaming.live_giving'      => [false, 'In-stream giving widget + progress bar.'],
        'streaming.relay_health_sweep' => [false, 'Auto-end streams stuck live with no relay heartbeat (ST4 sweep).'],
        'community.media_review'     => [false, 'Post-event media moderation queue (FR-EVT-015).'],
        'gamification.disciplemaking_awards' => [false, 'Award points to disciplers on journey advance.'],
        'reports.pdf_export'         => [false, 'PDF export of mobilization reports (parked feature).'],
    ];

    /** Extra org-level gamification_config keys: key => [value, type, description]. */
    private const GAMIFICATION_CONFIG = [
        'season_rollover_mode'  => ['manual', 'string', 'How seasons roll over: manual|scheduled.'],
        'group_ranking_enabled' => ['true', 'boolean', 'Rank groups at every ancestor level.'],
        'individual_ranking_scope' => ['org_and_group', 'string', 'Individuals ranked org-wide and group-wide.'],
        // G2 — open fraud-review aging (remind → escalate → optional timeout).
        'held_review_remind_hours'   => ['24', 'integer', 'Hours before an open fraud review is (re-)reminded to its approver (G2).'],
        'held_review_escalate_hours' => ['72', 'integer', 'Hours before an open fraud review escalates to org admins (G2).'],
        'held_review_timeout_days'   => ['0', 'integer', 'Days before a still-open fraud review auto-rejects; 0 = never auto-reject (G2).'],
        // G4 — disposition of open held entries at season rollover.
        'held_rollover_policy'       => ['carry_forward', 'string', 'Open held entries at rollover: carry_forward|reject_on_close|resolve_before_close (G4).'],
    ];

    public function run(): void
    {
        $now      = date('Y-m-d H:i:s');
        $nowMicro = date('Y-m-d H:i:s.u');

        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            if (is_cli()) {
                fwrite(STDERR, "AdminConfigSeeder: no 'wbs' organization — run RbacBootstrapSeeder first.\n");
            }

            return;
        }
        $orgId = (string) $org['id'];

        $admin = $this->db->table('users')
            ->where('organization_id', $orgId)->where('email', (string) (getenv('wbs.adminEmail') ?: 'admin@wbs.local'))
            ->get()->getRowArray();
        $adminId = $admin !== null ? (string) $admin['id'] : null;

        $groups = $this->seedHierarchy($orgId, $now);
        $this->seedSettings($orgId, $adminId, $nowMicro);
        $this->seedFlags($orgId, $adminId, $nowMicro, $groups);
        $this->seedGamificationConfig($orgId, $adminId, $now);
        $this->seedGroupConfigurations($orgId, $adminId, $nowMicro, $groups);
        $this->seedPaymentProviders($orgId, $now, $groups);
        $this->seedStreamGiving($orgId, $adminId, $now, $groups);

        if ($adminId !== null) {
            $this->seedScopedRoleAssignments($orgId, $adminId, $now, $nowMicro, $groups);
        }

        if (is_cli()) {
            fwrite(STDOUT, "AdminConfigSeeder: hierarchy + settings + flags + configs + all-scope grants seeded for org {$orgId}.\n");
        }
    }

    // -----------------------------------------------------------------------
    // 1. Canonical group hierarchy (with closure + path + depth).
    // -----------------------------------------------------------------------

    /** @return array<string,string> slug => group id */
    private function seedHierarchy(string $orgId, string $now): array
    {
        $ids = [];
        foreach (self::HIERARCHY as $slug => [$name, $kind, $parentSlug]) {
            $existing = $this->db->table('groups')
                ->where('organization_id', $orgId)->where('slug', $slug)
                ->get()->getRowArray();
            if ($existing !== null) {
                $ids[$slug] = (string) $existing['id'];
                continue;
            }

            $id       = Uuid::v7();
            $parentId = $parentSlug !== null ? ($ids[$parentSlug] ?? null) : null;
            $depth    = 1;
            $path     = '/' . $id . '/';
            if ($parentId !== null) {
                $parent = $this->db->table('groups')->where('id', $parentId)->get()->getRowArray();
                if ($parent !== null) {
                    $depth = (int) $parent['depth'] + 1;
                    $path  = rtrim((string) $parent['path'], '/') . '/' . $id . '/';
                }
            }

            $row = [
                'id'              => $id,
                'organization_id' => $orgId,
                'parent_id'       => $parentId,
                'name'            => $name,
                'slug'            => $slug,
                'type'            => null,
                'depth'           => $depth,
                'path'            => $path,
                'leader_user_id'  => null,
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
            if ($this->db->fieldExists('kind_code', 'groups')) {
                $row['kind_code'] = $kind;
            }
            $this->db->table('groups')->insert($row);

            // Closure: self edge (distance 0) + an edge from every ancestor.
            $this->db->table('group_closure')->insert([
                'ancestor_id' => $id, 'descendant_id' => $id, 'distance' => 0,
            ]);
            if ($parentId !== null) {
                $ancestors = $this->db->table('group_closure')
                    ->where('descendant_id', $parentId)
                    ->get()->getResultArray();
                foreach ($ancestors as $a) {
                    $this->db->table('group_closure')->insert([
                        'ancestor_id'   => (string) $a['ancestor_id'],
                        'descendant_id' => $id,
                        'distance'      => (int) $a['distance'] + 1,
                    ]);
                }
            }

            $ids[$slug] = $id;
        }

        return $ids;
    }

    // -----------------------------------------------------------------------
    // 2. platform_settings
    // -----------------------------------------------------------------------

    private function seedSettings(string $orgId, ?string $actorId, string $nowMicro): void
    {
        foreach (self::SETTINGS as $key => $value) {
            $exists = $this->db->table('platform_settings')
                ->where('organization_id', $orgId)->where('setting_key', $key)
                ->get()->getRowArray();
            if ($exists !== null) {
                continue;
            }
            $this->db->table('platform_settings')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'setting_key'     => $key,
                'value_json'      => json_encode($value),
                'version'         => 1,
                'updated_by'      => $actorId,
                'updated_at'      => $nowMicro,
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // 3. feature_flags — org-wide defaults + one illustrative group override.
    // -----------------------------------------------------------------------

    /** @param array<string,string> $groups */
    private function seedFlags(string $orgId, ?string $actorId, string $nowMicro, array $groups): void
    {
        foreach (self::FLAGS as $key => [$enabled, $desc]) {
            $this->insertFlag($orgId, $key, null, (bool) $enabled, $desc, $actorId, $nowMicro);
        }

        // Illustrative override: enable paid ticketing for ONE assembly only, to
        // prove that a group-scoped flag row wins over the org-wide default.
        if (isset($groups['ridge-assembly'])) {
            $this->insertFlag(
                $orgId,
                'events.paid_ticketing',
                $groups['ridge-assembly'],
                true,
                'Group override: paid ticketing enabled for Ridge Local Assembly.',
                $actorId,
                $nowMicro,
            );
        }
    }

    private function insertFlag(string $orgId, string $key, ?string $groupId, bool $enabled, ?string $desc, ?string $actorId, string $nowMicro): void
    {
        $q = $this->db->table('feature_flags')
            ->where('organization_id', $orgId)->where('flag_key', $key)->where('group_id', $groupId);
        if ($q->get()->getRowArray() !== null) {
            return;
        }
        $this->db->table('feature_flags')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'flag_key'        => $key,
            'group_id'        => $groupId,
            'enabled'         => $enabled ? 1 : 0,
            'description'     => $desc,
            'updated_by'      => $actorId,
            'updated_at'      => $nowMicro,
        ]);
    }

    // -----------------------------------------------------------------------
    // 4. gamification_config
    // -----------------------------------------------------------------------

    private function seedGamificationConfig(string $orgId, ?string $actorId, string $now): void
    {
        foreach (self::GAMIFICATION_CONFIG as $key => [$value, $type, $desc]) {
            $exists = $this->db->table('gamification_config')
                ->where('organization_id', $orgId)->where('config_key', $key)
                ->get()->getRowArray();
            if ($exists !== null) {
                continue;
            }
            $this->db->table('gamification_config')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'config_key'      => $key,
                'config_value'    => $value,
                'config_type'     => $type,
                'description'     => $desc,
                'is_editable'     => 1,
                'updated_by'      => $actorId,
                'created_at'      => $now,
                'updated_at'      => null,
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // 5. group_configurations — capability configs across levels + every mode.
    // -----------------------------------------------------------------------

    /** @param array<string,string> $groups */
    private function seedGroupConfigurations(string $orgId, ?string $actorId, string $nowMicro, array $groups): void
    {
        // slug => [capability, inheritance_mode, value]
        $configs = [
            ['wbs-national',    'branding',      'ancestor_default_child_override', ['primary_color' => '#0EA5E9', 'logo' => 'wbs-national']],
            ['wbs-national',    'retention',     'inherit_only',                    ['audit_days' => 3650, 'message_days' => 730]],
            ['greater-accra',   'notifications', 'ancestor_default_child_override', ['sms_enabled' => true, 'email_enabled' => true]],
            ['ridge-assembly',  'payment',       'child_owned',                     ['currency' => 'GHS', 'providers' => ['mtn_momo']]],
            ['ridge-assembly',  'branding',      'ancestor_default_child_override', ['primary_color' => '#22C55E', 'logo' => 'ridge']],
            ['ridge-fellowship','integrations',  'not_inheritable',                 ['calendar_sync' => false]],
            // Event committees: the shape a body configures, seeded DISABLED because
            // the capability is default OFF everywhere (CommitteeConfig::CAPABILITY).
            // A leader turns it on for their own subtree; descendants inherit unless
            // they override, which is what ancestor_default_child_override means.
            ['wbs-national',    'event_committee', 'ancestor_default_child_override', [
                'enabled'                   => false,
                'oversight'                 => 'formation_and_major',
                'max_members'               => 12,
                'chair_requires_approval'   => true,
                'allow_subdelegation'       => true,
                'grace_days'                => 7,
                'budget_approval_threshold' => null,
                'allow_crosscut'            => false,
            ]],
            // Integration decisions (FR-REF-3b): the shape a body configures,
            // seeded DISABLED — the capability is default OFF everywhere
            // (IntegrationConfig::CAPABILITY). A leader turns it on for their own
            // subtree; descendants inherit unless they override.
            ['wbs-national',    'referrals.integration_decisions', 'ancestor_default_child_override', [
                'enabled'                             => false,
                'required_groups'                     => ['salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course'],
                'foundation_course_categories'        => ['foundation', 'membership'],
                'allow_self_declaration'              => true,
                'self_declaration_requires_confirmation' => true,
                'derive_from_enrolment'               => true,
                'derive_from_completion'              => false,
                'gate_journey_advance'                => true,
                'gate_stages'                         => ['in_foundation', 'established'],
                // Optional onboarding capture-form inputs (empty = none shown;
                // list the decision types mentors may record at capture time).
                'capture_inputs'                      => ['salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course'],
            ]],
        ];

        foreach ($configs as [$slug, $capability, $mode, $value]) {
            $groupId = $groups[$slug] ?? null;
            if ($groupId === null) {
                continue;
            }
            $exists = $this->db->table('group_configurations')
                ->where('group_id', $groupId)->where('capability', $capability)
                ->get()->getRowArray();
            if ($exists !== null) {
                continue;
            }
            $this->db->table('group_configurations')->insert([
                'id'               => Uuid::v7(),
                'organization_id'  => $orgId,
                'group_id'         => $groupId,
                'capability'       => $capability,
                'inheritance_mode' => $mode,
                'value_json'       => json_encode($value),
                'version'          => 1,
                'updated_by'       => $actorId,
                'updated_at'       => $nowMicro,
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // 6. payment_provider_configs — org-wide + one group-scoped provider.
    // -----------------------------------------------------------------------

    /** @param array<string,string> $groups */
    private function seedPaymentProviders(string $orgId, string $now, array $groups): void
    {
        $providers = [
            [null, 'mtn_momo', 'MTN Mobile Money', ['GHS'], ['momo']],
            [null, 'stripe',   'Stripe (cards)',   ['GHS', 'USD'], ['card']],
            [$groups['ridge-assembly'] ?? null, 'mtn_momo', 'Ridge Assembly MoMo', ['GHS'], ['momo']],
        ];

        foreach ($providers as [$groupId, $provider, $displayName, $currencies, $methods]) {
            $exists = $this->db->table('payment_provider_configs')
                ->where('organization_id', $orgId)->where('provider', $provider)->where('version', 1)
                ->get()->getRowArray();
            // Unique key is (org, provider, version); an org-wide row already
            // present means the provider is configured — skip to stay idempotent.
            if ($exists !== null && $groupId === null) {
                continue;
            }
            if ($groupId !== null) {
                $groupExists = $this->db->table('payment_provider_configs')
                    ->where('organization_id', $orgId)->where('provider', $provider)->where('group_id', $groupId)
                    ->get()->getRowArray();
                if ($groupExists !== null) {
                    continue;
                }
            }
            $this->db->table('payment_provider_configs')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'group_id'        => $groupId,
                'provider'        => $provider,
                'version'         => $groupId === null ? 1 : 2,
                'display_name'    => $displayName,
                'currencies'      => json_encode($currencies),
                'methods'         => json_encode($methods),
                'status'          => 'active',
                'metadata'        => json_encode(['schema_version' => 1, 'secrets' => 'external']),
                'created_at'      => $now,
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // 7. stream_giving_configs — needs a stream to attach to.
    // -----------------------------------------------------------------------

    /** @param array<string,string> $groups */
    private function seedStreamGiving(string $orgId, ?string $actorId, string $now, array $groups): void
    {
        if ($actorId === null) {
            return; // streams.created_by is NOT NULL
        }
        $groupId = $groups['ridge-assembly'] ?? null;

        // Idempotent by a stable title within the org.
        $title  = 'Ridge Assembly — Sunday Service (config sample)';
        $stream = $this->db->table('streams')
            ->where('organization_id', $orgId)->where('title', $title)
            ->get()->getRowArray();
        if ($stream === null) {
            $streamId = Uuid::v7();
            $this->db->table('streams')->insert([
                'id'              => $streamId,
                'organization_id' => $orgId,
                'event_id'        => null,
                'group_id'        => $groupId,
                'created_by'      => $actorId,
                'title'           => $title,
                'description'     => 'Sample stream carrying a giving configuration.',
                'access_policy'   => 'restricted',
                'status'          => 'draft',
                'slow_mode_secs'  => 0,
                'scheduled_at'    => null,
                'started_at'      => null,
                'ended_at'        => null,
                'created_at'      => $now,
                'updated_at'      => null,
            ]);
        } else {
            $streamId = (string) $stream['id'];
        }

        $exists = $this->db->table('stream_giving_configs')
            ->where('stream_id', $streamId)->get()->getRowArray();
        if ($exists !== null) {
            return;
        }
        $this->db->table('stream_giving_configs')->insert([
            'id'                      => Uuid::v7(),
            'organization_id'         => $orgId,
            'stream_id'               => $streamId,
            'enabled'                 => 0, // OFF by default; giving is opt-in.
            'cause_id'                => null,
            'progress_bar_enabled'    => 1,
            'widget_enabled'          => 1,
            'suggested_amounts'       => json_encode([1000, 2000, 5000, 10000]),
            'min_amount_minor'        => 500,
            'max_amount_minor'        => 1000000,
            'currency'                => 'GHS',
            'allow_anonymous'         => 1,
            'ack_enabled'             => 1,
            'ack_show_amount_allowed' => 0,
            'authorized_by'           => null,
            'authorized_at'           => null,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);
    }

    // -----------------------------------------------------------------------
    // 8. role_assignments in ALL FOUR scope modes (+ grant_scope_groups).
    // -----------------------------------------------------------------------

    /** @param array<string,string> $groups */
    private function seedScopedRoleAssignments(string $orgId, string $adminId, string $now, string $nowMicro, array $groups): void
    {
        // Use non-admin roles so these demonstrative grants never widen the
        // admin's already-org-wide authority; the admin is a convenient subject.
        $roleCodes = [
            'self'                 => 'event_organizer',
            'self_and_descendants' => 'moderator',
            'descendants_only'     => 'analyst',
            'groups'               => 'finance',
        ];
        $roleIds = [];
        foreach (array_unique(array_values($roleCodes)) as $code) {
            $r = $this->db->table('roles')
                ->where('organization_id', $orgId)->where('code', $code)
                ->get()->getRowArray();
            if ($r !== null) {
                $roleIds[$code] = (string) $r['id'];
            }
        }

        // scope_mode => [role code, anchor group slug]
        $grants = [
            'self'                 => ['event_organizer', 'ridge-assembly'],
            'self_and_descendants' => ['moderator',       'accra-central'],
            'descendants_only'     => ['analyst',         'greater-accra'],
            'groups'               => ['finance',         'ridge-fellowship'],
        ];

        foreach ($grants as $mode => [$code, $slug]) {
            $roleId  = $roleIds[$code] ?? null;
            $groupId = $groups[$slug] ?? null;
            if ($roleId === null || $groupId === null) {
                continue;
            }

            // Idempotent by (subject, role, scope_group_id): matches ra_uq.
            $exists = $this->db->table('role_assignments')
                ->where('subject_id', $adminId)
                ->where('role_id', $roleId)
                ->where('scope_group_id', $groupId)
                ->get()->getRowArray();
            if ($exists !== null) {
                continue;
            }

            $grantId = Uuid::v7();
            $this->db->table('role_assignments')->insert([
                'id'                  => $grantId,
                'organization_id'     => $orgId,
                'subject_id'          => $adminId,
                'role_id'             => $roleId,
                'scope_group_id'      => $groupId,
                'scope_mode'          => $mode,
                'include_crosscut'    => 0,
                'include_descendants' => in_array($mode, ['self_and_descendants', 'descendants_only'], true) ? 1 : 0,
                'status'              => 'active',
                'source'              => 'seed',
                'issued_by'           => null,
                'request_id'          => null,
                'effective_from'      => null,
                'effective_to'        => null,
                'revoked_at'          => null,
                'created_at'          => $now,
            ]);

            // For 'groups' mode, carry the hand-picked set in grant_scope_groups.
            if ($mode === 'groups') {
                $handPicked = array_values(array_filter([
                    $groups['ridge-fellowship'] ?? null,
                    $groups['ridge-cell-1'] ?? null,
                ]));
                foreach ($handPicked as $gid) {
                    $has = $this->db->table('grant_scope_groups')
                        ->where('grant_type', 'role_assignment')
                        ->where('grant_id', $grantId)
                        ->where('group_id', $gid)
                        ->get()->getRowArray();
                    if ($has !== null) {
                        continue;
                    }
                    $this->db->table('grant_scope_groups')->insert([
                        'id'              => Uuid::v7(),
                        'organization_id' => $orgId,
                        'grant_type'      => 'role_assignment',
                        'grant_id'        => $grantId,
                        'group_id'        => $gid,
                        'created_at'      => $nowMicro,
                    ]);
                }
            }
        }
    }
}
