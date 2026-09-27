<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the group's upcoming events widget.
 */
final class GroupUpcomingEventsWidgetRenderer implements WidgetRenderer
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
        $groupIds = $scopeData['group_ids'] ?? [];
        $now = $this->clock->nowUtcString();

        if ($groupIds === []) {
            return '<p class="widget-empty">' . esc(lang('Events.dashboard.noGroupsInScope')) . '</p>';
        }

        $rows = $this->db->table('events e')
            ->select('e.id, e.title, e.starts_at, e.mode, e.timezone, e.status')
            ->where('e.organization_id', $orgId)
            ->whereIn('e.group_id', $groupIds)
            ->where('e.starts_at >=', $now)
            ->where('e.status !=', 'cancelled')
            ->where('e.visibility !=', 'private')
            ->orderBy('e.starts_at', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Events.dashboard.noGroupEvents')) . '</p>';
        }

        $html = '<ul class="events-list">';
        foreach ($rows as $row) {
            $title = esc($row['title'] ?? lang('Events.dashboard.untitledEvent'));
            $starts = esc($this->formatDateTime($row['starts_at'] ?? ''));
            $mode = esc($row['mode'] ?? '');
            
            $html .= '<li>';
            $html .= '<span class="event-title">' . $title . '</span>';
            $html .= '<span class="event-time">' . $starts . '</span>';
            if ($mode !== '') {
                $html .= '<span class="event-mode">' . $mode . '</span>';
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
