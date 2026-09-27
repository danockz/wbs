<?php

declare(strict_types=1);

namespace WBS\AccessControl\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\Uuid;

/**
 * Bootstraps a WORKING first login for a fresh deployment: an org administrator
 * account with an org-wide `org_admin` role assignment, plus the current
 * gamification season so points/awards resolve on day one.
 *
 * Runs AFTER RbacBootstrapSeeder (which creates the default `wbs` organization,
 * the permission catalogue and the roles). Idempotent: keyed by the admin email
 * within the org and by (subject, role) for the assignment.
 *
 * The initial password comes from env `wbs.adminPassword` (or `wbs.adminEmail`);
 * if unset it falls back to a clearly-marked default that MUST be rotated. The
 * account is created `active` + `email_verified` so the operator can sign in
 * immediately, then change the password.
 */
class AdminAccountSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            if (is_cli()) {
                fwrite(STDERR, "AdminAccountSeeder: no 'wbs' organization — run RbacBootstrapSeeder first.\n");
            }

            return;
        }
        $orgId = (string) $org['id'];

        $email    = (string) (getenv('wbs.adminEmail') ?: 'admin@wbs.local');
        $password = (string) (getenv('wbs.adminPassword') ?: 'ChangeMe!Admin1');
        $name     = (string) (getenv('wbs.adminName') ?: 'Platform Administrator');

        // 1. Admin user (idempotent by org + email).
        $user = $this->db->table('users')
            ->where('organization_id', $orgId)->where('email', $email)
            ->get()->getRowArray();
        if ($user === null) {
            $userId = Uuid::v7();
            $this->db->table('users')->insert([
                'id'              => $userId,
                'organization_id' => $orgId,
                'email'           => $email,
                'email_verified'  => 1,
                'password_hash'   => password_hash($password, PASSWORD_BCRYPT),
                'display_name'    => $name,
                'status'          => 'active',
                'locale'          => (string) ($org['default_locale'] ?? 'en'),
                'timezone'        => (string) ($org['timezone'] ?? 'UTC'),
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        } else {
            $userId = (string) $user['id'];
        }

        // 2. Org-wide org_admin assignment (idempotent by subject+role+scope).
        $role = $this->db->table('roles')
            ->where('organization_id', $orgId)->where('code', 'org_admin')
            ->get()->getRowArray();
        if ($role !== null) {
            $roleId = (string) $role['id'];
            $exists = $this->db->table('role_assignments')
                ->where('subject_id', $userId)
                ->where('role_id', $roleId)
                ->where('scope_group_id', null)
                ->get()->getRowArray();
            if ($exists === null) {
                $this->db->table('role_assignments')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $orgId,
                    'subject_id'      => $userId,
                    'role_id'         => $roleId,
                    'scope_group_id'  => null,
                    'status'          => 'active',
                    'include_descendants' => 1,
                    'source'          => 'seed',
                    'issued_by'       => null,
                    'request_id'      => null,
                    'effective_from'  => null,
                    'effective_to'    => null,
                    'revoked_at'      => null,
                    'created_at'      => $now,
                ]);
            }
        }

        // 3. Current gamification season (idempotent by org + year).
        $year   = (int) date('Y');
        $season = $this->db->table('gamification_seasons')
            ->where('organization_id', $orgId)->where('season_year', $year)
            ->get()->getRowArray();
        if ($season === null) {
            $this->db->table('gamification_seasons')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'season_year'     => $year,
                'status'          => 'active',
                'starts_at'       => $year . '-01-01 00:00:00',
                'ends_at'         => null,
                'closed_at'       => null,
                'created_at'      => $now,
            ]);
        }

        if (is_cli()) {
            fwrite(STDOUT, "AdminAccountSeeder: admin login ready — {$email}\n");
            if (getenv('wbs.adminPassword') === false) {
                fwrite(STDOUT, "  WARNING: using the default password 'ChangeMe!Admin1' — set wbs.adminPassword and rotate it now.\n");
            }
        }
    }
}
