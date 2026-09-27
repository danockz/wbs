<?php

declare(strict_types=1);

namespace WBS\Streaming\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class LiveStreamsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $groupIds = $scopeData['group_ids'] ?? [];
        if ($groupIds === []) return '<p class="widget-empty">' . esc(lang('Streaming.dashboard.noGroups')) . '</p>';
        $now = $this->clock->nowUtcString();
        $rows = $this->db->table('streams s')
            ->select('s.id, s.title, s.status, s.starts_at')
            ->where('s.organization_id', $orgId)
            ->whereIn('s.group_id', $groupIds)
            ->where('s.status !=', 'ended')
            ->orderBy('s.starts_at', 'ASC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Streaming.dashboard.noStreams')) . '</p>';
        $html = '<ul class="streams-list">';
        foreach ($rows as $r) {
            $title = esc($r['title'] ?? lang('Streaming.dashboard.untitled'));
            $status = esc($r['status'] ?? '');
            $when = esc($this->fmt($r['starts_at'] ?? ''));
            $html .= "<li><span class=\"stream-title\">$title</span> <span class=\"stream-status\">$status</span> <span class=\"stream-when\">$when</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j g:i a', strtotime($d)) : ''; }
}
