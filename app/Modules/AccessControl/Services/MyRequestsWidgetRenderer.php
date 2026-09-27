<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class MyRequestsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $rows = $this->db->table('access_requests ar')
            ->select('ar.id, ar.request_type, ar.status, ar.created_at')
            ->where('ar.organization_id', $orgId)
            ->where('ar.requester_id', $userId)
            ->orderBy('ar.created_at', 'DESC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('AccessControl.dashboard.noRequests')) . '</p>';
        $html = '<ul class="requests-list">';
        foreach ($rows as $r) {
            $type = esc($r['request_type'] ?? '');
            $status = esc($r['status'] ?? '');
            $date = esc($this->fmt($r['created_at'] ?? ''));
            $html .= "<li><span class=\"request-type\">$type</span> <span class=\"request-status status-$status\">$status</span> <span class=\"request-date\">$date</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j', strtotime($d)) : ''; }
}
