<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's upcoming events widget.
 */
final class MyUpcomingEventsWidgetRenderer implements WidgetRenderer
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    public static function create(): static
    {
        return new static(\Config\Database::connect(), new Clock());
    }

    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $now = $this->clock->nowUtcString();

        $rows = $this->db->table('event_registrations r')
            ->select('e.id, e.title, e.starts_at, e.mode, e.timezone, r.status AS reg_status, r.rsvp_state')
            ->join('events e', 'e.id = r.event_id', 'inner')
            ->where('r.organization_id', $orgId)
            ->where('r.user_id', $userId)
            ->where('r.status !=', 'cancelled')
            ->where('e.starts_at >=', $now)
            ->where('e.status !=', 'cancelled')
            ->orderBy('e.starts_at', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Events.dashboard.noUpcoming')) . '</p>';
        }

        $html = '<ul class="events-list">';
        foreach ($rows as $row) {
            $title = esc($row['title'] ?? lang('Events.dashboard.untitledEvent'));
            $starts = esc($this->formatDateTime($row['starts_at'] ?? ''));
            $mode = esc($row['mode'] ?? '');
            $status = esc($row['reg_status'] ?? $row['rsvp_state'] ?? '');
            
            $html .= '<li>';
            $html .= '<span class="event-title">' . $title . '</span>';
            $html .= '<span class="event-time">' . $starts . '</span>';
            if ($mode !== '') {
                $html .= '<span class="event-mode">' . $mode . '</span>';
            }
            if ($status !== '') {
                $html .= '<span class="event-status status-' . esc(strtolower($status)) . '">' . $status . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    private function formatDateTime(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $ts = strtotime($date);
        return $ts ? date('M j, Y g:i a', $ts) : $date;
    }
}
