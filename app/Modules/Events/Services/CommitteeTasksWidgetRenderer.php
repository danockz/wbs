<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class CommitteeTasksWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $groupIds = $scopeData['group_ids'] ?? [];
        if ($groupIds === []) return '<p class="widget-empty">' . esc(lang('Events.dashboard.noGroups')) . '</p>';
        $rows = $this->db->table('committee_tasks ct')
            ->select('ct.id, ct.title, ct.status, ct.due_at, e.title AS event_title')
            ->join('events e', 'e.id = ct.event_id', 'left')
            ->join('committee_members cm', 'cm.committee_id = ct.committee_id')
            ->join('group_members gm', 'gm.user_id = cm.user_id')
            ->where('ct.organization_id', $orgId)
            ->where('cm.user_id', $userId)
            ->whereIn('gm.group_id', $groupIds)
            ->where('ct.status !=', 'completed')
            ->orderBy('ct.due_at', 'ASC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Events.dashboard.noTasks')) . '</p>';
        $html = '<ul class="tasks-list">';
        foreach ($rows as $r) {
            $title = esc($r['title'] ?? lang('Events.dashboard.untitled'));
            $event = esc($r['event_title'] ?? '');
            $status = esc($r['status'] ?? '');
            $due = esc($this->fmt($r['due_at'] ?? ''));
            $html .= "<li><span class=\"task-title\">$title</span>" . ($event ? " <span class=\"task-event\">($event)</span>" : '') . " <span class=\"task-status\">$status</span> <span class=\"task-due\">$due</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j', strtotime($d)) : ''; }
}
