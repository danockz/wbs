<?php

declare(strict_types=1);

namespace WBS\Reporting\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class GroupFunnelWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $groupIds = $scopeData['group_ids'] ?? [];
        if ($groupIds === []) return '<p class="widget-empty">' . esc(lang('Reporting.dashboard.noGroups')) . '</p>';
        $members = (int) $this->db->table('group_members')->where('organization_id', $orgId)->whereIn('group_id', $groupIds)->where('status', 'active')->countAllResults();
        $prospects = (int) $this->db->table('prospects')->where('organization_id', $orgId)->where('state', 'captured')->whereIn('assigned_group_id', $groupIds)->countAllResults();
        $enrollments = (int) $this->db->table('enrollments')->where('organization_id', $orgId)->whereIn('group_id', $groupIds)->countAllResults();
        $completions = (int) $this->db->table('course_completions')->where('organization_id', $orgId)->whereIn('group_id', $groupIds)->countAllResults();
        $html = '<dl class="funnel-summary">';
        $html .= '<dt>' . esc(lang('Reporting.dashboard.members')) . '</dt><dd>' . esc((string)$members) . '</dd>';
        $html .= '<dt>' . esc(lang('Reporting.dashboard.prospects')) . '</dt><dd>' . esc((string)$prospects) . '</dd>';
        $html .= '<dt>' . esc(lang('Reporting.dashboard.enrollments')) . '</dt><dd>' . esc((string)$enrollments) . '</dd>';
        $html .= '<dt>' . esc(lang('Reporting.dashboard.completions')) . '</dt><dd>' . esc((string)$completions) . '</dd>';
        $html .= '</dl>';
        return $html;
    }
}
