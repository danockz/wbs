<?php

declare(strict_types=1);

namespace WBS\Announcements\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class MyAnnouncementsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $rows = $this->db->table('announcements a')
            ->select('a.id, a.title, a.created_at, a.status')
            ->where('a.organization_id', $orgId)
            ->whereIn('a.target_type', ['user', 'group'])
            ->where(function($q) use ($userId) {
                $q->where('a.target_id', $userId)->orWhere('a.target_type', 'all');
            })
            ->where('a.status', 'published')
            ->orderBy('a.created_at', 'DESC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Announcements.dashboard.noAnnouncements')) . '</p>';
        $html = '<ul class="announcements-list">';
        foreach ($rows as $r) {
            $title = esc($r['title'] ?? lang('Announcements.dashboard.untitled'));
            $date = esc($this->fmt($r['created_at'] ?? ''));
            $html .= "<li><span class=\"announcement-title\">$title</span> <span class=\"announcement-date\">$date</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j', strtotime($d)) : ''; }
}
