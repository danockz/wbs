<?php

declare(strict_types=1);

namespace WBS\Announcements\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Announcements\Support\AnnouncementScope;
use WBS\Notifications\Services\NotificationService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Announcements — first-class in-app bulletins, extended notification campaigns:
 * draft → pending_approval → published with SoD, optional NotificationService
 * fan-out, must-ack receipts, optional ends_at.
 */
final class AnnouncementService
{
    public const STATUSES = ['draft', 'pending_approval', 'published', 'cancelled'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AnnouncementAudienceResolver $audience,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(string $organizationId, string $actorId, array $data): Result
    {
        $title = trim((string) ($data['title'] ?? ''));
        $body  = trim((string) ($data['body'] ?? ''));
        if ($title === '' || $body === '') {
            return Result::fail('MISSING_FIELDS', 'announcement.missing_fields', 422);
        }
        $targets = $this->parseTargets($data);
        if ($targets === []) {
            return Result::fail('NO_TARGETS', 'announcement.no_targets', 422);
        }
        $hasUser = false;
        $hasFilter = false;
        foreach ($targets as $t) {
            if (($t['kind'] ?? '') === 'user') {
                $hasUser = true;
            } else {
                $hasFilter = true;
            }
        }
        if ($hasUser && $hasFilter) {
            return Result::fail('MIXED_TARGETS', 'announcement.named_users_direct_only', 422);
        }
        $ends = $this->optionalStamp($data['ends_at'] ?? null);
        if (($data['ends_at'] ?? '') !== '' && $data['ends_at'] !== null && $ends === null) {
            return Result::fail('ENDS_INVALID', 'announcement.ends_invalid', 422);
        }
        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('announcements')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'group_id'        => trim((string) ($data['group_id'] ?? '')) ?: null,
            'title'           => $title,
            'body'            => $body,
            'notify'          => ! empty($data['notify']) ? 1 : 0,
            'template_key'    => trim((string) ($data['template_key'] ?? '')) ?: null,
            'channel'         => $this->channel($data['channel'] ?? null),
            'status'          => 'draft',
            'requested_by'    => $actorId,
            'ends_at'         => $ends,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $this->writeTargets($id, $targets);

        return Result::created(['id' => $id, 'status' => 'draft']);
    }

    public function submit(string $organizationId, string $announcementId): Result
    {
        $row = $this->findInOrg($organizationId, $announcementId);
        if ($row === null) {
            return Result::notFound('announcement.not_found', 'NOT_FOUND');
        }
        if ((string) $row['status'] !== 'draft') {
            return Result::fail('BAD_STATE', 'announcement.bad_state', 409);
        }
        $targets = $this->targetsOf($announcementId);
        $count   = count($this->audience->resolve($organizationId, $targets));
        $this->db->table('announcements')->where('id', $announcementId)->update([
            'status'         => 'pending_approval',
            'audience_count' => $count,
            'updated_at'     => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['id' => $announcementId, 'status' => 'pending_approval', 'audience_count' => $count]);
    }

    public function approve(string $organizationId, string $announcementId, string $approverId): Result
    {
        $row = $this->findInOrg($organizationId, $announcementId);
        if ($row === null) {
            return Result::notFound('announcement.not_found', 'NOT_FOUND');
        }
        if ((string) $row['status'] !== 'pending_approval') {
            return Result::fail('BAD_STATE', 'announcement.bad_state', 409);
        }
        if ((string) $row['requested_by'] === $approverId) {
            return Result::fail('SOD_SELF_APPROVAL', 'announcement.self_approval', 422);
        }
        $targets = $this->targetsOf($announcementId);
        $users   = $this->audience->resolve($organizationId, $targets);
        if ($users === []) {
            return Result::fail('NO_AUDIENCE', 'announcement.no_audience', 422);
        }
        $now = $this->clock->nowUtcString();
        foreach ($users as $uid) {
            $this->db->table('announcement_audience')->insert([
                'announcement_id' => $announcementId,
                'user_id'         => $uid,
            ]);
        }
        $this->db->table('announcements')->where('id', $announcementId)->update([
            'status'         => 'published',
            'approved_by'    => $approverId,
            'audience_count' => count($users),
            'published_at'   => $now,
            'updated_at'     => $now,
        ]);
        $notified = 0;
        if ((int) ($row['notify'] ?? 0) === 1 && $this->notifications !== null) {
            $key     = trim((string) ($row['template_key'] ?? '')) ?: 'announcement_published';
            $channel = $this->channel($row['channel'] ?? 'email') ?? 'email';
            $ctx     = [
                'title' => (string) $row['title'],
                'name'  => '',
                'body'  => (string) $row['body'],
            ];
            foreach ($users as $uid) {
                $this->notifications->send($organizationId, $uid, $channel, 'announcement', [
                    'template_key' => $key,
                    'group_id'     => $row['group_id'] ?? null,
                    'dedupe_key'   => 'announcement:' . $announcementId . ':' . $uid,
                    'context'      => $ctx,
                    'priority'     => 'high',
                ]);
                $notified++;
            }
        }

        return Result::ok([
            'id'         => $announcementId,
            'status'     => 'published',
            'audience'   => count($users),
            'notified'   => $notified,
        ]);
    }

    public function cancel(string $organizationId, string $announcementId): Result
    {
        $row = $this->findInOrg($organizationId, $announcementId);
        if ($row === null) {
            return Result::notFound('announcement.not_found', 'NOT_FOUND');
        }
        if ((string) $row['status'] === 'cancelled') {
            return Result::ok(['id' => $announcementId, 'status' => 'cancelled', 'deduplicated' => true]);
        }
        $this->db->table('announcements')->where('id', $announcementId)->update([
            'status'     => 'cancelled',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['id' => $announcementId, 'status' => 'cancelled']);
    }

    public function ack(string $organizationId, string $announcementId, string $userId): Result
    {
        $row = $this->findInOrg($organizationId, $announcementId);
        if ($row === null || (string) $row['status'] !== 'published') {
            return Result::notFound('announcement.not_found', 'NOT_FOUND');
        }
        $in = $this->db->table('announcement_audience')
            ->where('announcement_id', $announcementId)
            ->where('user_id', $userId)
            ->get()->getRowArray();
        if ($in === null) {
            return Result::fail('NOT_IN_AUDIENCE', 'announcement.not_in_audience', 403);
        }
        $now = $this->clock->nowUtcString();
        $existing = $this->db->table('announcement_receipts')
            ->where('announcement_id', $announcementId)
            ->where('user_id', $userId)
            ->get()->getRowArray();
        if ($existing !== null) {
            if (($existing['acked_at'] ?? null) !== null && (string) $existing['acked_at'] !== '') {
                return Result::ok(['id' => $announcementId, 'acked' => true, 'deduplicated' => true]);
            }
            $this->db->table('announcement_receipts')
                ->where('announcement_id', $announcementId)
                ->where('user_id', $userId)
                ->update(['acked_at' => $now, 'seen_at' => $existing['seen_at'] ?? $now]);
        } else {
            $this->db->table('announcement_receipts')->insert([
                'announcement_id' => $announcementId,
                'user_id'         => $userId,
                'seen_at'         => $now,
                'acked_at'        => $now,
            ]);
        }

        return Result::ok(['id' => $announcementId, 'acked' => true]);
    }

    /**
     * Published, unexpired, unacked announcements for a user (must-ack inbox).
     *
     * @return list<array<string,mixed>>
     */
    public function inbox(string $organizationId, string $userId): array
    {
        $now = $this->clock->nowUtcString();
        $ids = [];
        foreach ($this->db->table('announcement_audience')
            ->select('announcement_id')
            ->where('user_id', $userId)
            ->get()->getResultArray() as $r) {
            $ids[] = (string) $r['announcement_id'];
        }
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $a = $this->db->table('announcements')->where('id', $id)->get()->getRowArray();
            if ($a === null || (string) ($a['organization_id'] ?? '') !== $organizationId) {
                continue;
            }
            if ((string) ($a['status'] ?? '') !== 'published') {
                continue;
            }
            $ends = (string) ($a['ends_at'] ?? '');
            if ($ends !== '' && $ends <= $now) {
                continue;
            }
            $rcpt = $this->db->table('announcement_receipts')
                ->where('announcement_id', $id)
                ->where('user_id', $userId)
                ->get()->getRowArray();
            if ($rcpt !== null && ($rcpt['acked_at'] ?? null) !== null && (string) $rcpt['acked_at'] !== '') {
                continue;
            }
            $out[] = $a;
        }
        usort($out, static fn ($a, $b) => ((string) ($b['published_at'] ?? '')) <=> ((string) ($a['published_at'] ?? '')));

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function listForOrg(string $organizationId, ?string $status = null): array
    {
        $q = $this->db->table('announcements')->where('organization_id', $organizationId);
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }
        $rows = $q->orderBy('created_at', 'DESC')->get()->getResultArray();

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(string $organizationId, string $id): ?array
    {
        $row = $this->findInOrg($organizationId, $id);
        if ($row === null) {
            return null;
        }
        $row['targets'] = $this->targetsOf($id);

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function findInOrg(string $organizationId, string $id): ?array
    {
        $row = $this->db->table('announcements')->where('id', $id)->get()->getRowArray();
        if ($row === null || (string) ($row['organization_id'] ?? '') !== $organizationId) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array{kind:string,ref:string,scope_mode:string}>
     */
    public function parseTargets(array $data): array
    {
        $out = [];
        $gid = trim((string) ($data['group_id'] ?? ''));
        if ($gid !== '') {
            $out[] = [
                'kind'       => 'group',
                'ref'        => $gid,
                'scope_mode' => AnnouncementScope::normalize($data['scope_mode'] ?? ''),
            ];
        }
        $kind = strtolower(trim((string) ($data['group_kind'] ?? '')));
        if ($kind !== '') {
            $out[] = ['kind' => 'group_kind', 'ref' => $kind, 'scope_mode' => ''];
        }
        foreach ($this->csv($data['membership_roles'] ?? '') as $r) {
            $out[] = ['kind' => 'membership_role', 'ref' => $r, 'scope_mode' => ''];
        }
        foreach ($this->csv($data['platform_roles'] ?? '') as $r) {
            $out[] = ['kind' => 'platform_role', 'ref' => $r, 'scope_mode' => ''];
        }
        foreach ($this->csv($data['user_ids'] ?? '') as $r) {
            $out[] = ['kind' => 'user', 'ref' => $r, 'scope_mode' => ''];
        }

        return $out;
    }

    /**
     * @param list<array{kind:string,ref:string,scope_mode:string}> $targets
     */
    private function writeTargets(string $announcementId, array $targets): void
    {
        foreach ($targets as $t) {
            $this->db->table('announcement_targets')->insert([
                'id'              => Uuid::v7(),
                'announcement_id' => $announcementId,
                'kind'            => $t['kind'],
                'ref'             => $t['ref'],
                'scope_mode'      => $t['scope_mode'] ?? '',
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function targetsOf(string $announcementId): array
    {
        return $this->db->table('announcement_targets')
            ->where('announcement_id', $announcementId)
            ->get()->getResultArray();
    }

    /** @return list<string> */
    private function csv(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[,\s]+/', (string) $raw) ?: [];
        }
        $out = [];
        foreach ($parts as $p) {
            $v = strtolower(trim((string) $p));
            if ($v !== '') {
                $out[$v] = true;
            }
        }

        return array_keys($out);
    }

    private function channel(mixed $raw): ?string
    {
        $c = strtolower(trim((string) $raw));
        if ($c === '') {
            return null;
        }

        return in_array($c, ['email', 'sms', 'inapp', 'in_app', 'push'], true) ? $c : 'email';
    }

    private function optionalStamp(mixed $raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $s) !== 1) {
            return null;
        }
        if (strlen($s) === 10) {
            $s .= ' 23:59:59';
        } elseif (strlen($s) === 16) {
            $s .= ':00';
        }

        return $s;
    }
}
