<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the group giving progress widget.
 */
final class GroupGivingWidgetRenderer implements WidgetRenderer
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
            return '<p class="widget-empty">' . esc(lang('Contributions.dashboard.noGroupsInScope')) . '</p>';
        }

        $year = date('Y');

        // Get giving summary for the scope groups
        $row = $this->db->query(
            'SELECT 
                COUNT(DISTINCT user_id) AS givers_count,
                COALESCE(SUM(amount_minor),0) AS total_amount,
                COALESCE(AVG(amount_minor),0) AS avg_gift
             FROM contributions
             WHERE organization_id = ? 
               AND state = "succeeded"
               AND YEAR(created_at) = ?
               AND user_id IN (
                   SELECT DISTINCT user_id FROM group_members 
                   WHERE organization_id = ? AND group_id IN (' . $this->placeholders($groupIds) . ')
               )',
            array_merge([$orgId, $year, $orgId], $groupIds),
        )->getRowArray();

        $givers = (int) ($row['givers_count'] ?? 0);
        $total = (int) ($row['total_amount'] ?? 0);
        $avg = (int) ($row['avg_gift'] ?? 0);

        $html = '<dl class="group-giving-summary">';
        $html .= '<dt>' . esc(lang('Contributions.dashboard.givers')) . '</dt>';
        $html .= '<dd>' . esc((string) $givers) . '</dd>';
        
        $html .= '<dt>' . esc(lang('Contributions.dashboard.totalGiven')) . '</dt>';
        $html .= '<dd>' . esc($this->formatAmount($total)) . '</dd>';
        
        if ($givers > 0) {
            $html .= '<dt>' . esc(lang('Contributions.dashboard.avgGift')) . '</dt>';
            $html .= '<dd>' . esc($this->formatAmount($avg)) . '</dd>';
        }
        
        $html .= '</dl>';

        return $html;
    }

    private function formatAmount(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2);
    }

    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
