<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Gamification\Config\Services as GamificationServices;

/**
 * Seeds the Option D disciple-making pieces through the NORMAL gamification
 * services (ConfigService + RuleService), so everything is editable like any
 * other activity and nothing bypasses validation/versioning:
 *
 *   - gamification_config:
 *       disciplemaking_award_enabled = false  (OFF by default — opt-in)
 *       disciplemaking_rule_code     = 'disciple.advance'
 *   - a gamification_rule 'disciple.advance' (SEND phase, LEADERSHIP-style):
 *       the discipler earns points when they move a member forward. Fixed 60 pts
 *       with a small daily cap as an anti-gaming default; fully editable.
 *
 * Idempotent: config upserts; the rule is created only if absent. Run AFTER
 * RbacBootstrapSeeder and (ideally) WbsActivityCatalogSeeder:
 *
 *   composer seed:journey-gamification
 */
class JourneyGamificationSeeder extends Seeder
{
    private const RULE_CODE = 'disciple.advance';

    public function run(): void
    {
        $orgIds = [];
        $wbs = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($wbs !== null) {
            $orgIds[] = (string) $wbs['id'];
        } else {
            foreach ($this->db->table('organizations')->select('id')->get()->getResultArray() as $o) {
                $orgIds[] = (string) $o['id'];
            }
        }

        $config = GamificationServices::config(false);
        $rules  = GamificationServices::rules(false);

        foreach ($orgIds as $orgId) {
            $config->set($orgId, 'disciplemaking_award_enabled', false, [
                'type'        => 'boolean',
                'description' => 'Award the discipler points when a journey transition advances a member.',
            ]);
            $config->set($orgId, 'disciplemaking_rule_code', self::RULE_CODE, [
                'type'        => 'string',
                'description' => 'Gamification rule code used to credit the discipler on an advancing transition.',
            ]);

            $exists = $this->db->table('gamification_rules')
                ->where('organization_id', $orgId)->where('code', self::RULE_CODE)
                ->countAllResults() > 0;
            if ($exists) {
                continue;
            }

            $rules->create($orgId, [
                'code'          => self::RULE_CODE,
                'activity_name' => 'Disciple-Making (moved a member forward)',
                'phase'         => 'send',
                'event_type'    => 'journey.advanced',
                'points'        => 60,
                'point_mode'    => 'fixed',
                'daily_limit'   => 20,
                'explanation'   => 'Credited when a leader/discipler advances a member along the journey.',
            ]);
        }

        $this->say(STDOUT, "JourneyGamificationSeeder: disciple-making config + rule ensured (award OFF by default).\n");
    }

    private function say(mixed $stream, string $msg): void
    {
        if (is_resource($stream)) {
            fwrite($stream, $msg);
        }
    }
}
