<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Shared\Support\Uuid;

/**
 * Chained account seeder — bootstraps the LEADERSHIP SPINE of the organization
 * straight from the system's configured group hierarchy.
 *
 * WHY: every prospect/member has exactly one active sponsor (upline), and the
 * automatic sponsor for a new registration is the hierarchical group leader — or
 * their delegate (see SponsorResolver / member-onboarding.md). That rule needs a
 * leader to exist at each level of the tree. This seeder creates one, walking the
 * hierarchy top-down and CHAINING the sponsorships so each group's leader is
 * sponsored by the leader of the group ABOVE it:
 *
 *   National leader                (root — no sponsor)
 *     └─ Region leader             sponsored by National leader
 *         └─ Area leader           sponsored by Region leader
 *             └─ Assembly leader   sponsored by Area leader
 *                 └─ Fellowship leader     sponsored by Assembly leader
 *                     └─ Senior Cell leader sponsored by Fellowship leader
 *                         └─ Cell leader   sponsored by Senior Cell leader
 *
 * The result is a fully-formed upline chain from any cell leader up to the
 * national leader, so the moment a real member self-registers under any group,
 * SponsorResolver finds a leader and the sponsorship graph is already connected.
 *
 * HOW it stays consistent with the running system (no shortcuts around invariants):
 *   - Groups are created through GroupService::create(), so depth / path /
 *     group_closure stay correct and org max_group_depth is respected.
 *   - Leader accounts are created through AccountService::register(), so each one
 *     exercises the real registration path AND its automatic-sponsor linking:
 *     we pass the parent group's leader as the explicit sponsor_id, and the
 *     group_id, and let SponsorshipService::assign() write the acyclic,
 *     single-active edge. The national (root) leader has no parent, so it is the
 *     one intentionally sponsor-less account.
 *   - Each leader is recorded as groups.leader_user_id AND given a `leader`
 *     group membership via GroupMembershipService, and the org-leader role is
 *     assigned scoped to that group (include_descendants) so their leadership
 *     scope matches their place in the tree.
 *
 * The hierarchy CHAIN mirrors the WBS model already used by OutreachDemoSeeder:
 * National → Region → Area → Local Assembly → Fellowship → Senior Cell → Cell
 * (fellowship and senior_cell come BEFORE cell). Override it with the
 * `wbs.leadershipChain` env (a comma list of `type:Name` pairs) if a deployment
 * uses different level names.
 *
 * NOT strictly demo-only: this is the leadership skeleton a fresh deployment can
 * start from. It is idempotent (keyed off a sentinel slug) and safe to re-run.
 * Run AFTER migrations + RbacBootstrapSeeder:
 *
 *   php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
 *   php spark db:seed 'WBS\Referrals\Database\Seeds\ChainedAccountSeeder'
 */
class ChainedAccountSeeder extends Seeder
{
    /** Slug pinned on the root node so the seeder is idempotent. */
    private const SENTINEL_SLUG = 'leadership-national';

    /**
     * The default configured chain (top → bottom). Each entry:
     *   [type, group name, leader display name, leader email local-part].
     * The group hierarchy order honours National→…→Fellowship→Senior Cell→Cell.
     *
     * @var list<array{0:string,1:string,2:string,3:string}>
     */
    private const CHAIN = [
        ['national',    'National Church',        'National Overseer',    'national'],
        ['region',      'Greater Accra Region',   'Regional Overseer',    'region'],
        ['area',        'Accra East Area',        'Area Leader',          'area'],
        ['assembly',    'Legon Local Assembly',   'Assembly Pastor',      'assembly'],
        ['fellowship',  'Legon Central Fellowship', 'Fellowship Leader',  'fellowship'],
        ['senior_cell', 'Legon Hall Senior Cell', 'Senior Cell Leader',   'seniorcell'],
        ['cell',        'Legon Hall Cell',        'Cell Leader',          'cell'],
    ];

    /** Shared demo password for every seeded leader account (NOT for prod use). */
    private const LEADER_PASSWORD = 'ChainSeed!2026';

    public function run(): void
    {
        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            $this->out("ChainedAccountSeeder: run RbacBootstrapSeeder first (no 'wbs' org).", true);

            return;
        }
        $orgId = (string) $org['id'];

        // Idempotency guard — the root node's pinned slug.
        if ($this->db->table('groups')
            ->where('organization_id', $orgId)->where('slug', self::SENTINEL_SLUG)
            ->countAllResults() > 0) {
            $this->out('ChainedAccountSeeder: leadership chain already present; skipping.');

            return;
        }

        $chain    = $this->resolveChain();
        $maxDepth = (int) ($org['max_group_depth'] ?? 9);
        if (count($chain) > $maxDepth) {
            $chain = array_slice($chain, 0, $maxDepth);
            $this->out('ChainedAccountSeeder: chain trimmed to org max_group_depth=' . $maxDepth . '.');
        }

        $now      = date('Y-m-d H:i:s');
        $groups   = GroupServices::groups(false);
        $accounts = IdentityServices::accounts();       // real registration + auto-sponsor
        $members  = GroupServices::memberships(false);
        $ships    = ReferralServices::sponsorships();

        $leaderRoleId = $this->leaderRoleId($orgId);

        $parentGroupId  = null;
        $parentLeaderId = null;         // becomes the explicit sponsor of the next level
        $created        = [];

        foreach ($chain as $i => [$type, $groupName, $leaderName, $emailLocal]) {
            // 1) The group node (closure/path/depth handled by the service).
            $gRes = $groups->create($orgId, $parentGroupId, ['name' => $groupName, 'type' => $type]);
            if (! $gRes->ok) {
                $this->out('ChainedAccountSeeder: group create failed at "' . $groupName . '": ' . $gRes->message, true);

                return;
            }
            $groupId = (string) $gRes->data['id'];

            // Pin the sentinel slug on the root so re-runs are detected.
            if ($i === 0) {
                $this->db->table('groups')->where('id', $groupId)->update(['slug' => self::SENTINEL_SLUG]);
            }

            // 2) The leader account, via the REAL registration path. Passing the
            //    parent leader as sponsor_id + this group as group_id makes the
            //    sponsorship CHAIN link this leader to the one above (or, if the
            //    explicit id were ever missing, SponsorResolver would fall back
            //    to the parent group's leader anyway).
            $email  = $emailLocal . '.leader@wbs.test';
            $leaderId = $this->existingUserId($orgId, $email);
            if ($leaderId === null) {
                $reg = $accounts->register($orgId, [
                    'email'          => $email,
                    'password'       => self::LEADER_PASSWORD,
                    'display_name'   => $leaderName,
                    'locale'         => 'en',
                    'timezone'       => (string) ($org['timezone'] ?? 'Africa/Accra'),
                    'initial_status' => 'active',
                    'sponsor_id'     => $parentLeaderId,   // null at the root
                    'group_id'       => $groupId,
                ]);
                if (! $reg->ok) {
                    $this->out('ChainedAccountSeeder: leader register failed for "' . $email . '": ' . $reg->message, true);

                    return;
                }
                $leaderId = (string) $reg->data['user_id'];
            } else {
                // Account already exists (partial prior run): ensure the chained
                // sponsorship is present without overriding an existing upline.
                if ($parentLeaderId !== null && $ships->activeSponsor($leaderId) === null) {
                    $ships->assign($orgId, $leaderId, $parentLeaderId, 'leadership_chain');
                }
            }

            // 3) Make them the group's leader: leader_user_id + a leader membership.
            $this->db->table('groups')->where('id', $groupId)
                ->update(['leader_user_id' => $leaderId, 'updated_at' => $now]);
            $members->add($orgId, $groupId, [
                'user_id'         => $leaderId,
                'role'            => 'leader',
                'membership_type' => 'leader',
                'source'          => 'system',
                'added_by'        => $leaderId,
            ]);

            // 4) Give them the leadership ROLE scoped to their group (with
            //    descendants) so their authority matches their tree position.
            if ($leaderRoleId !== null) {
                $this->assignScopedRole($orgId, $leaderId, $leaderRoleId, $groupId, $now);
            }

            $created[] = ['name' => $groupName, 'leader' => $leaderName, 'group_id' => $groupId, 'leader_id' => $leaderId];

            // Descend.
            $parentGroupId  = $groupId;
            $parentLeaderId = $leaderId;
        }

        $this->verifyAndReport($created);
    }

    /**
     * The configured leadership chain. Honours `wbs.leadershipChain` when set:
     * a comma-separated list of `type:Name` (leader name + email derived from the
     * type). Falls back to the built-in WBS chain.
     *
     * @return list<array{0:string,1:string,2:string,3:string}>
     */
    private function resolveChain(): array
    {
        $env = (string) (getenv('wbs.leadershipChain') ?: '');
        if (trim($env) === '') {
            return self::CHAIN;
        }

        $chain = [];
        foreach (explode(',', $env) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, ':')) {
                continue;
            }
            [$type, $name] = array_map('trim', explode(':', $pair, 2));
            if ($type === '' || $name === '') {
                continue;
            }
            $local  = preg_replace('/[^a-z0-9]+/', '', strtolower($type)) ?: 'leader';
            $chain[] = [$type, $name, ucwords(str_replace(['-', '_'], ' ', $type)) . ' Leader', $local];
        }

        return $chain !== [] ? $chain : self::CHAIN;
    }

    /** Look up the org's leadership role id (prefer org_admin, else member). */
    private function leaderRoleId(string $orgId): ?string
    {
        foreach (['org_admin', 'member'] as $code) {
            $r = $this->db->table('roles')
                ->where('organization_id', $orgId)->where('code', $code)
                ->get()->getRowArray();
            if ($r !== null) {
                return (string) $r['id'];
            }
        }

        return null;
    }

    private function existingUserId(string $orgId, string $email): ?string
    {
        $row = $this->db->table('users')
            ->where('organization_id', $orgId)->where('email', strtolower($email))
            ->get()->getRowArray();

        return $row !== null ? (string) $row['id'] : null;
    }

    /** Idempotent group-scoped role assignment (UNIQUE subject/role/scope). */
    private function assignScopedRole(string $orgId, string $subjectId, string $roleId, string $groupId, string $now): void
    {
        if (! $this->db->tableExists('role_assignments')) {
            return;
        }
        $existing = $this->db->table('role_assignments')
            ->where('subject_id', $subjectId)
            ->where('role_id', $roleId)
            ->where('scope_group_id', $groupId)
            ->countAllResults();
        if ($existing > 0) {
            return;
        }
        $this->db->table('role_assignments')->insert([
            'id'                  => Uuid::v7(),
            'organization_id'     => $orgId,
            'subject_id'          => $subjectId,
            'role_id'             => $roleId,
            'scope_group_id'      => $groupId,
            'status'              => 'active',
            'include_descendants' => 1,
            'source'              => 'seed',
            'effective_from'      => $now,
            'effective_to'        => null,
            'created_at'          => $now,
        ]);
    }

    /**
     * Verify the chained sponsorships resolved as expected and print a summary.
     *
     * @param list<array{name:string,leader:string,group_id:string,leader_id:string}> $created
     */
    private function verifyAndReport(array $created): void
    {
        $ships = ReferralServices::sponsorships();

        $lines = [];
        $ok    = true;
        foreach ($created as $i => $node) {
            $sponsor = $ships->activeSponsor($node['leader_id']);
            if ($i === 0) {
                $ok = $ok && $sponsor === null;
                $lines[] = sprintf('  %s — %s (root, sponsor: %s)', $node['name'], $node['leader'], $sponsor ?? 'none');
            } else {
                $expected = $created[$i - 1]['leader_id'];
                $good     = $sponsor === $expected;
                $ok       = $ok && $good;
                $lines[]  = sprintf(
                    '  %s — %s (sponsor: %s%s)',
                    $node['name'],
                    $node['leader'],
                    $sponsor === $expected ? $created[$i - 1]['leader'] : ($sponsor ?? 'none'),
                    $good ? '' : ' [UNEXPECTED]'
                );
            }
        }

        // Deepest leader's full upline should be every ancestor leader, nearest first.
        if ($created !== []) {
            $deepest = end($created);
            $upline  = $ships->upline($deepest['leader_id']);
            $lines[] = sprintf('  upline(%s) = %d level(s)', $deepest['leader'], count($upline));
        }

        $this->out('ChainedAccountSeeder: seeded ' . count($created) . ' hierarchical groups + chained leader accounts'
            . ($ok ? ' (sponsorship chain verified).' : ' (WARNING: chain verification found a mismatch).'));
        foreach ($lines as $l) {
            $this->out($l);
        }
    }

    private function out(string $msg, bool $err = false): void
    {
        if (! is_cli()) {
            return;
        }
        fwrite($err ? STDERR : STDOUT, $msg . "\n");
    }
}
