<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class CampaignStatusWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $groupIds = $scopeData['group_ids'] ?? [];
        if ($groupIds === []) return '<p class="widget-empty">' . esc(lang('Notifications.dashboard.noGroups')) . '</p>';
        $rows = $this->db->table('notification_campaigns nc')
            ->select('nc.id, nc.name, nc.status, nc.sent_at, COUNT(n.id) AS recipient_count')
            ->join('notifications n', 'n.campaign_id = nc.id', 'left')
            ->where('nc.organization_id', $orgId)
            ->whereIn('nc.group_id', $groupIds)
            ->groupBy('nc.id, nc.name, nc.status, nc.sent_at')
            ->orderBy('nc.sent_at', 'DESC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Notifications.dashboard.noCampaigns')) . '</p>';
        $html = '<ul class="campaigns-list">';
        foreach ($rows as $r) {
            $name = esc($r['name'] ?? lang('Notifications.dashboard.unnamed'));
            $status = esc($r['status'] ?? '');
            $count = esc((string)($r['recipient_count'] ?? 0));
            $date = esc($this->fmt($r['sent_at'] ?? ''));
            $html .= "<li><span class=\"campaign-name\">$name</span> <span class=\"campaign-status\">$status</span> <span class=\"campaign-count\">$count</span> <span class=\"campaign-date\">$date</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j', strtotime($d)) : ''; }
}
