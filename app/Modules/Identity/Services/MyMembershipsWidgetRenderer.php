<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's group memberships widget.
 */
final class MyMembershipsWidgetRenderer implements WidgetRenderer
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    public static function create(): static
    {
        return new static(\Config\Database::connect(), new Clock());
    }

    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 10;

        $rows = $this->db->table('group_members gm')
            ->select('g.id, g.name, g.type, g.path, gm.role, gm.joined_at, gm.status')
            ->join('groups g', 'g.id = gm.group_id', 'inner')
            ->where('gm.organization_id', $orgId)
            ->where('gm.user_id', $userId)
            ->where('gm.status', 'active')
            ->orderBy('gm.joined_at', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Identity.dashboard.noMemberships')) . '</p>';
        }

        $html = '<ul class="memberships-list">';
        foreach ($rows as $row) {
            $name = esc($row['name'] ?? lang('Identity.dashboard.unnamedGroup'));
            $role = esc($row['role'] ?? '');
            $type = esc($row['type'] ?? '');
            $joined = esc($this->formatDate($row['joined_at'] ?? ''));
            
            $html .= '<li>';
            $html .= '<span class="group-name">' . $name . '</span>';
            if ($role !== '') {
                $html .= '<span class="member-role">' . $role . '</span>';
            }
            if ($type !== '') {
                $html .= '<span class="group-type">' . $type . '</span>';
            }
            if ($joined !== '') {
                $html .= '<span class="joined-date">' . esc(lang('Identity.dashboard.joinedOn', [$joined])) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    private function formatDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $ts = strtotime($date);
        return $ts ? date('M j, Y', $ts) : $date;
    }
}
