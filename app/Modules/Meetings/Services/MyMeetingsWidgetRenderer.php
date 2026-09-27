<?php

declare(strict_types=1);

namespace WBS\Meetings\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class MyMeetingsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $now = $this->clock->nowUtcString();
        $rows = $this->db->table('meetings m')
            ->select('m.id, m.title, m.starts_at, m.location')
            ->join('meeting_attendees ma', 'ma.meeting_id = m.id')
            ->where('m.organization_id', $orgId)
            ->where('ma.user_id', $userId)
            ->where('m.starts_at >=', $now)
            ->orderBy('m.starts_at', 'ASC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Meetings.dashboard.noMeetings')) . '</p>';
        $html = '<ul class="meetings-list">';
        foreach ($rows as $r) {
            $title = esc($r['title'] ?? lang('Meetings.dashboard.untitled'));
            $when = esc($this->fmt($r['starts_at'] ?? ''));
            $loc = esc($r['location'] ?? '');
            $html .= "<li><span class=\"meeting-title\">$title</span> <span class=\"meeting-when\">$when</span>" . ($loc ? " <span class=\"meeting-loc\">$loc</span>" : '') . "</li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j g:i a', strtotime($d)) : ''; }
}
