<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's causes widget.
 */
final class MyCausesWidgetRenderer implements WidgetRenderer
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

        // Get causes the user has contributed to
        $rows = $this->db->query(
            'SELECT 
                c.id, c.name, c.description,
                COALESCE(SUM(c2.amount_minor),0) AS my_total
             FROM causes c
             LEFT JOIN contributions c2 ON c2.organization_id = ? AND c2.cause_id = c.id AND c2.user_id = ? AND c2.state = "succeeded"
             WHERE c.organization_id = ?
               AND c.status = "active"
             GROUP BY c.id, c.name, c.description
             HAVING my_total > 0
             ORDER BY my_total DESC, c.name ASC
             LIMIT ?',
            [$orgId, $userId, $orgId, $limit],
        )->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Contributions.dashboard.noCauses')) . '</p>';
        }

        $html = '<ul class="causes-list">';
        foreach ($rows as $row) {
            $name = esc($row['name'] ?? lang('Contributions.dashboard.unknownCause'));
            $amount = esc($this->formatAmount((int) ($row['my_total'] ?? 0)));
            
            $html .= '<li>';
            $html .= '<span class="cause-name">' . $name . '</span>';
            $html .= '<span class="cause-amount">' . $amount . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    private function formatAmount(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2);
    }
}
