<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Production EventRosterPort: reads the roster and manages the reminder watermark
 * through the live database. Each method is ONE bounded, indexed query (roster
 * fan-out over KEY er_event_idx; reminder sweep over KEY ev_org_idx / starts_at),
 * keeping the notifier resource-light per the standing rule.
 */
final class EventRosterDbAdapter implements EventRosterPort
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function activeRegistrants(string $eventId, array $statuses = ['registered']): array
    {
        $statuses = $statuses === [] ? ['registered'] : $statuses;
        $rows     = $this->db->table('event_registrations')
            ->select('user_id, group_attribution')
            ->where('event_id', $eventId)
            ->whereIn('status', $statuses)
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'user_id'           => (string) ($r['user_id'] ?? ''),
            'group_attribution' => $r['group_attribution'] !== null ? (string) $r['group_attribution'] : null,
        ], $rows);
    }

    public function dueForReminder(?string $organizationId, string $notBeforeUtc, string $notAfterUtc, int $limit): array
    {
        $q = $this->db->table('events')
            ->select('id, organization_id, group_id, title, starts_at, timezone, mode')
            ->where('status', 'published')
            ->where('starts_at >=', $notBeforeUtc)
            ->where('starts_at <', $notAfterUtc)
            ->where('last_reminded_at IS NULL');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }

        return $q->orderBy('starts_at', 'ASC')->limit($limit)->get()->getResultArray();
    }

    public function markReminded(string $eventId, string $at): void
    {
        $this->db->table('events')->where('id', $eventId)->update([
            'last_reminded_at' => $at,
            'updated_at'       => $at,
        ]);
    }

    public function dueForClose(?string $organizationId, string $finishedByUtc, int $limit): array
    {
        // A single bounded, indexed range read over `events` (KEY ev_close_idx).
        // "Finished" = ends_at when set, otherwise starts_at (an open-ended event
        // is due once its start is far enough past the grace window). COALESCE
        // keeps it one query without a UNION.
        $q = $this->db->table('events')
            ->select('id, organization_id, group_id, title, starts_at, ends_at')
            ->where('status', 'published')
            ->where('COALESCE(ends_at, starts_at) <=', $finishedByUtc);
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }

        return $q->orderBy('COALESCE(ends_at, starts_at)', 'ASC', false)->limit($limit)->get()->getResultArray();
    }

    public function orgRootGroup(string $organizationId): ?string
    {
        if ($organizationId === '') {
            return null;
        }
        $row = $this->db->table('groups')
            ->select('id')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->orderBy('depth', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get()->getRowArray();

        return $row !== null ? (string) $row['id'] : null;
    }
}
