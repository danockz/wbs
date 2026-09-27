<?php

declare(strict_types=1);

namespace WBS\Admin\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\Uuid;

/**
 * Demo/sample data so the dashboards render meaningful content on first boot.
 *
 * NOT for production. Idempotent-ish: it keys off the 'wbs' organization created
 * by RbacBootstrapSeeder and skips if demo content already exists (detected by a
 * sentinel community post). Run AFTER migrations + RbacBootstrapSeeder:
 *
 *   php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
 *   php spark db:seed 'WBS\Admin\Database\Seeds\DemoDataSeeder'
 *
 * All money is integer minor units; timestamps are UTC strings.
 */
class DemoDataSeeder extends Seeder
{
    private const SENTINEL = 'Welcome to the Win–Build–Send community';

    public function run(): void
    {
        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            if (is_cli()) {
                fwrite(STDERR, "DemoDataSeeder: run RbacBootstrapSeeder first (no 'wbs' org).\n");
            }

            return;
        }
        $orgId = $org['id'];

        // Idempotency guard.
        $exists = $this->db->table('community_posts')
            ->where('organization_id', $orgId)->where('title', self::SENTINEL)->countAllResults();
        if ($exists > 0) {
            if (is_cli()) {
                fwrite(STDOUT, "DemoDataSeeder: demo data already present; skipping.\n");
            }

            return;
        }

        $now = date('Y-m-d H:i:s');

        // -- Users ----------------------------------------------------------
        $users = [];
        foreach ([
            ['ama@example.test', 'Ama Mensah'],
            ['kofi@example.test', 'Kofi Boateng'],
            ['esi@example.test', 'Esi Owusu'],
            ['yaw@example.test', 'Yaw Darko'],
            ['abena@example.test', 'Abena Asante'],
        ] as [$email, $name]) {
            $id = Uuid::v7();
            $users[] = $id;
            $this->db->table('users')->insert([
                'id'              => $id,
                'organization_id' => $orgId,
                'email'           => $email,
                'email_verified'  => 1,
                'password_hash'   => password_hash('demo-not-for-prod', PASSWORD_BCRYPT),
                'display_name'    => $name,
                'status'          => 'active',
                'locale'          => 'en',
                'timezone'        => 'Africa/Accra',
                'mfa_enabled'     => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        // -- Group ----------------------------------------------------------
        $groupId = Uuid::v7();
        $this->db->table('groups')->insert([
            'id'              => $groupId,
            'organization_id' => $orgId,
            'parent_id'       => null,
            'name'            => 'Kumasi Central',
            'slug'            => 'kumasi-central',
            'type'            => 'chapter',
            'depth'           => 1,
            'path'            => '/' . $groupId . '/',
            'leader_user_id'  => $users[0],
            'status'          => 'active',
            // Public landing-page profile (group-specific public page).
            'tagline'         => 'Win. Build. Send. — together in Kumasi.',
            'description'     => "Kumasi Central is a growing community on the Win–Build–Send journey. "
                . "We gather weekly for worship and teaching, grow through small groups, and serve our city through outreach. "
                . "Whether you're exploring faith or ready to go deeper, there's a place for you here.",
            'location_text'   => 'Adum, Kumasi',
            'contact_email'   => 'hello@kumasicentral.example',
            'contact_phone'   => '+233 32 000 0000',
            'website_url'     => null,
            'announcement'    => 'New members welcome lunch this Sunday after the second service — come say hello!',
            'hero_theme'      => 'aurora',
            'public_join'     => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        // Closure self-row (root group, distance 0) so closure queries resolve.
        $this->db->table('group_closure')->insert([
            'ancestor_id' => $groupId, 'descendant_id' => $groupId, 'distance' => 0,
        ]);

        // -- Subgroup (child of Kumasi Central) -----------------------------
        // A real second tier so group-scoped grants + include_descendants are
        // demonstrable: a grant on the parent (with descendants) reaches here,
        // a grant confined to this subgroup does not reach the parent.
        $subGroupId = Uuid::v7();
        $this->db->table('groups')->insert([
            'id'              => $subGroupId,
            'organization_id' => $orgId,
            'parent_id'       => $groupId,
            'name'            => 'Kumasi Central — Youth',
            'slug'            => 'kumasi-central-youth',
            'type'            => 'chapter',
            'depth'           => 2,
            'path'            => '/' . $groupId . '/' . $subGroupId . '/',
            'leader_user_id'  => $users[1],
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        // Closure rows: self (distance 0) + ancestor edge to the parent (1).
        $this->db->table('group_closure')->insert([
            'ancestor_id' => $subGroupId, 'descendant_id' => $subGroupId, 'distance' => 0,
        ]);
        $this->db->table('group_closure')->insert([
            'ancestor_id' => $groupId, 'descendant_id' => $subGroupId, 'distance' => 1,
        ]);

        // -- Community posts ------------------------------------------------
        $this->insertPost($orgId, $groupId, $users[0], self::SENTINEL,
            'Glad to have everyone here. Share your wins for the week!', 'public', $now, pinned: true);
        $this->insertPost($orgId, $groupId, $users[1], 'Follow-up drive Saturday',
            'We are visiting new contacts this weekend — sign up on the events page.', 'group', $now);
        $this->insertPost($orgId, $groupId, $users[2], 'Course completions 🎉',
            'Three members finished the leadership track this month. Well done!', 'group', $now);

        // -- Event + registrations -----------------------------------------
        $eventId = Uuid::v7();
        $this->db->table('events')->insert([
            'id'                  => $eventId,
            'organization_id'     => $orgId,
            'group_id'            => $groupId,
            'title'               => 'Monthly Gathering',
            'slug'                => 'monthly-gathering',
            'description'         => 'Our flagship monthly gathering with worship, teaching and fellowship.',
            'type'                => 'gathering',
            'mode'                => 'hybrid',
            'timezone'            => 'Africa/Accra',
            'starts_at'           => date('Y-m-d H:i:s', strtotime('+7 days')),
            'ends_at'             => date('Y-m-d H:i:s', strtotime('+7 days +2 hours')),
            'capacity'            => 100,
            'registration_policy' => 'open',
            'attendance_policy'   => 'qr',
            'status'              => 'published',
            'created_by'          => $users[0],
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        // 3 confirmed registrations, 1 positive-RSVP pending.
        foreach (array_slice($users, 0, 3) as $u) {
            $this->db->table('event_registrations')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'event_id'        => $eventId,
                'user_id'         => $u,
                'status'          => 'registered',
                'rsvp_state'      => 'yes',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
        $this->db->table('event_registrations')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'event_id'        => $eventId,
            'user_id'         => $users[3],
            'status'          => 'pending',
            'rsvp_state'      => 'yes',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        // -- Cause + verified contributions --------------------------------
        $causeId = Uuid::v7();
        $this->db->table('causes')->insert([
            'id'              => $causeId,
            'organization_id' => $orgId,
            'group_id'        => $groupId,
            'name'            => 'New Community Hall',
            'purpose'         => 'Raise funds toward building a shared community hall.',
            'visibility'      => 'group',
            'currency'        => 'GHS',
            'target_minor'    => 5000000, // GHS 50,000.00
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        foreach ([250000, 500000, 125000] as $i => $amount) {
            $this->db->table('contributions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'cause_id'        => $causeId,
                'intent_id'       => null,
                'user_id'         => $users[$i],
                'amount_minor'    => $amount,
                'currency'        => 'GHS',
                'fee_minor'       => 0,
                'net_minor'       => $amount,
                'source'          => 'demo',
                'recognition'     => 'public',
                'state'           => 'succeeded',
                'verified_at'     => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        // -- Course ---------------------------------------------------------
        $courseId = Uuid::v7();
        $this->db->table('courses')->insert([
            'id'              => $courseId,
            'organization_id' => $orgId,
            'group_id'        => $groupId,
            'title'           => 'Foundations of Leadership',
            'slug'            => 'foundations-of-leadership',
            'category'        => 'leadership',
            'description'     => 'A five-lesson introduction to servant leadership.',
            'delivery_mode'   => 'self_paced',
            'enrollment_policy' => 'open',
            'completion_rule' => json_encode(['required_lessons' => 'all'], JSON_UNESCAPED_UNICODE),
            'status'          => 'published',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        // -- Gamification season + points ----------------------------------
        // Idempotent: AdminAccountSeeder (foundation) already creates the current
        // season, and `gamification_seasons.gs_year_uq` is UNIQUE (org, year).
        // Reuse it if present so re-running the demo seeder — or running it after
        // the foundation set — doesn't collide on the unique key.
        $year   = (int) date('Y');
        $season = $this->db->table('gamification_seasons')
            ->where('organization_id', $orgId)->where('season_year', $year)
            ->get()->getRowArray();
        if ($season !== null) {
            $seasonId = $season['id'];
        } else {
            $seasonId = Uuid::v7();
            $this->db->table('gamification_seasons')->insert([
                'id'              => $seasonId,
                'organization_id' => $orgId,
                'season_year'     => $year,
                'status'          => 'active',
                'starts_at'       => "{$year}-01-01 00:00:00",
                'ends_at'         => ($year + 1) . '-01-01 00:00:00',
                'created_at'      => $now,
            ]);
        }
        foreach ($users as $i => $u) {
            $this->db->table('point_ledger')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'season_id'       => $seasonId,
                'subject_id'      => $u,
                'subject_type'    => 'user',
                'rule_id'         => 'demo-seed-rule',
                'rule_version'    => 1,
                'entry_type'      => 'award',
                'points'          => (5 - $i) * 50,
                'source_ref'      => 'demo:seed:' . $i,
                'state'           => 'final',
                'explanation'     => 'Demo seed points',
                'archived'        => 0,
                'created_at'      => $now,
            ]);
        }

        // -- Rank ladder ----------------------------------------------------
        // So /me/dashboard can resolve a member's rank + "points to next".
        foreach ([
            ['starter', 'Starter', 0, 0, '#94a3b8'],
            ['builder', 'Builder', 100, 1, '#22d3ee'],
            ['leader', 'Leader', 200, 2, '#a78bfa'],
            ['sender', 'Sender', 400, 3, '#f59e0b'],
        ] as [$code, $name, $min, $sort, $color]) {
            $this->db->table('rank_definitions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'code'            => $code,
                'name'            => $name,
                'min_points'      => $min,
                'sort_order'      => $sort,
                'icon'            => null,
                'color'           => $color,
                'status'          => 'active',
                'created_at'      => $now,
            ]);
        }

        // -- Gamification catalog: badges, achievements, streaks -----------
        // So the enriched /me/dashboard "Badges & achievements" and "Streaks"
        // sections render real content (not just point/rank numbers).
        $badgeId = Uuid::v7();
        $this->db->table('badges')->insert([
            'id'              => $badgeId,
            'organization_id' => $orgId,
            'group_id'        => null,
            'code'            => 'first_event',
            'name'            => 'First Gathering',
            'criteria'        => null,
            'visibility'      => 'public',
            'permanent'       => 1,
            'created_at'      => $now,
        ]);
        // Achievement definitions (one easily-unlocked, one in-progress teaser).
        $achFirstStep = Uuid::v7();
        $this->db->table('achievement_definitions')->insert([
            'id'              => $achFirstStep,
            'organization_id' => $orgId,
            'code'            => 'first_step',
            'name'            => 'First Step',
            'description'     => 'Attend your first event.',
            'category'        => 'engagement',
            'trigger_type'    => 'count',
            'trigger_config'  => json_encode(['count' => 1, 'activity_code' => 'event_attended']),
            'xp'              => 50,
            'bonus_points'    => 0,
            'secret'          => 0,
            'status'          => 'active',
            'sort_order'      => 1,
            'created_at'      => $now,
        ]);
        $achConnector = Uuid::v7();
        $this->db->table('achievement_definitions')->insert([
            'id'              => $achConnector,
            'organization_id' => $orgId,
            'code'            => 'connector',
            'name'            => 'Connector',
            'description'     => 'Attend five events.',
            'category'        => 'engagement',
            'trigger_type'    => 'count',
            'trigger_config'  => json_encode(['count' => 5, 'activity_code' => 'event_attended']),
            'xp'              => 200,
            'bonus_points'    => 0,
            'secret'          => 0,
            'status'          => 'active',
            'sort_order'      => 2,
            'created_at'      => $now,
        ]);
        // Streak definition.
        $this->db->table('streak_definitions')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'code'            => 'weekly_gathering',
            'name'            => 'Weekly Gathering',
            'description'     => 'Show up every week.',
            'cadence'         => 'weekly',
            'default_grace_days' => 1,
            'status'          => 'active',
            'sort_order'      => 1,
            'created_at'      => $now,
        ]);

        // Award the catalog to user[0] (Ama): badge, unlocked achievement,
        // an in-progress achievement, and an active streak.
        $this->db->table('badge_awards')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'badge_id'        => $badgeId,
            'subject_id'      => $users[0],
            'season_id'       => $seasonId,
            'source_ref'      => 'demo:seed',
            'state'           => 'awarded',
            'visibility'      => 'public',
            'awarded_at'      => date('Y-m-d H:i:s', strtotime('-29 days')),
        ]);
        $this->db->table('user_achievements')->insert([
            'id'               => Uuid::v7(),
            'organization_id'  => $orgId,
            'achievement_id'   => $achFirstStep,
            'achievement_code' => 'first_step',
            'subject_id'       => $users[0],
            'season_id'        => $seasonId,
            'xp_awarded'       => 50,
            'bonus_points_awarded' => 0,
            'trigger_source_ref' => 'demo:seed',
            'unlocked_at'      => date('Y-m-d H:i:s.u', strtotime('-29 days')),
        ]);
        $this->db->table('user_achievement_progress')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'achievement_id'  => $achConnector,
            'subject_id'      => $users[0],
            'current_value'   => 1,
            'required_value'  => 5,
            'percentage'      => 20,
            'updated_at'      => date('Y-m-d H:i:s.u', strtotime('-1 day')),
        ]);
        $this->db->table('user_streaks')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'subject_id'      => $users[0],
            'streak_code'     => 'weekly_gathering',
            'season_id'       => $seasonId,
            'current_count'   => 3,
            'best_count'      => 4,
            'last_event_date' => date('Y-m-d', strtotime('-2 days')),
            'updated_at'      => $now,
        ]);

        // -- Group memberships ---------------------------------------------
        // First three users belong to Kumasi Central (user[0] is the leader).
        foreach ([[$users[0], 'leader'], [$users[1], 'member'], [$users[2], 'member']] as [$u, $role]) {
            $this->db->table('group_members')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'group_id'        => $groupId,
                'user_id'         => $u,
                'role'            => $role,
                'joined_at'       => $now,
            ]);
        }

        // -- Role assignments (SRS FR-ACL-003/004) --------------------------
        // The PDP now enforces role_assignments.scope_group_id +
        // include_descendants, so the demo seeds concrete, correctly-scoped
        // grants that exercise every branch of the hierarchy check:
        //
        //   user[0] -> org_admin        , scope = org-wide (NULL)
        //              full-org administrator; covers every group + org-wide
        //              actions (e.g. runtime config, follow-up methods).
        //   user[1] -> moderator        , scope = Kumasi Central, +descendants
        //              a chapter leader: may manage gamification config for the
        //              chapter AND its Youth subgroup, but not other branches
        //              and not org-wide-only surfaces.
        //   user[2] -> moderator        , scope = Youth subgroup, NO descendants
        //              a subgroup leader confined to exactly that subgroup.
        //   user[3] -> analyst          , scope = org-wide (NULL)
        //              read/export reporting across the org.
        //
        // Roles were created by RbacBootstrapSeeder; look them up by (org, code).
        $roleIdByCode = [];
        foreach (['org_admin', 'moderator', 'analyst'] as $code) {
            $r = $this->db->table('roles')
                ->where('organization_id', $orgId)->where('code', $code)
                ->get()->getRowArray();
            if ($r !== null) {
                $roleIdByCode[$code] = $r['id'];
            }
        }

        $assignments = [
            [$users[0], 'org_admin', null,        0],
            [$users[1], 'moderator', $groupId,    1],
            [$users[2], 'moderator', $subGroupId, 0],
            [$users[3], 'analyst',   null,        0],
        ];
        foreach ($assignments as [$subjectId, $roleCode, $scopeGroupId, $includeDescendants]) {
            if (! isset($roleIdByCode[$roleCode])) {
                continue; // role missing (bootstrap not run) — skip defensively
            }
            // Idempotent on the UNIQUE (subject_id, role_id, scope_group_id).
            $existing = $this->db->table('role_assignments')
                ->where('subject_id', $subjectId)
                ->where('role_id', $roleIdByCode[$roleCode])
                ->where('scope_group_id', $scopeGroupId)
                ->get()->getRowArray();
            if ($existing !== null) {
                continue;
            }
            $this->db->table('role_assignments')->insert([
                'id'                  => Uuid::v7(),
                'organization_id'     => $orgId,
                'subject_id'          => $subjectId,
                'role_id'             => $roleIdByCode[$roleCode],
                'scope_group_id'      => $scopeGroupId,
                'status'              => 'active',
                'include_descendants' => $includeDescendants,
                'source'              => 'seed',
                'effective_from'      => $now,
                'effective_to'        => null,
                'created_at'          => $now,
            ]);
        }

        // -- Course enrollments + completions ------------------------------
        // user[0] completed the course; user[1] is still active.
        $enrolledAt = date('Y-m-d H:i:s', strtotime('-40 days'));
        $enr0 = Uuid::v7();
        $this->db->table('enrollments')->insert([
            'id'              => $enr0,
            'organization_id' => $orgId,
            'course_id'       => $courseId,
            'user_id'         => $users[0],
            'status'          => 'completed',
            'enrolled_at'     => $enrolledAt,
            'completed_at'    => date('Y-m-d H:i:s', strtotime('-5 days')),
        ]);
        $this->db->table('course_completions')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'course_id'       => $courseId,
            'enrollment_id'   => $enr0,
            'user_id'         => $users[0],
            'final_score'     => 92,
            'verified'        => 1,
            'created_at'      => date('Y-m-d H:i:s', strtotime('-5 days')),
        ]);
        $this->db->table('enrollments')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'course_id'       => $courseId,
            'user_id'         => $users[1],
            'status'          => 'active',
            'enrolled_at'     => date('Y-m-d H:i:s', strtotime('-10 days')),
            'completed_at'    => null,
        ]);

        // -- Past event + attendance + issued certificates -----------------
        // A completed event so members have real certificates on their dashboard.
        $pastEventId = Uuid::v7();
        $pastStart   = date('Y-m-d H:i:s', strtotime('-30 days'));
        $this->db->table('events')->insert([
            'id'                  => $pastEventId,
            'organization_id'     => $orgId,
            'group_id'            => $groupId,
            'title'               => 'Foundations Retreat',
            'slug'                => 'foundations-retreat',
            'description'         => 'A weekend retreat on the foundations of the community.',
            'type'                => 'gathering',
            'mode'                => 'physical',
            'timezone'            => 'Africa/Accra',
            'starts_at'           => $pastStart,
            'ends_at'             => date('Y-m-d H:i:s', strtotime('-30 days +3 hours')),
            'capacity'            => 60,
            'registration_policy' => 'open',
            'attendance_policy'   => 'qr',
            'status'              => 'completed',
            'created_by'          => $users[0],
            'created_at'          => $pastStart,
            'updated_at'          => $pastStart,
        ]);
        // user[0] and user[1] attended and hold ISSUED certificates.
        $certIds = [];
        foreach ([$users[0], $users[1]] as $u) {
            $this->db->table('event_attendance')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'event_id'        => $pastEventId,
                'user_id'         => $u,
                'method'          => 'manual',
                'checked_in_at'   => $pastStart,
                'status'          => 'present',
                'created_at'      => $pastStart,
            ]);
            $certId    = Uuid::v7();
            $certIds[] = $certId;
            $this->db->table('event_certificates')->insert([
                'id'              => $certId,
                'organization_id' => $orgId,
                'event_id'        => $pastEventId,
                'user_id'         => $u,
                'template_id'     => null, // renderer falls back to default template
                'verification_id' => bin2hex(random_bytes(16)),
                'status'          => 'issued',
                'issued_at'       => date('Y-m-d H:i:s.u', strtotime('-29 days')),
                'created_at'      => date('Y-m-d H:i:s.u', strtotime('-30 days')),
            ]);
        }

        // Pre-render the two certificate PDFs so the /me/dashboard download
        // links work immediately after seeding — instead of waiting for a queue
        // worker. This needs the PDF/QR libraries (dompdf/dompdf, endroid/
        // qr-code); if they aren't installed yet the certs stay issued-but-
        // unrendered (download link simply doesn't show) and the rest of the
        // seed is unaffected.
        $rendered = $this->prerenderCertificates($certIds);

        if (is_cli()) {
            fwrite(STDOUT, "DemoDataSeeder: seeded users/group+subgroup/memberships/role-assignments (org-wide + group-scoped)/posts/events/attendance/certificates/cause/contributions/course/enrollments/season/ranks/badges/achievements/streaks for org {$orgId}.\n");
            fwrite(STDOUT, "DemoDataSeeder: pre-rendered {$rendered}/" . count($certIds) . " certificate PDF(s).\n");
        }
    }

    /**
     * Render the given issued certificates to real PDFs (writable/certificates/)
     * and store each render_ref, so download links work right after seeding.
     *
     * Best-effort: returns the count actually rendered. If the PDF/QR libraries
     * are absent (deps not installed) or any render fails, it degrades quietly —
     * the certificate simply remains issued-but-unrendered.
     *
     * @param list<string> $certificateIds
     */
    private function prerenderCertificates(array $certificateIds): int
    {
        // Avoid a hard dependency at seed time when Composer deps aren't in yet.
        if (! class_exists(\Dompdf\Dompdf::class) || ! class_exists(\Endroid\QrCode\Builder\Builder::class)) {
            if (is_cli()) {
                fwrite(STDOUT, "DemoDataSeeder: PDF libraries not installed; skipping certificate pre-render.\n");
            }

            return 0;
        }

        $renderer = \WBS\Events\Config\Services::certificateRenderer();
        $count    = 0;
        foreach ($certificateIds as $certId) {
            try {
                $ref = $renderer->render($certId);
                $this->db->table('event_certificates')
                    ->where('id', $certId)
                    ->update(['render_ref' => $ref]);
                $count++;
            } catch (\Throwable $e) {
                if (is_cli()) {
                    fwrite(STDERR, "DemoDataSeeder: certificate {$certId} render failed: {$e->getMessage()}\n");
                }
            }
        }

        return $count;
    }

    private function insertPost(string $orgId, string $groupId, string $author, string $title, string $body, string $visibility, string $now, bool $pinned = false): void
    {
        $this->db->table('community_posts')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'group_id'        => $groupId,
            'author_id'       => $author,
            'kind'            => 'post',
            'title'           => $title,
            'body'            => $body,
            'body_html'       => '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>',
            'visibility'      => $visibility,
            'pinned'          => $pinned ? 1 : 0,
            'locked'          => 0,
            'status'          => 'active',
            'reaction_count'  => 0,
            'comment_count'   => 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }
}
