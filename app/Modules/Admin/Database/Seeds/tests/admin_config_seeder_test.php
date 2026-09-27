<?php

declare(strict_types=1);

/**
 * AdminConfigSeeder logic test (framework-free, in-memory DB fake).
 *
 * Drives the FOUNDATION administrative-config seeder against a fake connection
 * and asserts the CORRECT, IDEMPOTENT effect across every config surface:
 *
 *   - a canonical 7-level group hierarchy with valid closure (self edge + an
 *     edge from every ancestor, so the deepest cell has 7 ancestor rows),
 *   - platform_settings, feature_flags (org-wide + a group override),
 *     gamification_config, group_configurations, payment_provider_configs and
 *     a stream + stream_giving_configs row,
 *   - role_assignments carrying ALL FOUR scope modes (self,
 *     self_and_descendants, descendants_only, groups) with the hand-picked set
 *     for 'groups' recorded in grant_scope_groups,
 *   - a second run inserts NOTHING more (idempotency), which is the property
 *     that lets it run on every deploy / alongside migrate:refresh.
 *
 *   php app/Modules/Admin/Database/Seeds/tests/admin_config_seeder_test.php
 */

// ---------------------------------------------------------------------------
// Minimal CI4 seeder + connection fakes (mirrors bootstrap_seeders_test.php).
// ---------------------------------------------------------------------------
namespace CodeIgniter\Database {
    class Seeder
    {
        protected $db;

        public function call(string $class): void
        {
        }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows)
        {
        }

        public function getRowArray()
        {
            return $this->rows[0] ?? null;
        }

        public function getResultArray()
        {
            return array_values($this->rows);
        }
    }

    class QB
    {
        /** @var array<string,mixed> */
        private array $eq = [];

        public function __construct(private Conn $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function orderBy($k, $d = 'ASC')
        {
            return $this;
        }

        public function get($limit = null): RS
        {
            return new RS(array_values(array_filter(
                $this->db->rows[$this->t] ?? [],
                fn ($r) => $this->matches($r),
            )));
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;

            return true;
        }

        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                $rv = $r[$k] ?? null;
                if ($v === null) {
                    if ($rv !== null) {
                        return false;
                    }
                    continue;
                }
                if ((string) $rv !== (string) $v) {
                    return false;
                }
            }

            return true;
        }
    }

    class Conn
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): QB
        {
            return new QB($this, $t);
        }

        public function fieldExists(string $field, string $table): bool
        {
            // The real groups table has kind_code after migration; emulate that.
            return $table === 'groups' && $field === 'kind_code';
        }
    }
}

namespace WBS\Shared\Support {
    class Uuid
    {
        private static int $n = 0;

        public static function v7(): string
        {
            return sprintf('uuid-%06d', ++self::$n);
        }
    }
}

namespace {
    if (! function_exists('is_cli')) {
        function is_cli(): bool
        {
            return true;
        }
    }

    // ---- harness ----------------------------------------------------------
    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    require __DIR__ . '/../AdminConfigSeeder.php';

    // Seed the prerequisites AdminConfigSeeder reads: org, admin user, roles.
    $db = new \Fake\Conn();
    $db->rows['organizations'] = [[
        'id' => 'org-1', 'slug' => 'wbs', 'default_locale' => 'en', 'timezone' => 'Africa/Accra',
    ]];
    $db->rows['users'] = [[
        'id' => 'admin-1', 'organization_id' => 'org-1', 'email' => 'admin@wbs.local',
    ]];
    $db->rows['roles'] = [];
    foreach (['event_organizer', 'moderator', 'analyst', 'finance'] as $i => $code) {
        $db->rows['roles'][] = ['id' => 'role-' . $i, 'organization_id' => 'org-1', 'code' => $code];
    }

    $seeder = new \WBS\Admin\Database\Seeds\AdminConfigSeeder();
    (function () use ($db) {
        $this->db = $db; // inject protected $db
    })->call($seeder);

    $seeder->run();

    // ---- 1. hierarchy -----------------------------------------------------
    $groups = $db->rows['groups'] ?? [];
    chk('7 groups created', count($groups) === 7, 'got ' . count($groups));

    $bySlug = [];
    foreach ($groups as $g) {
        $bySlug[$g['slug']] = $g;
    }
    chk('national is depth 1, no parent', ($bySlug['wbs-national']['depth'] ?? null) === 1
        && array_key_exists('parent_id', $bySlug['wbs-national'] ?? [])
        && $bySlug['wbs-national']['parent_id'] === null);
    chk('deepest cell is depth 7', ($bySlug['ridge-cell-1']['depth'] ?? null) === 7,
        'got ' . var_export($bySlug['ridge-cell-1']['depth'] ?? null, true));
    chk('cell kind_code column populated (nullable ok)', array_key_exists('kind_code', $bySlug['ridge-cell-1'] ?? []));

    // closure: the deepest cell must have 7 ancestor rows (itself + 6 ancestors)
    $cellId  = $bySlug['ridge-cell-1']['id'] ?? '';
    $closure = array_filter($db->rows['group_closure'] ?? [], fn ($r) => $r['descendant_id'] === $cellId);
    chk('deepest cell has 7 closure ancestors', count($closure) === 7, 'got ' . count($closure));
    $self = array_filter($closure, fn ($r) => $r['ancestor_id'] === $cellId && (int) $r['distance'] === 0);
    chk('cell has a self closure edge (distance 0)', count($self) === 1);
    $toNational = array_filter($closure, fn ($r) => $r['ancestor_id'] === ($bySlug['wbs-national']['id'] ?? '') && (int) $r['distance'] === 6);
    chk('cell -> national closure distance is 6', count($toNational) === 1);

    // ---- 2..7 config tables ----------------------------------------------
    chk('platform_settings seeded (17)', count($db->rows['platform_settings'] ?? []) === 17,
        'got ' . count($db->rows['platform_settings'] ?? []));
    // 7 org-wide flags + 1 group override = 8
    chk('feature_flags: 7 org-wide + 1 group override', count($db->rows['feature_flags'] ?? []) === 8,
        'got ' . count($db->rows['feature_flags'] ?? []));
    $orgWideFlags = array_filter($db->rows['feature_flags'] ?? [], fn ($r) => $r['group_id'] === null);
    chk('all org-wide flags default OFF', array_reduce($orgWideFlags, fn ($c, $r) => $c && (int) $r['enabled'] === 0, true));
    $override = array_filter($db->rows['feature_flags'] ?? [], fn ($r) => $r['group_id'] !== null && $r['flag_key'] === 'events.paid_ticketing');
    chk('group override enables paid ticketing', count($override) === 1
        && (int) array_values($override)[0]['enabled'] === 1);

    chk('gamification_config seeded (7)', count($db->rows['gamification_config'] ?? []) === 7);
    chk('group_configurations seeded (9)', count($db->rows['group_configurations'] ?? []) === 9);
    // The event_committee capability ships DISABLED (default-OFF gating), and the
    // seeder must not silently turn committees on for the demo org.
    $committeeCfg = array_values(array_filter(
        $db->rows['group_configurations'] ?? [],
        static fn ($r): bool => (string) $r['capability'] === 'event_committee',
    ));
    chk('event_committee capability seeded once', count($committeeCfg) === 1);
    chk('event_committee seeded DISABLED (default OFF)', count($committeeCfg) === 1
        && (json_decode((string) $committeeCfg[0]['value_json'], true)['enabled'] ?? true) === false);
    // The integration-decisions capability (FR-REF-3b) ships DISABLED too — the
    // standalone dated decisions are default OFF until a leader turns them on.
    $intCfg = array_values(array_filter(
        $db->rows['group_configurations'] ?? [],
        static fn ($r): bool => (string) $r['capability'] === 'referrals.integration_decisions',
    ));
    chk('integration_decisions capability seeded once', count($intCfg) === 1);
    chk('integration_decisions seeded DISABLED (default OFF)', count($intCfg) === 1
        && (json_decode((string) $intCfg[0]['value_json'], true)['enabled'] ?? true) === false);
    $bdayCfg = array_values(array_filter(
        $db->rows['group_configurations'] ?? [],
        static fn ($r): bool => (string) $r['capability'] === 'groups.birthdays',
    ));
    chk('groups.birthdays capability seeded once', count($bdayCfg) === 1);
    chk('groups.birthdays seeded DISABLED (default OFF)', count($bdayCfg) === 1
        && (json_decode((string) $bdayCfg[0]['value_json'], true)['enabled'] ?? true) === false);
    // every inheritance mode present at least once
    $modes = array_unique(array_map(fn ($r) => $r['inheritance_mode'], $db->rows['group_configurations'] ?? []));
    foreach (['ancestor_default_child_override', 'inherit_only', 'child_owned', 'not_inheritable'] as $m) {
        chk("group_config inheritance mode present: {$m}", in_array($m, $modes, true));
    }
    chk('payment_provider_configs seeded (3)', count($db->rows['payment_provider_configs'] ?? []) === 3);
    chk('a group-scoped provider exists', (bool) array_filter($db->rows['payment_provider_configs'] ?? [], fn ($r) => $r['group_id'] !== null));
    chk('stream created', count($db->rows['streams'] ?? []) === 1);
    chk('stream_giving_config created (giving OFF by default)', count($db->rows['stream_giving_configs'] ?? []) === 1
        && (int) ($db->rows['stream_giving_configs'][0]['enabled'] ?? 1) === 0);

    // ---- 8. role assignments in all four scope modes ---------------------
    $ras   = $db->rows['role_assignments'] ?? [];
    chk('4 scoped role assignments', count($ras) === 4, 'got ' . count($ras));
    $seenModes = array_map(fn ($r) => $r['scope_mode'], $ras);
    foreach (['self', 'self_and_descendants', 'descendants_only', 'groups'] as $m) {
        chk("scope_mode present: {$m}", in_array($m, $seenModes, true));
    }
    // include_descendants must track the mode
    foreach ($ras as $r) {
        if (in_array($r['scope_mode'], ['self_and_descendants', 'descendants_only'], true)) {
            chk("include_descendants=1 for {$r['scope_mode']}", (int) $r['include_descendants'] === 1);
        } else {
            chk("include_descendants=0 for {$r['scope_mode']}", (int) $r['include_descendants'] === 0);
        }
    }
    // grant_scope_groups only for the 'groups' grant, with 2 hand-picked groups
    $gsg = $db->rows['grant_scope_groups'] ?? [];
    chk('grant_scope_groups has the hand-picked set (2)', count($gsg) === 2, 'got ' . count($gsg));
    $groupsGrant = array_values(array_filter($ras, fn ($r) => $r['scope_mode'] === 'groups'))[0] ?? null;
    chk('grant_scope_groups reference the groups-mode grant', $groupsGrant !== null
        && count(array_filter($gsg, fn ($r) => $r['grant_id'] === $groupsGrant['id'])) === 2);

    // ---- idempotency ------------------------------------------------------
    $before = array_map(fn ($t) => count($db->rows[$t] ?? []), array_keys($db->rows));
    $seeder->run();
    $after = array_map(fn ($t) => count($db->rows[$t] ?? []), array_keys($db->rows));
    chk('second run inserts nothing (idempotent)', $before === $after,
        'before=' . json_encode($before) . ' after=' . json_encode($after));

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
