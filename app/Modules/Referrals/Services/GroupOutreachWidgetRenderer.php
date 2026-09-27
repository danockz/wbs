<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class GroupOutreachWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $groupIds = $scopeData['group_ids'] ?? [];
        if ($groupIds === []) return '<p class="widget-empty">' . esc(lang('Referrals.dashboard.noGroups')) . '</p>';
        $rows = $this->db->table('prospects p')
            ->select('p.state, COUNT(*) AS count')
            ->where('p.organization_id', $orgId)
            ->whereIn('p.assigned_group_id', $groupIds)
            ->groupBy('p.state')
            ->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Referrals.dashboard.noData')) . '</p>';
        $html = '<dl class="outreach-summary">';
        foreach ($rows as $r) {
            $state = esc($r['state'] ?? '');
            $count = esc((string)($r['count'] ?? 0));
            $html .= "<dt>$state</dt><dd>$count</dd>";
        }
        $html .= '</dl>';
        return $html;
    }
}
