<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's current journey stage widget.
 */
final class MyStageWidgetRenderer implements WidgetRenderer
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
        // Get user's current journey stage
        $row = $this->db->table('user_journey_stages ujs')
            ->select('js.code, js.name, js.description, js.icon, ujs.started_at')
            ->join('journey_stages js', 'js.id = ujs.stage_id', 'left')
            ->where('ujs.organization_id', $orgId)
            ->where('ujs.user_id', $userId)
            ->where('ujs.status', 'active')
            ->orderBy('ujs.started_at', 'DESC')
            ->get()->getRowArray();

        if ($row === null) {
            return '<p class="widget-empty">' . esc(lang('Journey.dashboard.noStage')) . '</p>';
        }

        $icon = $row['icon'] ?? '';
        $name = esc($row['name'] ?? $row['code'] ?? lang('Journey.dashboard.unknownStage'));
        $description = esc($row['description'] ?? '');
        $started = esc($this->formatDate($row['started_at'] ?? ''));

        $html = '<div class="stage-widget">';
        if ($icon !== '') {
            $html .= '<div class="stage-icon">' . esc($icon) . '</div>';
        }
        $html .= '<div class="stage-name">' . $name . '</div>';
        if ($description !== '') {
            $html .= '<div class="stage-description">' . $description . '</div>';
        }
        if ($started !== '') {
            $html .= '<div class="stage-started">' . esc(lang('Journey.dashboard.startedOn', [$started])) . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function formatDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $ts = strtotime($date);
        return $ts ? date('M j, Y', $ts) : $date;
    }
}
