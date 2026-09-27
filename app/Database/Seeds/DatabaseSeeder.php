<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Master seeder — fires up a runnable WBS deployment in one command:
 *
 *     php spark db:seed "App\\Database\\Seeds\\DatabaseSeeder"
 *
 * Runs every module seeder in DEPENDENCY ORDER. Split into two tiers:
 *
 *   FOUNDATION (always safe for production) — the reference/bootstrap data a live
 *   deployment needs to function: org + RBAC, admin login + current season, geo
 *   reference, group-kind taxonomy, journey stages + membership/activity rules,
 *   notification templates, provider-adapter catalogue, certificate templates.
 *
 *   DEMO (skipped unless enabled) — illustrative sample content (demo users,
 *   groups, posts, campaigns, outreach, chained referral accounts). Enabled with
 *   env `wbs.seedDemo=1` or CLI flag `--demo`; NEVER run this against production.
 *
 * Every child seeder is individually idempotent (upsert on natural keys), so the
 * master seeder is safe to re-run — it converges the database to the seeded
 * baseline without duplicating rows.
 */
class DatabaseSeeder extends Seeder
{
    /** Foundation seeders, in strict dependency order. */
    private const FOUNDATION = [
        // 1. Org + permissions + roles + default identity policy. Creates 'wbs'.
        \WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder::class,
        // 2. Admin login + org_admin assignment + current gamification season.
        \WBS\AccessControl\Database\Seeds\AdminAccountSeeder::class,
        // 2b. Administrative configuration: canonical group hierarchy + platform
        //     settings + feature flags (OFF) + gamification config + group-scoped
        //     capability configs + payment providers + stream-giving config +
        //     demonstrative role grants in all four RuBAC scope modes.
        \WBS\Admin\Database\Seeds\AdminConfigSeeder::class,
        // 3. Geo reference (regions/subregions/countries) for pickers + phone.
        \WBS\Geo\Database\Seeds\GeoReferenceSeeder::class,
        // 4. Group classification taxonomy (kinds).
        \WBS\Groups\Database\Seeds\GroupKindSeeder::class,
        // 5. Journey stages, then the rules that reference them.
        \WBS\Journey\Database\Seeds\JourneyStageSeeder::class,
        \WBS\Journey\Database\Seeds\MembershipRuleSeeder::class,
        \WBS\Journey\Database\Seeds\JourneyGamificationSeeder::class,
        // 6. Win-Build-Send activity catalogue (gamification rules).
        \WBS\Gamification\Database\Seeds\WbsActivityCatalogSeeder::class,
        // 7. Notification templates the platform sends.
        \WBS\Notifications\Database\Seeds\NotificationTemplateSeeder::class,
        // 8. Provider adapter catalogue (integrations).
        \WBS\Integrations\Database\Seeds\AdapterCatalogSeeder::class,
        // 9. Certificate templates (events).
        \WBS\Events\Database\Seeds\CertificateTemplateSeeder::class,
    ];

    /** Demo/sample content — only when explicitly enabled. */
    private const DEMO = [
        \WBS\Admin\Database\Seeds\DemoDataSeeder::class,
        \WBS\Referrals\Database\Seeds\ChainedAccountSeeder::class,
        \WBS\Referrals\Database\Seeds\OutreachDemoSeeder::class,
        \WBS\Gamification\Database\Seeds\CampaignBaseDemoSeeder::class,
        \WBS\Gamification\Database\Seeds\CampaignDemoSeeder::class,
        \WBS\Gamification\Database\Seeds\CampaignSubtreeDemoSeeder::class,
    ];

    public function run(): void
    {
        foreach (self::FOUNDATION as $seeder) {
            $this->call($seeder);
        }

        if ($this->demoEnabled()) {
            if (is_cli()) {
                fwrite(STDOUT, "-- Demo content enabled: seeding sample data (NOT for production) --\n");
            }
            foreach (self::DEMO as $seeder) {
                $this->call($seeder);
            }
        } elseif (is_cli()) {
            fwrite(STDOUT, "-- Demo content skipped (set wbs.seedDemo=1 or pass --demo to include it) --\n");
        }

        if (is_cli()) {
            fwrite(STDOUT, "\nSeeding complete. Sign in with the admin account from AdminAccountSeeder.\n");
        }
    }

    private function demoEnabled(): bool
    {
        $env = (string) (getenv('wbs.seedDemo') ?: getenv('WBS_SEED_DEMO') ?: '');
        if ($env === '' && function_exists('env')) {
            $env = (string) env('wbs.seedDemo', '');
        }
        if ($env === '1' || strtolower($env) === 'true') {
            return true;
        }
        // `php spark db:seed "App\Database\Seeds\DatabaseSeeder" --demo`
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if ($arg === '--demo' || $arg === '--demo=1' || $arg === '--demo=true') {
                return true;
            }
        }

        return false;
    }
}
