<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the group discipleship pipeline widget.
 */
final class GroupPipelineWidgetRenderer implements WidgetRenderer
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
        $groupIds = $scopeData['group_ids'] ?? [];

        if ($groupIds === []) {
            return '<p class="widget-empty">' . esc(lang('Journey.dashboard.noGroupsInScope')) . '</p>';
        }

        // Get pipeline counts by stage for the scope groups
        $stages = $this->db->table('journey_stages')
            ->select('id, code, name, icon')
            ->where('organization_id', $orgId)
            ->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();

        if ($stages === []) {
            return '<p class="widget-empty">' . esc(lang('Journey.dashboard.noStagesDefined')) . '</p>';
        }

        $html = '<div class="pipeline-widget">';
        
        foreach ($stages as $stage) {
            $stageId = $stage['id'];
            $code = esc($stage['code'] ?? '');
            $name = esc($stage['name'] ?? '');
            $icon = $stage['icon'] ?? '';

            // Count users in this stage in the scope groups
            $count = $this->db->table('user_journey_stages ujs')
                ->join('group_members gm', 'gm.user_id = ujs.user_id AND gm.organization_id = ujs.organization_id')
                ->where('ujs.organization_id', $orgId)
                ->where('ujs.stage_id', $stageId)
                ->where('ujs.status', 'active')
                ->whereIn('gm.group_id', $groupIds)
                ->countAllResults();

            $html .= '<div class="pipeline-stage">';
            if ($icon !== '') {
                $html .= '<span class="stage-icon">' . esc($icon) . '</span>';
            }
            $html .= '<span class="stage-name">' . $name . '</span>';
            $html .= '<span class="stage-count">' . esc((string) $count) . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}
