<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class PendingApprovalsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $groupIds = $scopeData['group_ids'] ?? [];
        if ($groupIds === []) return '<p class="widget-empty">' . esc(lang('AccessControl.dashboard.noGroups')) . '</p>';
        $rows = $this->db->table('access_requests ar')
            ->select('ar.id, ar.request_type, ar.requester_id, u.display_name, ar.created_at')
            ->join('users u', 'u.id = ar.requester_id', 'left')
            ->where('ar.organization_id', $orgId)
            ->where('ar.status', 'pending')
            ->whereIn('ar.scope_group_id', $groupIds)
            ->orderBy('ar.created_at', 'DESC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('AccessControl.dashboard.noPending')) . '</p>';
        $html = '<ul class="approvals-list">';
        foreach ($rows as $r) {
            $name = esc($r['display_name'] ?? lang('AccessControl.dashboard.anonymous'));
            $type = esc($r['request_type'] ?? '');
            $date = esc($this->fmt($r['created_at'] ?? ''));
            $html .= "<li><span class=\"requester-name\">$name</span> <span class=\"request-type\">$type</span> <span class=\"request-date\">$date</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j', strtotime($d)) : ''; }
}
