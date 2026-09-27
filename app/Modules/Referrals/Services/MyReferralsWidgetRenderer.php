<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

final class MyReferralsWidgetRenderer implements WidgetRenderer
{
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock) {}
    public static function create(): static { return new static(\Config\Database::connect(), new Clock()); }
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 5;
        $rows = $this->db->table('prospects p')
            ->select('p.id, p.first_name, p.last_name, p.state, p.created_at')
            ->where('p.organization_id', $orgId)
            ->where('p.referrer_id', $userId)
            ->orderBy('p.created_at', 'DESC')
            ->limit($limit)->get()->getResultArray();
        if ($rows === []) return '<p class="widget-empty">' . esc(lang('Referrals.dashboard.noReferrals')) . '</p>';
        $html = '<ul class="referrals-list">';
        foreach ($rows as $r) {
            $name = esc(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: lang('Referrals.dashboard.anonymous'));
            $state = esc($r['state'] ?? '');
            $date = esc($this->fmt($r['created_at'] ?? ''));
            $html .= "<li><span class=\"prospect-name\">$name</span> <span class=\"prospect-state\">$state</span> <span class=\"prospect-date\">$date</span></li>";
        }
        $html .= '</ul>';
        return $html;
    }
    private function fmt(?string $d): string { return $d ? date('M j', strtotime($d)) : ''; }
}
