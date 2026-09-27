<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the group membership summary widget.
 */
final class GroupMembershipWidgetRenderer implements WidgetRenderer
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Factory method for lazy instantiation.
     */
    public static function create(): static
    {
        return new static(\Config\Database::connect(), new Clock());
    }

    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $groupIds = $scopeData['group_ids'] ?? [];
        
        if ($groupIds === []) {
            return '<p class="widget-empty">' . esc(lang('Groups.dashboard.widgets.noGroups')) . '</p>';
        }

        $groups = $this->db->table('groups')
            ->select('id, name, type')
            ->where('organization_id', $orgId)
            ->whereIn('id', $groupIds)
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();

        if ($groups === []) {
            return '<p class="widget-empty">' . esc(lang('Groups.dashboard.widgets.noGroups')) . '</p>';
        }

        $html = '<ul class="widget-list">';
        foreach ($groups as $g) {
            $name = esc($g['name'] ?? 'Unnamed');
            $type = esc($g['type'] ?? '');
            $html .= "<li>{$name}";
            if ($type !== '') {
                $html .= " <span class=\"muted\">({$type})</span>";
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
