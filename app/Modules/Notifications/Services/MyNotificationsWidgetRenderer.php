<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class MyNotificationsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $rows = $this->db->table('notifications n')
            ->select('n.id, n.title, n.body, n.created_at, n.status')
            ->where('n.organization_id', $orgId)
            ->where('n.recipient_id', $userId)
            ->where('n.recipient_type', 'user')
            ->orderBy('n.created_at', 'DESC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Notifications.dashboard.noNotifications')) . '</p>';
        $html = '<ul class="notifications-list">';
        foreach ($rows as $r) {
            $title = esc($r['title'] ?? lang('Notifications.dashboard.untitled'));
            $date = esc($this->fmt($r['created_at'] ?? ''));
            $status = $r['status'] ?? '';
            $class = $status === 'read' ? 'read' : 'unread';
            $html .= "<li class=\"$class\">$title <span class=\"notification-date\">$date</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j', strtotime($d)) : ''; }
}
