<?php

declare(strict_types=1);

namespace WBS\AccessControl\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\Uuid;

/**
 * Bootstraps the single organization, the permission catalogue, core roles and
 * their grants (SRS FR-ACL-001/002).
 *
 * Idempotent: permissions upsert on unique `code`, roles on (org, code). The
 * created organization id is written to a marker row so the app's default-org
 * lookup (env wbs.organizationId) can be aligned by the operator.
 *
 * Wildcards are NOT used for ordinary roles (FR-ACL-002); the admin role is
 * granted the explicit permission set. A break-glass role would be handled
 * separately with extra controls.
 */
class RbacBootstrapSeeder extends Seeder
{
    /** The fine-grained permission catalogue (verbs on resource types). */
    private const PERMISSIONS = [
        'group.create', 'group.move', 'group.change.approve',
        'referral.link.create', 'sponsor.reassign.approve',
        'event.create', 'event.schedule.approve', 'attendance.check_in',
        'event.tickets.manage', 'event.logistics.manage',
        'event.expense.submit', 'event.expense.approve',
        'event.feedback.manage', 'event.certificate.manage', 'event.media.manage',
        'contribution.refund.request', 'contribution.refund.approve',
        'contribution.manage',
        'integration.connection.approve', 'provider.configure',
        'access.request.approve',
        'access.assignment.manage',
        'access.role.manage', 'access.policy.manage', 'access.rule.manage',
        'access.break_glass', 'access.break_glass.review',
        'identity.manage',
        'notification.send', 'notification.broadcast.approve',
        'course.create', 'course.completion.override',
        'stream.create', 'stream.moderate',
        'meeting.manage',
        'venue.manage',
        'community.moderate',
        'gamification.manage',
        'report.view', 'report.export',
        'admin.manage',
    ];

    /** role code => [name, [permission codes]] */
    private const ROLES = [
        'org_admin' => ['Organization Administrator', self::PERMISSIONS],
        'finance'   => ['Finance Officer', ['contribution.refund.approve', 'contribution.manage', 'integration.connection.approve', 'event.expense.approve']],
        'notification_manager' => ['Notification Manager', ['notification.send']],
        'event_organizer' => ['Event Organizer', ['event.create', 'attendance.check_in', 'stream.create', 'stream.moderate', 'meeting.manage', 'venue.manage', 'event.tickets.manage', 'event.logistics.manage', 'event.expense.submit', 'event.feedback.manage', 'event.certificate.manage', 'event.media.manage']],
        'moderator' => ['Community Moderator', ['community.moderate', 'stream.moderate', 'gamification.manage']],
        'analyst'   => ['Reporting Analyst', ['report.view', 'report.export']],
        'member'    => ['Member', ['referral.link.create']],
    ];

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. Default organization (idempotent by slug). Prefer the fixed id from
        // the environment (wbs.organizationId) so the seeded org matches what the
        // single-org runtime resolves; fall back to a fresh UUIDv7 if unset.
        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            $envOrgId = (string) (getenv('wbs.organizationId') ?: '');
            $orgId    = $envOrgId !== '' ? $envOrgId : Uuid::v7();
            $timezone = (string) (getenv('wbs.organizationTimezone') ?: 'Africa/Accra');
            $this->db->table('organizations')->insert([
                'id'             => $orgId,
                'name'           => 'Win–Build–Send',
                'slug'           => 'wbs',
                'timezone'       => $timezone,
                'default_locale' => 'en',
                'max_group_depth' => (int) (getenv('wbs.maxGroupDepth') ?: 9),
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        } else {
            $orgId = $org['id'];
        }

        // 2. Permission catalogue (upsert on code).
        $permIds = [];
        foreach (self::PERMISSIONS as $code) {
            $existing = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            if ($existing !== null) {
                $permIds[$code] = $existing['id'];

                continue;
            }
            $id            = Uuid::v7();
            $permIds[$code] = $id;
            $this->db->table('permissions')->insert([
                'id'         => $id,
                'code'       => $code,
                'description' => null,
                'created_at' => $now,
            ]);
        }

        // 3. Roles + grants.
        foreach (self::ROLES as $roleCode => [$name, $perms]) {
            $role = $this->db->table('roles')
                ->where('organization_id', $orgId)->where('code', $roleCode)
                ->get()->getRowArray();
            if ($role === null) {
                $roleId = Uuid::v7();
                $this->db->table('roles')->insert([
                    'id'              => $roleId,
                    'organization_id' => $orgId,
                    'code'            => $roleCode,
                    'name'            => $name,
                    'is_system'       => 1,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            } else {
                $roleId = $role['id'];
            }

            foreach ($perms as $permCode) {
                if (! isset($permIds[$permCode])) {
                    continue;
                }
                $link = $this->db->table('role_permissions')
                    ->where('role_id', $roleId)->where('permission_id', $permIds[$permCode])
                    ->get()->getRowArray();
                if ($link === null) {
                    $this->db->table('role_permissions')->insert([
                        'role_id'       => $roleId,
                        'permission_id' => $permIds[$permCode],
                    ]);
                }
            }
        }

        // 4. Default identity policy (SRS FR-ID-002). The "*" row is the
        // organization-wide default; the deployment footprint is Ghana, so the
        // national phone region defaults to GH. Idempotent on (org, "*").
        $idp = $this->db->table('identity_policies')
            ->where('organization_id', $orgId)->where('country_code', '*')
            ->get()->getRowArray();
        if ($idp === null) {
            $this->db->table('identity_policies')->insert([
                'id'                   => Uuid::v7(),
                'organization_id'      => $orgId,
                'country_code'         => '*',
                'min_age'              => 13,
                'phone_default_region' => (string) (getenv('wbs.defaultPhoneRegion') ?: 'GH'),
                'require_email_unique' => 1,
                'require_phone_unique' => 1,
                'allow_minor'          => 0,
                'notes'                => 'Organization default identity policy.',
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        if (is_cli()) {
            fwrite(STDOUT, "RBAC bootstrap complete. Default organization id: {$orgId}\n");
            fwrite(STDOUT, "Set env wbs.organizationId={$orgId}\n");
        }
    }
}
