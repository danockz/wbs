<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's giving summary widget.
 */
final class MyGivingWidgetRenderer implements WidgetRenderer
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
        $year = date('Y');
        
        // Get giving summary for the year
        $row = $this->db->query(
            'SELECT 
                COUNT(*) AS total_gifts,
                COALESCE(SUM(amount_minor),0) AS total_amount,
                COALESCE(AVG(amount_minor),0) AS avg_gift
             FROM contributions
             WHERE organization_id = ? 
               AND user_id = ? 
               AND state = "succeeded"
               AND YEAR(created_at) = ?',
            [$orgId, $userId, $year],
        )->getRowArray();

        $totalGifts = (int) ($row['total_gifts'] ?? 0);
        $totalAmount = (int) ($row['total_amount'] ?? 0);
        $avgGift = (int) ($row['avg_gift'] ?? 0);

        $html = '<dl class="giving-summary">';
        $html .= '<dt>' . esc(lang('Contributions.dashboard.totalGifts')) . '</dt>';
        $html .= '<dd>' . esc((string) $totalGifts) . '</dd>';
        
        $html .= '<dt>' . esc(lang('Contributions.dashboard.totalAmount')) . '</dt>';
        $html .= '<dd>' . esc($this->formatAmount($totalAmount)) . '</dd>';
        
        if ($totalGifts > 0) {
            $html .= '<dt>' . esc(lang('Contributions.dashboard.avgGift')) . '</dt>';
            $html .= '<dd>' . esc($this->formatAmount($avgGift)) . '</dd>';
        }
        
        $html .= '</dl>';

        return $html;
    }

    private function formatAmount(int $minorUnits): string
    {
        // Assuming minor units are cents (100 = 1.00)
        return number_format($minorUnits / 100, 2);
    }
}
