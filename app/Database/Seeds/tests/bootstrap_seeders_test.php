<?php

declare(strict_types=1);

/**
 * Bootstrap seeder logic tests (framework-free).
 *
 * The seeders resolve `$this->db` (a real connection) at runtime; here we drive
 * each against an in-memory DB fake and assert the CORRECT, IDEMPOTENT effect:
 *
 *   - AdminAccountSeeder: creates the admin user, an org-wide active org_admin
 *     assignment, and the current season; a second run inserts nothing more.
 *   - NotificationTemplateSeeder: seeds every sent category as active English
 *     templates; re-run updates in place (no duplicate rows).
 *   - GeoReferenceSeeder: regions -> subregions -> countries with Ghana present
 *     and FK ids linked; re-run adds no new countries.
 *   - DatabaseSeeder: FOUNDATION list is ordered (Rbac before Admin; stages
 *     before rules) and DEMO is gated off by default.
 *
 *   php app/Database/Seeds/tests/bootstrap_seeders_test.php
 */

namespace CodeIgniter\Database {
    class Seeder
    {
        protected $db;

        /** Records nested seeder calls for the orchestrator test. */
        public array $called = [];

        public function call(string $class): void
        {
            $this->called[] = $class;
        }
    }

    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        private int $autoId = 0;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function insertID(): int
        {
            return $this->autoId;
        }

        public function bumpId(): int
        {
            return ++$this->autoId;
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
            return $this->rows;
        }
    }

    class QB
    {
        /** @var array<string,mixed> */
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
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
            return new RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
        }

        public function insert(array $row): bool
        {
            // Emulate AUTO_INCREMENT for reference tables lacking an explicit id.
            if (! isset($row['id'])) {
                $row['id'] = $this->db->bumpId();
            }
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
                if ((string) ($rv ?? '') !== (string) $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;

    // CI4 global helper the seeders use for CLI output; stub it for the harness.
    if (! function_exists('is_cli')) {
        function is_cli(): bool
        {
            return false;
        }
    }

    $root = dirname(__DIR__, 4);
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/AccessControl/Database/Seeds/AdminAccountSeeder.php';
    require_once $root . '/app/Modules/Notifications/Database/Seeds/NotificationTemplateSeeder.php';
    require_once $root . '/app/Modules/Geo/Database/Seeds/GeoReferenceSeeder.php';
    require_once $root . '/app/Database/Seeds/DatabaseSeeder.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    // Reflection helper to inject the fake db into the protected $db property.
    $inject = static function (object $seeder, BaseConnection $db): void {
        $rp = new ReflectionProperty($seeder, 'db');
        $rp->setAccessible(true);
        $rp->setValue($seeder, $db);
    };

    $seedOrg = static function (BaseConnection $db): void {
        $db->rows['organizations'] = [[
            'id' => 'org-1', 'slug' => 'wbs', 'default_locale' => 'en', 'timezone' => 'Africa/Accra',
        ]];
        $db->rows['roles'] = [[
            'id' => 'role-admin', 'organization_id' => 'org-1', 'code' => 'org_admin',
        ]];
    };

    // ===================== AdminAccountSeeder ===============================
    $db = new BaseConnection();
    $seedOrg($db);
    $db->rows['users']            = [];
    $db->rows['role_assignments'] = [];
    $db->rows['gamification_seasons'] = [];

    $s = new WBS\AccessControl\Database\Seeds\AdminAccountSeeder();
    $inject($s, $db);
    $s->run();

    $chk('admin user created', count($db->rows['users']) === 1);
    $u = $db->rows['users'][0];
    $chk('admin active + verified', ($u['status'] ?? '') === 'active' && (int) ($u['email_verified'] ?? 0) === 1);
    $chk('admin password hashed', isset($u['password_hash']) && password_verify('ChangeMe!Admin1', $u['password_hash']));
    $chk('org_admin assignment created', count($db->rows['role_assignments']) === 1);
    $ra = $db->rows['role_assignments'][0];
    $chk('assignment active org-wide', ($ra['status'] ?? '') === 'active' && array_key_exists('scope_group_id', $ra) && $ra['scope_group_id'] === null);
    $chk('assignment includes descendants', (int) ($ra['include_descendants'] ?? 0) === 1);
    $chk('current season created', count($db->rows['gamification_seasons']) === 1);
    $chk('season active + this year', ($db->rows['gamification_seasons'][0]['status'] ?? '') === 'active'
        && (int) $db->rows['gamification_seasons'][0]['season_year'] === (int) date('Y'));

    // Idempotent re-run.
    $s2 = new WBS\AccessControl\Database\Seeds\AdminAccountSeeder();
    $inject($s2, $db);
    $s2->run();
    $chk('re-run: still one user', count($db->rows['users']) === 1);
    $chk('re-run: still one assignment', count($db->rows['role_assignments']) === 1);
    $chk('re-run: still one season', count($db->rows['gamification_seasons']) === 1);

    // No org -> no-op (defensive).
    $empty = new BaseConnection();
    $empty->rows['organizations'] = [];
    $s3 = new WBS\AccessControl\Database\Seeds\AdminAccountSeeder();
    $inject($s3, $empty);
    $s3->run();
    $chk('no org -> no users seeded', empty($empty->rows['users']));

    // ===================== NotificationTemplateSeeder ======================
    $db = new BaseConnection();
    $seedOrg($db);
    $db->rows['notification_templates'] = [];
    $t = new WBS\Notifications\Database\Seeds\NotificationTemplateSeeder();
    $inject($t, $db);
    $t->run();

    $keys = array_column($db->rows['notification_templates'], 'key_name');
    foreach (['account_verify_reminder', 'event_reminder', 'event_cancelled', 'event_updated', 'event_promoted', 'security_alert', 'access_request', 'access_request_outcome', 'stream_relay_failure', 'partnership_commitment_due', 'outreach_follow_up_due', 'gamification_review_reminder', 'gamification_review_escalation', 'journey_proposal_reminder', 'journey_proposal_escalation'] as $need) {
        $chk("template seeded: {$need}", in_array($need, $keys, true));
    }
    $chk('templates active + English', array_reduce($db->rows['notification_templates'], fn ($c, $r) => $c && $r['status'] === 'active' && $r['locale'] === 'en', true));
    $chk('templates have non-empty body', array_reduce($db->rows['notification_templates'], fn ($c, $r) => $c && trim((string) $r['body']) !== '', true));
    $seededCount = count($db->rows['notification_templates']);

    $t2 = new WBS\Notifications\Database\Seeds\NotificationTemplateSeeder();
    $inject($t2, $db);
    $t2->run();
    $chk('re-run: no duplicate templates', count($db->rows['notification_templates']) === $seededCount);

    // ===================== GeoReferenceSeeder ==============================
    $db = new BaseConnection();
    $db->rows['regions']    = [];
    $db->rows['subregions'] = [];
    $db->rows['countries']  = [];
    $g = new WBS\Geo\Database\Seeds\GeoReferenceSeeder();
    $inject($g, $db);
    $g->run();

    $chk('regions seeded', count($db->rows['regions']) >= 5);
    $chk('subregions seeded', count($db->rows['subregions']) >= 5);
    $isos = array_column($db->rows['countries'], 'iso2');
    $chk('Ghana present', in_array('GH', $isos, true));
    $gh = array_values(array_filter($db->rows['countries'], fn ($c) => $c['iso2'] === 'GH'))[0];
    $chk('Ghana currency GHS', ($gh['currency'] ?? '') === 'GHS');
    $chk('Ghana phonecode 233', (string) ($gh['phonecode'] ?? '') === '233');
    $chk('Ghana region_id linked', ! empty($gh['region_id']));
    $chk('Ghana subregion_id linked', ! empty($gh['subregion_id']));
    $countryCount = count($db->rows['countries']);

    $g2 = new WBS\Geo\Database\Seeds\GeoReferenceSeeder();
    $inject($g2, $db);
    $g2->run();
    $chk('re-run: no duplicate countries', count($db->rows['countries']) === $countryCount);
    $chk('re-run: no duplicate regions', count($db->rows['regions']) >= 5 && count(array_unique(array_column($db->rows['regions'], 'name'))) === count($db->rows['regions']));

    // ===================== DatabaseSeeder orchestration =====================
    $master = new App\Database\Seeds\DatabaseSeeder();
    // Ensure demo stays OFF regardless of ambient env/argv.
    putenv('wbs.seedDemo');
    $argvBak       = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['spark', 'db:seed'];
    $master->run();
    $called = $master->called;

    $chk('foundation: Rbac before Admin', array_search(WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder::class, $called, true)
        < array_search(WBS\AccessControl\Database\Seeds\AdminAccountSeeder::class, $called, true));
    $chk('foundation: stages before membership rules', array_search(WBS\Journey\Database\Seeds\JourneyStageSeeder::class, $called, true)
        < array_search(WBS\Journey\Database\Seeds\MembershipRuleSeeder::class, $called, true));
    $chk('foundation: AdminConfig after AdminAccount', in_array(WBS\Admin\Database\Seeds\AdminConfigSeeder::class, $called, true)
        && array_search(WBS\AccessControl\Database\Seeds\AdminAccountSeeder::class, $called, true)
        < array_search(WBS\Admin\Database\Seeds\AdminConfigSeeder::class, $called, true));
    $chk('foundation includes geo + templates', in_array(WBS\Geo\Database\Seeds\GeoReferenceSeeder::class, $called, true)
        && in_array(WBS\Notifications\Database\Seeds\NotificationTemplateSeeder::class, $called, true));
    $chk('demo OFF by default: DemoDataSeeder not called', ! in_array(WBS\Admin\Database\Seeds\DemoDataSeeder::class, $called, true));

    // Demo ON via env.
    $master2 = new App\Database\Seeds\DatabaseSeeder();
    putenv('wbs.seedDemo=1');
    $master2->run();
    $chk('demo ON via env: DemoDataSeeder called', in_array(WBS\Admin\Database\Seeds\DemoDataSeeder::class, $master2->called, true));
    $chk('demo ON: campaign demos called', in_array(WBS\Gamification\Database\Seeds\CampaignDemoSeeder::class, $master2->called, true));
    putenv('wbs.seedDemo');

    $master3 = new App\Database\Seeds\DatabaseSeeder();
    $_SERVER['argv'] = ['spark', 'db:seed', 'App\Database\Seeds\DatabaseSeeder', '--demo'];
    $master3->run();
    $chk('demo ON via --demo: DemoDataSeeder called', in_array(WBS\Admin\Database\Seeds\DemoDataSeeder::class, $master3->called, true));
    if ($argvBak !== null) {
        $_SERVER['argv'] = $argvBak;
    }

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
