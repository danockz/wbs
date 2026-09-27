<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\Uuid;

/**
 * Seeds a sensible default group-kind taxonomy (leadership-responsibility model,
 * option B). Kinds classify WHAT a group is, independently of placement.
 *
 * Defaults cover the motivating examples:
 *   - department  — management + staff/volunteers pursuing targets; usually
 *                   cross-cutting across branches.
 *   - activity_team — activity-specific groups (e.g. physical/cyber security)
 *                   with their own leadership that hold events/meetings.
 *   - ministry, team, committee, network — other common kinds.
 *
 * Idempotent: upsert on (organization_id, code). Runs for the default 'wbs' org
 * if present; otherwise for every organization so multi-org bootstraps are
 * covered. Adding a kind never changes access scope.
 */
class GroupKindSeeder extends Seeder
{
    /** code => [name, default_placement, description, sort_order] */
    private const KINDS = [
        'department'    => ['Department', 'crosscut', 'Management plus staff/volunteers working to achieve targets; typically spans branches.', 10],
        'activity_team' => ['Activity Team', 'crosscut', 'Activity-specific group (e.g. physical or cyber security) with its own leadership, events and meetings.', 20],
        'ministry'      => ['Ministry', 'either', 'A ministry or service group.', 30],
        'team'          => ['Team', 'either', 'A general working team.', 40],
        'committee'     => ['Committee', 'either', 'A governance or oversight committee.', 50],
        'network'       => ['Network', 'crosscut', 'A network drawing members from many groups (e.g. youth network).', 60],
    ];

    public function run(): void
    {
        $now = date('Y-m-d H:i:s.u');

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
            foreach (self::KINDS as $code => [$name, $placement, $description, $sort]) {
                $existing = $this->db->table('group_kinds')
                    ->where('organization_id', $orgId)->where('code', $code)
                    ->get()->getRowArray();
                if ($existing !== null) {
                    continue; // idempotent — leave operator edits intact
                }
                $this->db->table('group_kinds')->insert([
                    'id'                => Uuid::v7(),
                    'organization_id'   => $orgId,
                    'code'              => $code,
                    'name'              => $name,
                    'description'       => $description,
                    'default_placement' => $placement,
                    'sort_order'        => $sort,
                    'status'            => 'active',
                    'created_at'        => $now,
                ]);
            }
        }
    }
}
