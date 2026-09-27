<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's disciples widget (people they are discipling).
 */
final class MyDisciplesWidgetRenderer implements WidgetRenderer
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
        $limit = $options['limit'] ?? 10;

        // Get users where this user is their discipler
        $rows = $this->db->table('user_relationships ur')
            ->select('u.id, u.display_name, u.email, ur.relationship_type, ur.started_at')
            ->join('users u', 'u.id = ur.related_user_id', 'left')
            ->where('ur.organization_id', $orgId)
            ->where('ur.user_id', $userId)
            ->where('ur.relationship_type', 'discipling')
            ->where('ur.status', 'active')
            ->orderBy('ur.started_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Journey.dashboard.noDisciples')) . '</p>';
        }

        $html = '<ul class="disciples-list">';
        foreach ($rows as $row) {
            $name = esc($row['display_name'] ?? lang('Journey.dashboard.anonymous'));
            $email = esc($row['email'] ?? '');
            $started = esc($this->formatDate($row['started_at'] ?? ''));
            
            $html .= '<li>';
            $html .= '<span class="disciple-name">' . $name . '</span>';
            if ($email !== '') {
                $html .= '<span class="disciple-email">' . $email . '</span>';
            }
            $html .= '<span class="discipling-since">' . esc(lang('Journey.dashboard.disciplingSince', [$started])) . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul>';

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
