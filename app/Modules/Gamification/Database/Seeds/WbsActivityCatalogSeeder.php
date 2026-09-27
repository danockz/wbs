<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Gamification\Config\Services as GamificationServices;

/**
 * Seeds a sensible DEFAULT Win-Build-Send activity catalog: phases → categories →
 * earning activities (rules), plus configurable follow-up types/methods and a
 * few runtime config keys. Everything here is editable at runtime through the
 * admin endpoints — this just gives an org a working starting point (mirroring
 * the "starter template" idea from mature gamification products).
 *
 * Idempotent: define()/set() upsert by code/key, so re-running is safe.
 *
 * Run AFTER migrations + RbacBootstrapSeeder:
 *   php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
 *   php spark db:seed 'WBS\Gamification\Database\Seeds\WbsActivityCatalogSeeder'
 */
class WbsActivityCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            $this->say(STDERR, "WbsActivityCatalogSeeder: run RbacBootstrapSeeder first (no 'wbs' org).\n");

            return;
        }
        $orgId = (string) $org['id'];

        $catalog  = GamificationServices::activityCatalog(false);
        $rules    = GamificationServices::rules(false);
        $followUp = GamificationServices::followUps(false);
        $config   = GamificationServices::config(false);

        // ---- Categories, grouped by Win / Build / Send phase ---------------
        $categories = [
            // WIN — reach & convert new people
            ['code' => 'OUTREACH',   'name' => 'Outreach',        'phase' => 'win',   'icon' => 'megaphone',     'color' => '#EF4444', 'sort_order' => 10],
            ['code' => 'FIRST_TIME', 'name' => 'First Contact',   'phase' => 'win',   'icon' => 'user-plus',     'color' => '#F97316', 'sort_order' => 20],
            // BUILD — disciple & grow existing members
            ['code' => 'ATTENDANCE', 'name' => 'Attendance',      'phase' => 'build', 'icon' => 'calendar-check','color' => '#4CAF50', 'sort_order' => 10],
            ['code' => 'GROWTH',     'name' => 'Spiritual Growth','phase' => 'build', 'icon' => 'trending-up',   'color' => '#9C27B0', 'sort_order' => 20],
            ['code' => 'CARE',       'name' => 'Member Care',     'phase' => 'build', 'icon' => 'heart-handshake','color' => '#2196F3', 'sort_order' => 30],
            // SEND — mobilise members to serve & give
            ['code' => 'LEADERSHIP', 'name' => 'Leadership',      'phase' => 'send',  'icon' => 'star',          'color' => '#FF9800', 'sort_order' => 10],
            ['code' => 'GIVING',     'name' => 'Giving',          'phase' => 'send',  'icon' => 'gift',          'color' => '#F44336', 'sort_order' => 20],
        ];
        $catIds = [];
        foreach ($categories as $c) {
            $res = $catalog->define($orgId, $c);
            $catIds[$c['code']] = is_array($res->data) ? ($res->data['id'] ?? null) : null;
        }

        // ---- Activities (rules). Each is a configurable earning activity ----
        // Shows off all point_modes: fixed, variable (caller value), formula.
        $activities = [
            // WIN
            ['code' => 'outreach.contact',   'activity_name' => 'Outreach Contact',    'phase' => 'win',   'category' => 'OUTREACH',   'event_type' => 'outreach.logged',       'points' => 20, 'point_mode' => 'fixed',    'daily_limit' => 20, 'multipliers' => [['factor' => 1.5, 'when' => ['field' => 'method', 'op' => '==', 'value' => 'visit']], ['factor' => 0.8, 'when' => ['field' => 'method', 'op' => '==', 'value' => 'sms']]]],
            ['code' => 'recruit.newmember',  'activity_name' => 'New Member Recruited', 'phase' => 'win',   'category' => 'FIRST_TIME',  'event_type' => 'member.recruited',      'points' => 100,'point_mode' => 'fixed',    'requires_review' => 1, 'approval_role_code' => 'group_leader'],
            ['code' => 'firsttime.attend',   'activity_name' => 'First-Time Attendance','phase' => 'win',   'category' => 'FIRST_TIME',  'event_type' => 'attendance.firsttime',  'points' => 50, 'point_mode' => 'fixed'],
            // BUILD
            ['code' => 'event.attended',     'activity_name' => 'Event Attendance',    'phase' => 'build', 'category' => 'ATTENDANCE', 'event_type' => 'event.attended',        'points' => 50, 'point_mode' => 'fixed',    'per_period_cap' => 2, 'period' => 'day', 'cooldown_seconds' => 3600],
            ['code' => 'stream.watch',       'activity_name' => 'Streaming Participation','phase' => 'build','category' => 'ATTENDANCE','event_type' => 'stream.watched',        'points' => 25, 'point_mode' => 'formula', 'point_formula' => 'base_points * min(1, duration_minutes / 30)', 'min_points' => 10, 'max_points' => 50],
            ['code' => 'course.completed',   'activity_name' => 'Course Completion',    'phase' => 'build', 'category' => 'GROWTH',     'event_type' => 'course.completed',      'points' => 100,'point_mode' => 'fixed'],
            ['code' => 'foundation.graduate','activity_name' => 'Foundation Graduation','phase' => 'build', 'category' => 'GROWTH',     'event_type' => 'foundation.graduated',  'points' => 75, 'point_mode' => 'fixed'],
            ['code' => 'followup.member',    'activity_name' => 'Member Follow-up',     'phase' => 'build', 'category' => 'CARE',       'event_type' => 'followup.logged',       'points' => 20, 'point_mode' => 'fixed',    'daily_limit' => 10, 'multipliers' => [['factor' => 1.5, 'when' => ['field' => 'method', 'op' => '==', 'value' => 'visit']], ['factor' => 0.9, 'when' => ['field' => 'method', 'op' => '==', 'value' => 'whatsapp']], ['factor' => 0.8, 'when' => ['field' => 'method', 'op' => '==', 'value' => 'sms']]]],
            // SEND
            ['code' => 'leadership.serve',   'activity_name' => 'Leadership Service',   'phase' => 'send',  'category' => 'LEADERSHIP', 'event_type' => 'leadership.served',     'points' => 40, 'point_mode' => 'variable', 'min_points' => 20, 'max_points' => 100],
            ['code' => 'contribution.verified','activity_name' => 'Financial Giving',   'phase' => 'send',  'category' => 'GIVING',     'event_type' => 'contribution.verified', 'points' => 10, 'point_mode' => 'formula', 'point_formula' => 'floor(amount / 10) * base_points', 'min_points' => 5, 'max_points' => 1000],
            ['code' => 'testimony.shared',   'activity_name' => 'Testimony Shared',     'phase' => 'send',  'category' => 'GIVING',     'event_type' => 'testimony.shared',      'points' => 25, 'point_mode' => 'fixed',    'weekly_limit' => 2],
        ];

        foreach ($activities as $a) {
            $code = $a['code'];
            $a['category_id'] = $catIds[$a['category']] ?? null;
            unset($a['category']);

            $existing = $this->db->table('gamification_rules')
                ->where('organization_id', $orgId)->where('code', $code)->countAllResults() > 0;
            $existing ? $rules->update($orgId, $code, $a) : $rules->create($orgId, ['code' => $code] + $a);
        }

        // ---- Follow-up methods (channels) with multiplier keys -------------
        $methods = [
            ['code' => 'call',      'name' => 'Phone Call',     'multiplier_key' => 'call',      'sort_order' => 10],
            ['code' => 'visit',     'name' => 'In-person Visit','multiplier_key' => 'visit',     'sort_order' => 20],
            ['code' => 'whatsapp',  'name' => 'WhatsApp',       'multiplier_key' => 'whatsapp',  'sort_order' => 30],
            ['code' => 'sms',       'name' => 'SMS',            'multiplier_key' => 'sms',       'sort_order' => 40],
            ['code' => 'kingschat', 'name' => 'KingsChat',      'multiplier_key' => 'kingschat', 'sort_order' => 50],
            ['code' => 'email',     'name' => 'Email',          'multiplier_key' => 'email',     'sort_order' => 60],
        ];
        foreach ($methods as $m) {
            $followUp->defineMethod($orgId, $m);
        }

        // ---- Follow-up types, tied to award rules --------------------------
        $types = [
            ['code' => 'new_visitor',       'name' => 'New Visitor',        'phase' => 'win',   'award_rule_code' => 'followup.member', 'default_next_days' => 3,  'requires_outcome' => 1, 'sort_order' => 10],
            ['code' => 'absent_member',     'name' => 'Absent Member',      'phase' => 'build', 'award_rule_code' => 'followup.member', 'default_next_days' => 7,  'sort_order' => 20],
            ['code' => 'prayer_request',    'name' => 'Prayer Request',     'phase' => 'build', 'award_rule_code' => 'followup.member', 'default_next_days' => 2,  'sort_order' => 30],
            ['code' => 'integration_check', 'name' => 'Integration Check',  'phase' => 'build', 'award_rule_code' => 'followup.member', 'default_next_days' => 14, 'sort_order' => 40],
        ];
        foreach ($types as $t) {
            $followUp->defineType($orgId, $t);
        }

        // ---- Runtime config knobs -----------------------------------------
        $config->set($orgId, 'leaderboard_cache_ttl', 300, ['type' => 'integer', 'description' => 'Cache TTL (seconds) for leaderboard']);
        $config->set($orgId, 'achievement_evaluation_mode', 'auto', ['type' => 'string', 'description' => 'auto|manual|scheduled']);
        $config->set($orgId, 'achievement_retroactive_evaluation', true, ['type' => 'boolean', 'description' => 'Evaluate achievements retroactively when new definitions are added']);
        $config->set($orgId, 'followups_award_enabled', true, ['type' => 'boolean', 'description' => 'Award points when a follow-up is recorded']);

        $this->say(STDOUT, sprintf(
            "WbsActivityCatalogSeeder: %d categories, %d activities, %d follow-up methods, %d follow-up types seeded.\n",
            count($categories),
            count($activities),
            count($methods),
            count($types),
        ));
    }

    private function say(mixed $stream, string $msg): void
    {
        if (is_resource($stream)) {
            fwrite($stream, $msg);
        }
    }
}
