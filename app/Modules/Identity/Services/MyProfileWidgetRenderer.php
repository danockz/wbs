<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's profile summary widget.
 */
final class MyProfileWidgetRenderer implements WidgetRenderer
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
        $user = $this->db->table('users')
            ->select('display_name, email, phone, country_code, created_at, last_login_at')
            ->where('id', $userId)
            ->where('organization_id', $orgId)
            ->get()->getRowArray();

        if ($user === null) {
            return '<p class="widget-empty">' . esc(lang('Identity.dashboard.profileNotFound')) . '</p>';
        }

        $html = '<dl class="profile-summary">';
        
        $html .= '<dt>' . esc(lang('Identity.dashboard.name')) . '</dt>';
        $html .= '<dd>' . esc($user['display_name'] ?? '') . '</dd>';

        if (! empty($user['email'])) {
            $html .= '<dt>' . esc(lang('Identity.dashboard.email')) . '</dt>';
            $html .= '<dd>' . esc($user['email']) . '</dd>';
        }

        if (! empty($user['phone'])) {
            $html .= '<dt>' . esc(lang('Identity.dashboard.phone')) . '</dt>';
            $html .= '<dd>' . esc($user['phone']) . '</dd>';
        }

        if (! empty($user['country_code'])) {
            $html .= '<dt>' . esc(lang('Identity.dashboard.country')) . '</dt>';
            $html .= '<dd>' . esc($user['country_code']) . '</dd>';
        }

        if (! empty($user['created_at'])) {
            $html .= '<dt>' . esc(lang('Identity.dashboard.memberSince')) . '</dt>';
            $html .= '<dd>' . esc($this->formatDate($user['created_at'])) . '</dd>';
        }

        if (! empty($user['last_login_at'])) {
            $html .= '<dt>' . esc(lang('Identity.dashboard.lastLogin')) . '</dt>';
            $html .= '<dd>' . esc($this->formatDate($user['last_login_at'])) . '</dd>';
        }

        $html .= '</dl>';

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
