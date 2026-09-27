<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class EventAnalyticsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $groupIds = $scopeData['group_ids'] ?? [];
        if ($groupIds === []) return '<p class="widget-empty">' . esc(lang('Events.dashboard.noGroups')) . '</p>';
        $total = (int) $this->db->table('events')->where('organization_id', $orgId)->whereIn('group_id', $groupIds)->countAllResults();
        $upcoming = (int) $this->db->table('events')->where('organization_id', $orgId)->whereIn('group_id', $groupIds)->where('starts_at >=', $this->clock->nowUtcString())->where('status !=', 'cancelled')->countAllResults();
        $attendees = (int) $this->db->table('event_attendance ea')->join('events e', 'e.id = ea.event_id')->where('ea.organization_id', $orgId)->whereIn('e.group_id', $groupIds)->where('ea.status', 'present')->countAllResults();
        $html = '<dl class="analytics-summary">';
        $html .= '<dt>' . esc(lang('Events.dashboard.totalEvents')) . '</dt><dd>' . esc((string)$total) . '</dd>';
        $html .= '<dt>' . esc(lang('Events.dashboard.upcoming')) . '</dt><dd>' . esc((string)$upcoming) . '</dd>';
        $html .= '<dt>' . esc(lang('Events.dashboard.totalAttendees')) . '</dt><dd>' . esc((string)$attendees) . '</dd>';
        $html .= '</dl>';
        return $html;
    }
}
