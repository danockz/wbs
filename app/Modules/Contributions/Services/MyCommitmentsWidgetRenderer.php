<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's commitment widget.
 */
final class MyCommitmentsWidgetRenderer implements WidgetRenderer
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
        $limit = $options['limit'] ?? 5;

        $rows = $this->db->table('commitments c')
            ->select('c.id, c.cause_id, co.name AS cause_name, c.amount_minor, c.frequency, c.start_date, c.status')
            ->join('causes co', 'co.id = c.cause_id', 'left')
            ->where('c.organization_id', $orgId)
            ->where('c.user_id', $userId)
            ->where('c.status', 'active')
            ->orderBy('c.start_date', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Contributions.dashboard.noCommitments')) . '</p>';
        }

        $html = '<ul class="commitments-list">';
        foreach ($rows as $row) {
            $cause = esc($row['cause_name'] ?? lang('Contributions.dashboard.unknownCause'));
            $amount = esc($this->formatAmount((int) ($row['amount_minor'] ?? 0)));
            $frequency = esc($row['frequency'] ?? '');
            $status = esc($row['status'] ?? '');
            
            $html .= '<li>';
            $html .= '<span class="commitment-cause">' . $cause . '</span>';
            $html .= '<span class="commitment-amount">' . $amount . '</span>';
            if ($frequency !== '') {
                $html .= '<span class="commitment-frequency">' . esc(lang('Contributions.dashboard.' . $frequency)) . '</span>';
            }
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
