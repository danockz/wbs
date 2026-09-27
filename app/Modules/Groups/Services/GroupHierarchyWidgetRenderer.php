<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the group hierarchy navigation widget.
 */
final class GroupHierarchyWidgetRenderer implements WidgetRenderer
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
            return '<p class="widget-empty">' . esc(lang('Groups.dashboard.widgets.noHierarchy')) . '</p>';
        }

        // Get the full hierarchy for the first group
        $firstGroupId = $groupIds[0] ?? '';
        if ($firstGroupId === '') {
            return '<p class="widget-empty">' . esc(lang('Groups.dashboard.widgets.noHierarchy')) . '</p>';
        }

        $row = $this->db->table('groups')
            ->select('path')
            ->where('organization_id', $orgId)
            ->where('id', $firstGroupId)
            ->get()->getRowArray();

        if ($row === null || ! isset($row['path'])) {
            return '<p class="widget-empty">' . esc(lang('Groups.dashboard.widgets.noHierarchy')) . '</p>';
        }

        $path = trim($row['path'], '/');
        if ($path === '') {
            return '<p>' . esc(lang('Groups.dashboard.widgets.topLevel')) . '</p>';
        }

        $ancestorIds = explode('/', $path);
        $names = $this->getGroupNames($orgId, $ancestorIds);
        
        $html = '<nav class="widget-hierarchy">';
        $html .= '<span class="hierarchy-root">' . esc($names[0] ?? 'Root') . '</span>';
        for ($i = 1; $i < count($names); $i++) {
            $html .= ' &rarr; <span class="hierarchy-node">' . esc($names[$i] ?? '?') . '</span>';
        }
        $html .= '</nav>';

        return $html;
    }

    /**
     * Get names for a list of group IDs.
     */
    private function getGroupNames(string $orgId, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $rows = $this->db->table('groups')
            ->select('id, name')
            ->where('organization_id', $orgId)
            ->whereIn('id', $groupIds)
            ->get()->getResultArray();

        $names = [];
        foreach ($groupIds as $id) {
            $found = false;
            foreach ($rows as $row) {
                if ($row['id'] === $id) {
                    $names[] = $row['name'] ?? '';
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $names[] = '';
            }
        }

        return $names;
    }
}
