<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\Uuid;

/**
 * Seeds the DEFAULT membership-journey stage ladder (assessment §4.1). The
 * ladder is fully editable afterwards — these rows are just a sensible starting
 * point, stored as org-wide (group_id = NULL) catalog entries.
 *
 * Each stage carries a Win/Build/Send `phase`, so the journey and the activity
 * model share one vocabulary. `is_entry` marks the stage a brand-new journey
 * opens at; `is_terminal` marks the top of the ladder.
 *
 * Idempotent: upsert on (organization_id, group_id=NULL, code). Runs for the
 * default 'wbs' org if present; otherwise for every organization.
 */
class JourneyStageSeeder extends Seeder
{
    /** code => [name, phase, sort, is_entry, is_terminal, description] */
    private const STAGES = [
        'prospect'       => ['Prospect / Contact',   'win',   10, 1, 0, 'A captured lead or first contact not yet converted.'],
        'first_timer'    => ['First-Timer',          'win',   20, 0, 0, 'Attended for the first time; being welcomed.'],
        'new_believer'   => ['New Believer',         'win',   30, 0, 0, 'A new convert; being established in the faith.'],
        'in_foundation'  => ['In Foundation / Growth', 'build', 40, 0, 0, 'Going through foundation/growth classes.'],
        'established'     => ['Established Member',   'build', 50, 0, 0, 'A settled, participating member.'],
        'worker'         => ['Worker / Volunteer',   'build', 60, 0, 0, 'Serving in a ministry or department.'],
        'leader'         => ['Leader',               'send',  70, 0, 0, 'Leading others; carrying responsibility.'],
        'sender'         => ['Sender / Multiplier',  'send',  80, 0, 1, 'Raising and sending other leaders and workers.'],
    ];

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $orgIds = [];
        $wbs = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($wbs !== null) {
            $orgIds[] = (string) $wbs['id'];
        } else {
            foreach ($this->db->table('organizations')->select('id')->get()->getResultArray() as $o) {
                $orgIds[] = (string) $o['id'];
            }
        }

        foreach ($orgIds as $orgId) {
            foreach (self::STAGES as $code => [$name, $phase, $sort, $entry, $terminal, $description]) {
                $existing = $this->db->table('journey_stages')
                    ->where('organization_id', $orgId)
                    ->where('group_id', null)
                    ->where('code', $code)
                    ->get()->getRowArray();
                if ($existing !== null) {
                    continue; // idempotent — leave operator edits intact
                }
                $this->db->table('journey_stages')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $orgId,
                    'group_id'        => null,
                    'code'            => $code,
                    'name'            => $name,
                    'phase'           => $phase,
                    'sort_order'      => $sort,
                    'description'     => $description,
                    'is_terminal'     => $terminal,
                    'is_entry'        => $entry,
                    'icon'            => null,
                    'color'           => null,
                    'status'          => 'active',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }
        }
    }
}
