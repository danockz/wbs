<?php

declare(strict_types=1);

namespace WBS\Community\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class RecentPostsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $groupIds = $scopeData['group_ids'] ?? [];
        if ($groupIds === []) return '<p class="widget-empty">' . esc(lang('Community.dashboard.noGroups')) . '</p>';
        $rows = $this->db->table('community_posts cp')
            ->select('cp.id, cp.title, cp.created_at')
            ->where('cp.organization_id', $orgId)
            ->whereIn('cp.group_id', $groupIds)
            ->orderBy('cp.created_at', 'DESC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Community.dashboard.noPosts')) . '</p>';
        $html = '<ul class="posts-list">';
        foreach ($rows as $r) {
            $title = esc($r['title'] ?? lang('Community.dashboard.untitled'));
            $date = esc($this->fmt($r['created_at'] ?? ''));
            $html .= "<li><span class=\"post-title\">$title</span> <span class=\"post-date\">$date</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j', strtotime($d)) : ''; }
}
