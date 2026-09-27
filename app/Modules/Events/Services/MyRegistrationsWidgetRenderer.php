<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class MyRegistrationsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $rows = $this->db->table('event_registrations r')
            ->select('e.id, e.title, e.starts_at, r.status, r.rsvp_state')
            ->join('events e', 'e.id = r.event_id')
            ->where('r.organization_id', $orgId)
            ->where('r.user_id', $userId)
            ->where('r.status !=', 'cancelled')
            ->orderBy('e.starts_at', 'ASC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Events.dashboard.noRegistrations')) . '</p>';
        $html = '<ul class="registrations-list">';
        foreach ($rows as $r) {
            $title = esc($r['title'] ?? lang('Events.dashboard.untitled'));
            $when = esc($this->fmt($r['starts_at'] ?? ''));
            $status = esc($r['status'] ?? $r['rsvp_state'] ?? '');
            $html .= "<li><span class=\"event-title\">$title</span> <span class=\"event-when\">$when</span> <span class=\"reg-status\">$status</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j g:i a', strtotime($d)) : ''; }
}
