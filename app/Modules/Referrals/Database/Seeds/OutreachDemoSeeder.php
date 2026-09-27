<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Geo\Config\Services as GeoServices;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Shared\Support\Uuid;

/**
 * Demo data for the location-based group directory + address-book / outreach.
 *
 * Seeds a HIERARCHICAL GROUP CHAIN with per-group location + contact details so
 * /g can be presented as a directory by location, plus a member address book of
 * downline CONTACTS at varied journey stages / follow-up temperatures, with
 * consent-gated GPS on a couple, and dated DECISIONS (including join_group).
 *
 * NOT for production. Idempotent by group name: re-runs find-or-create the chain
 * and attach venues when `primary_venue_id` is missing. Run AFTER migrations +
 * RbacBootstrapSeeder:
 *
 *   php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
 *   php spark db:seed 'WBS\Referrals\Database\Seeds\OutreachDemoSeeder'
 *
 * Uses GroupService::create so the closure table / path / depth stay correct;
 * ContactBookService for contacts + consent-gated coordinates.
 */
class OutreachDemoSeeder extends Seeder
{
    private const SENTINEL_SLUG = 'gh-national';

    public function run(): void
    {
        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            $this->out("OutreachDemoSeeder: run RbacBootstrapSeeder first (no 'wbs' org).", true);

            return;
        }
        $orgId = (string) $org['id'];

        $now    = date('Y-m-d H:i:s');
        $groups = GroupServices::groups(false);

        // -- Hierarchical group chain --------------------------------------
        // National -> Region -> Area/District -> Local Assembly -> Cell
        // Each node gets a location bucket + contact block for the directory.
        $chain = [
            ['name' => 'Ghana National',      'type' => 'national',  'loc' => 'Ghana',          'bucket' => 'Ghana',         'phone' => '+233 30 000 0000', 'email' => 'national@wbs.test',      'lat' => 7.9465,  'lng' => -1.0232],
            ['name' => 'Greater Accra Region', 'type' => 'region',   'loc' => 'Accra',          'bucket' => 'Greater Accra', 'phone' => '+233 30 111 1111', 'email' => 'accra.region@wbs.test',  'lat' => 5.6037,  'lng' => -0.1870],
            ['name' => 'Accra East Area',      'type' => 'area',     'loc' => 'East Legon, Accra', 'bucket' => 'Greater Accra', 'phone' => '+233 30 222 2222', 'email' => 'accra.east@wbs.test', 'lat' => 5.6500,  'lng' => -0.1500],
            ['name' => 'Legon Local Assembly', 'type' => 'assembly',   'loc' => 'Legon, Accra',      'bucket' => 'Greater Accra', 'phone' => '+233 30 333 3333', 'email' => 'legon@wbs.test',           'lat' => 5.6510, 'lng' => -0.1870],
            // Below the assembly the chain deepens: Fellowship -> Senior Cell -> Cell.
            ['name' => 'Legon Central Fellowship', 'type' => 'fellowship', 'loc' => 'Legon, Accra',  'bucket' => 'Greater Accra', 'phone' => '+233 24 400 0001', 'email' => 'legon.fellowship@wbs.test', 'lat' => 5.6512, 'lng' => -0.1872],
            ['name' => 'Legon Hall Senior Cell', 'type' => 'senior_cell', 'loc' => 'Legon Hall, Accra', 'bucket' => 'Greater Accra', 'phone' => '+233 24 400 0002', 'email' => 'legon.seniorcell@wbs.test', 'lat' => 5.6516, 'lng' => -0.1876],
            ['name' => 'Legon Hall Cell',      'type' => 'cell',       'loc' => 'Legon Hall, Accra', 'bucket' => 'Greater Accra', 'phone' => '+233 24 444 4444', 'email' => 'legon.cell@wbs.test',      'lat' => 5.6520, 'lng' => -0.1880],
        ];

        // A parallel Ashanti branch off the national node, so the directory shows
        // more than one location bucket.
        $ashanti = [
            ['name' => 'Ashanti Region',        'type' => 'region',   'loc' => 'Kumasi',        'bucket' => 'Ashanti',       'phone' => '+233 32 555 5555', 'email' => 'ashanti@wbs.test',       'lat' => 6.6666,  'lng' => -1.6163],
            ['name' => 'Kumasi Central Assembly','type' => 'assembly','loc' => 'Adum, Kumasi',  'bucket' => 'Ashanti',       'phone' => '+233 32 666 6666', 'email' => 'kumasi.central@wbs.test','lat' => 6.6906,  'lng' => -1.6248],
        ];

        $parentId = null;
        $ids      = [];
        foreach ($chain as $node) {
            $gid = $this->findOrCreateLocatedGroup($groups, $orgId, $parentId, $node, $now, $node['name'] === 'Ghana National' ? self::SENTINEL_SLUG : null);
            if ($gid === null) {
                return;
            }
            $ids[$node['name']] = $gid;
            $parentId = $gid; // deepen the chain
        }

        // Ashanti hangs off National (index 0) and then deepens.
        $ashParent = $ids['Ghana National'];
        foreach ($ashanti as $node) {
            $gid = $this->findOrCreateLocatedGroup($groups, $orgId, $ashParent, $node, $now, null);
            if ($gid === null) {
                return;
            }
            $ids[$node['name']] = $gid;
            $ashParent = $gid;
        }

        // -- A member who owns an address book -----------------------------
        $memberId = $this->findOrCreateUser($orgId, 'evangelist@example.test', 'Yaa Serwaa', $now);
        $this->ensureMembership($orgId, $ids['Legon Hall Cell'], $memberId, 'leader', $now);

        // -- Downline contacts (address book) via ContactBookService -------
        $cb          = ReferralServices::contactBook();
        $legonCell   = $ids['Legon Hall Cell'];
        $kumasiCentr = $ids['Kumasi Central Assembly'];

        $contacts = [
            // [name, phone, stage, temp, group, invite type, next f/u days, lat, lng, verbal, confirmed]
            ['Kofi Boateng',  '+233 24 100 0001', 'prospect',     'hot',  $legonCell,   'event',  2,  5.6521, -0.1881, true,  true],
            ['Abena Asante',  '+233 24 100 0002', 'first_timer',  'warm', $legonCell,   'course', 7,  null,    null,   false, false],
            ['Yaw Darko',     '+233 24 100 0003', 'new_believer', 'warm', $kumasiCentr, 'cause',  14, null,    null,   false, false],
            ['Esi Owusu',     '+233 24 100 0004', 'prospect',     'cold', $kumasiCentr, null,     30, null,    null,   false, false],
            ['Ama Mensah',    '+233 24 100 0005', 'foundation',   'hot',  $legonCell,   'group',  1,  5.6519, -0.1879, true,  true],
        ];

        $contactIds = [];
        foreach ($contacts as [$name, $phone, $stage, $temp, $gid, $ctx, $days, $lat, $lng, $verbal, $confirmed]) {
            $res = $cb->createContact($orgId, [
                'owner_user_id'            => $memberId,
                'created_by'               => $memberId,
                'source'                   => 'member',
                'full_name'                => $name,
                'phone'                    => $phone,
                'assigned_group_id'        => $gid,
                'journey_stage'            => $stage,
                'temperature'              => $temp,
                'invite_context_type'      => $ctx,
                'next_follow_up_at'        => date('Y-m-d H:i:s', strtotime("+{$days} days")),
                'consent'                  => true,
                'latitude'                 => $lat,
                'longitude'                => $lng,
                'coords_consent_verbal'    => $verbal,
                'coords_consent_confirmed' => $confirmed,
            ]);
            if ($res->ok) {
                $contactIds[$name] = (string) $res->data['id'];
            }
        }

        // -- Dated decisions (>=3 for a contact; one is join_group) ---------
        if (isset($contactIds['Kofi Boateng'])) {
            $cid = $contactIds['Kofi Boateng'];
            $cb->recordDecision($cid, $memberId, ['decision_type' => 'salvation',     'decision_date' => date('Y-m-d', strtotime('-40 days')), 'note' => 'Responded at an outreach event.']);
            $cb->recordDecision($cid, $memberId, ['decision_type' => 'water_baptism', 'decision_date' => date('Y-m-d', strtotime('-14 days'))]);
            $cb->recordDecision($cid, $memberId, ['decision_type' => 'foundation_course', 'decision_date' => date('Y-m-d', strtotime('-9 days')), 'note' => 'Enrolled in the foundation class.']);
            $cb->recordDecision($cid, $memberId, ['decision_type' => 'join_group',    'decision_date' => date('Y-m-d', strtotime('-7 days')), 'target_group_id' => $legonCell, 'note' => 'Decided to join the Legon Hall Cell.']);
        }
        if (isset($contactIds['Ama Mensah'])) {
            $cid = $contactIds['Ama Mensah'];
            $cb->recordDecision($cid, $memberId, ['decision_type' => 'salvation',           'decision_date' => date('Y-m-d', strtotime('-120 days'))]);
            $cb->recordDecision($cid, $memberId, ['decision_type' => 'rededication',        'decision_date' => date('Y-m-d', strtotime('-30 days'))]);
            $cb->recordDecision($cid, $memberId, ['decision_type' => 'holy_spirit_baptism', 'decision_date' => date('Y-m-d', strtotime('-20 days'))]);
            $cb->recordDecision($cid, $memberId, ['decision_type' => 'join_group',          'decision_date' => date('Y-m-d', strtotime('-10 days')), 'target_group_id' => $legonCell]);
        }

        // -- Attendance registrations within follow-up (if a demo event/course
        //    exists). Optional: only runs when there's something to register for,
        //    so this seeder never depends on Events/Courses demo data.
        $attend = 0;
        // Prefer a PUBLISHED event: follow-up attendance now flows through the
        // platform registrar, which (correctly) refuses draft/closed events.
        $event  = $this->db->table('events')->where('organization_id', $orgId)->where('status', 'published')->get(1)->getRowArray();
        $course = $this->db->table('courses')->where('organization_id', $orgId)->get(1)->getRowArray();
        if (isset($contactIds['Kofi Boateng']) && $event !== null) {
            $r = $cb->recordAttendance($contactIds['Kofi Boateng'], $memberId, [
                'type' => 'event', 'target_id' => (string) $event['id'], 'rsvp_state' => 'yes',
                'group_attribution' => $legonCell,
            ]);
            $attend += $r->ok ? 1 : 0;
        }
        if (isset($contactIds['Abena Asante']) && $course !== null) {
            $r = $cb->recordAttendance($contactIds['Abena Asante'], $memberId, [
                'type' => 'course', 'target_id' => (string) $course['id'],
            ]);
            $attend += $r->ok ? 1 : 0;
        }

        $this->out('OutreachDemoSeeder: seeded ' . count($ids) . ' groups, '
            . count($contactIds) . ' contacts, decisions, ' . $attend . ' attendance registrations.');
    }

    /**
     * @param array<string,mixed> $node
     */
    private function findOrCreateLocatedGroup(object $groups, string $orgId, ?string $parentId, array $node, string $now, ?string $slug): ?string
    {
        $row = $this->db->table('groups')
            ->select('id, primary_venue_id')
            ->where('organization_id', $orgId)
            ->where('name', $node['name'])
            ->get(1)->getRowArray();
        if ($row !== null) {
            $gid = (string) $row['id'];
            if (empty($row['primary_venue_id'])) {
                $this->applyGroupLocation($orgId, $gid, $node, $now, $slug);
            } elseif ($slug !== null) {
                $this->db->table('groups')->where('id', $gid)->update(['slug' => $slug, 'updated_at' => $now]);
            }

            return $gid;
        }
        $res = $groups->create($orgId, $parentId, ['name' => $node['name'], 'type' => $node['type']]);
        if (! $res->ok) {
            $this->out('OutreachDemoSeeder: group create failed: ' . $node['name'] . ' -> ' . $res->message, true);

            return null;
        }
        $gid = (string) $res->data['id'];
        $this->applyGroupLocation($orgId, $gid, $node, $now, $slug);

        return $gid;
    }

    /**
     * Public profile on the group; physical place on a VENUE (never denormalized
     * onto `groups` — geo lives on venues via primary_venue_id).
     *
     * @param array<string,mixed> $node
     */
    private function applyGroupLocation(string $orgId, string $groupId, array $node, string $now, ?string $slug): void
    {
        $fields = [
            'location_text' => $node['loc'],
            'contact_phone' => $node['phone'],
            'contact_email' => $node['email'],
            'tagline'       => $node['type'] === 'cell' ? 'A place to belong' : null,
            'join_policy'   => 'open',
            'updated_at'    => $now,
        ];
        if ($slug !== null) {
            $fields['slug'] = $slug;
        }
        $this->db->table('groups')->where('id', $groupId)->update($fields);

        $country = $this->db->table('countries')->where('iso2', 'GH')->get()->getRowArray();
        $state   = $this->db->table('states')
            ->where('name', (string) $node['bucket'])
            ->get()->getRowArray();
        $created = GeoServices::location(false)->createVenue($orgId, [
            'name'             => (string) $node['name'] . ' — meeting place',
            'venue_type'       => 'church',
            'status'           => 'active',
            'mode'             => 'physical',
            'address_text'     => $node['loc'],
            'contact_phone'    => $node['phone'],
            'contact_email'    => $node['email'],
            'latitude'         => $node['lat'],
            'longitude'        => $node['lng'],
            'timezone'         => 'Africa/Accra',
            'discovery_status' => 'public',
            'country_id'       => $country['id'] ?? null,
            'state_id'         => $state['id'] ?? null,
        ]);
        if (! $created->ok) {
            $this->out('OutreachDemoSeeder: venue create failed for ' . $node['name'] . ': ' . $created->message, true);

            return;
        }
        $venueId = (string) ($created->data['id'] ?? '');
        if ($venueId === '') {
            return;
        }
        // Stamp labels even if reverse-geocode is unavailable (demo / offline).
        $stamp = [
            'country_id'    => $country['id'] ?? null,
            'country_label' => $country['name'] ?? 'Ghana',
            'state_id'      => $state['id'] ?? null,
            'state_label'   => $node['bucket'],
            'city_label'    => $node['loc'],
        ];
        $this->db->table('venues')->where('id', $venueId)->update($stamp);
        GeoServices::location(false)->assignVenueToGroup($orgId, $venueId, $groupId, 'primary');
    }

    private function ensureMembership(string $orgId, string $groupId, string $userId, string $role, string $now): void
    {
        $existing = $this->db->table('group_members')
            ->where('organization_id', $orgId)
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->get()->getRowArray();
        if ($existing !== null) {
            return;
        }
        $this->db->table('group_members')->insert([
            'id'               => Uuid::v7(),
            'organization_id'  => $orgId,
            'group_id'         => $groupId,
            'user_id'          => $userId,
            'role'             => $role,
            'membership_type'  => $role === 'leader' ? 'leader' : 'member',
            'status'           => 'active',
            'source'           => 'seed',
            'approval_state'   => 'approved',
            'joined_at'        => $now,
            'updated_at'       => $now,
        ]);
    }

    private function findOrCreateUser(string $orgId, string $email, string $name, string $now): string
    {
        $row = $this->db->table('users')
            ->where('organization_id', $orgId)->where('email', $email)->get()->getRowArray();
        if ($row !== null) {
            return (string) $row['id'];
        }

        $id = Uuid::v7();
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

        return $id;
    }

    private function out(string $msg, bool $err = false): void
    {
        if (! is_cli()) {
            return;
        }
        fwrite($err ? STDERR : STDOUT, $msg . "\n");
    }
}
