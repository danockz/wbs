<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Broadcast campaign lifecycle (SRS FR-NOT-004/008).
 *
 * draft -> pending_approval -> approved -> queued -> sent, with separation of
 * duties: the approver MUST differ from the requester. The queued audience is
 * immutable for reporting (a snapshot count is frozen at approval time).
 */
final class CampaignService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        // N2 fan-out seams. Null → fanOutQueued() is a no-op (campaigns stay
        // queued) so the service constructs for the CRUD/approval tests that
        // don't exercise fan-out.
        private readonly ?CampaignAudiencePort $audience = null,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    /**
     * List an organization's campaigns (newest first), optionally filtered by
     * status. Read-only projection for the campaigns dashboard.
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $organizationId, ?string $status = null, int $limit = 100): array
    {
        $q = $this->db->table('notification_campaigns')
            ->where('organization_id', $organizationId);
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return $q->orderBy('created_at', 'DESC')
            ->get(max(1, min(500, $limit)))
            ->getResultArray();
    }

    /** @param array<string,mixed> $data */
    public function create(string $organizationId, string $requestedBy, array $data): Result
    {
        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('notification_campaigns')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'group_id'        => $data['group_id'] ?? null,
            'name'            => $data['name'] ?? 'Untitled campaign',
            'template_key'    => $data['template_key'] ?? '',
            'channel'         => $data['channel'] ?? 'email',
            'category'        => $data['category'] ?? 'community',
            'priority'        => $data['priority'] ?? 'normal',
            'audience_filter' => isset($data['audience_filter']) ? json_encode($data['audience_filter'], JSON_UNESCAPED_UNICODE) : null,
            'status'          => 'draft',
            'requested_by'    => $requestedBy,
            'created_at'      => $now,
        ]);

        return Result::created(['id' => $id, 'status' => 'draft']);
    }

    /** Submit for approval, freezing the estimated audience count. */
    public function submitForApproval(string $campaignId, int $audienceCount): Result
    {
        $c = $this->find($campaignId);
        if ($c === null) {
            return Result::notFound('notification.campaign_not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['status'] !== 'draft') {
            return Result::fail('BAD_STATE', 'notification.bad_state', 409, ['status' => $c['status']]);
        }
        $this->db->table('notification_campaigns')->where('id', $campaignId)->update([
            'status'         => 'pending_approval',
            'audience_count' => $audienceCount,
            'updated_at'     => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['id' => $campaignId, 'status' => 'pending_approval']);
    }

    /**
     * Approve a campaign. Enforces separation of duties: a user cannot approve
     * a campaign they requested (FR-NOT-008 / FR-ACL SoD).
     */
    public function approve(string $campaignId, string $approverId): Result
    {
        $c = $this->find($campaignId);
        if ($c === null) {
            return Result::notFound('notification.campaign_not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['status'] !== 'pending_approval') {
            return Result::fail('BAD_STATE', 'notification.bad_state', 409, ['status' => $c['status']]);
        }
        if ((string) $c['requested_by'] === $approverId) {
            return Result::fail('SOD_SELF_APPROVAL', 'notification.self_approval', 422);
        }
        $this->db->table('notification_campaigns')->where('id', $campaignId)->update([
            'status'      => 'approved',
            'approved_by' => $approverId,
            'updated_at'  => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['id' => $campaignId, 'status' => 'approved']);
    }

    /** Mark an approved campaign queued for fan-out. */
    public function markQueued(string $campaignId): Result
    {
        $c = $this->find($campaignId);
        if ($c === null) {
            return Result::notFound('notification.campaign_not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['status'] !== 'approved') {
            return Result::fail('BAD_STATE', 'notification.bad_state', 409, ['status' => $c['status']]);
        }
        $this->db->table('notification_campaigns')->where('id', $campaignId)->update([
            'status'     => 'queued',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['id' => $campaignId, 'status' => 'queued']);
    }

    /**
     * N2 — fan a QUEUED campaign out to its audience. Nothing ever consumed the
     * `queued` status, so an approved+queued campaign was never actually sent.
     * This bounded, idempotent pass:
     *
     *   - resolves the campaign's audience (via the audience port),
     *   - issues one gated send() per recipient with a stable per-(campaign,user)
     *     dedupe key, so a re-run (or a crash mid-fan-out) never double-sends —
     *     the NotificationService dedupe short-circuits already-created rows,
     *   - flips the campaign to `sent` once the audience is dispatched.
     *
     * Each send still runs the RetentionPolicyGate, so opt-outs / quiet hours /
     * unverified channels are honoured per recipient (a deferred one is picked up
     * later by the N1 release sweep). Only an `approved` or `queued` campaign is
     * eligible; anything else is a BAD_STATE.
     *
     * @return Result data: campaign_id, recipients, sent, status
     */
    public function fanOutQueued(string $campaignId): Result
    {
        $c = $this->find($campaignId);
        if ($c === null) {
            return Result::notFound('notification.campaign_not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if (! in_array((string) $c['status'], ['approved', 'queued'], true)) {
            return Result::fail('BAD_STATE', 'notification.bad_state', 409, ['status' => $c['status']]);
        }
        if ($this->audience === null || $this->notifications === null) {
            return Result::fail('FANOUT_NOT_WIRED', 'notification.fanout_not_wired', 501);
        }

        $recipients = $this->audience->resolve($c);
        $sent       = 0;
        foreach ($recipients as $userId) {
            $userId = (string) $userId;
            if ($userId === '') {
                continue;
            }
            $res = $this->notifications->send(
                (string) $c['organization_id'],
                $userId,
                (string) $c['channel'],
                (string) $c['category'],
                [
                    'campaign_id'      => $campaignId,
                    'priority'         => (string) ($c['priority'] ?? 'normal'),
                    // Stable idempotency: one delivery per (campaign, user).
                    'dedupe_key'       => $campaignId . ':' . $userId,
                    'template_version' => $c['template_version'] ?? null,
                    'template_key'     => (string) ($c['template_key'] ?? ''),
                    'group_id'         => $c['group_id'] ?? null,
                ],
            );
            if ($res->ok) {
                $sent++;
            }
        }

        $this->db->table('notification_campaigns')->where('id', $campaignId)->update([
            'status'     => 'sent',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok([
            'campaign_id' => $campaignId,
            'recipients'  => count($recipients),
            'sent'        => $sent,
            'status'      => 'sent',
        ]);
    }

    /**
     * N2 sweep entry point — fan out EVERY queued campaign for an org (or all
     * orgs), bounded. Returns the number of campaigns dispatched. Idempotent: a
     * campaign flips to `sent` once fanned out, so it is not re-selected.
     */
    public function fanOutAllQueued(?string $organizationId, int $limit = 100): int
    {
        $limit = max(1, min(1000, $limit));
        $q     = $this->db->table('notification_campaigns')
            ->where('status', 'queued')
            ->orderBy('updated_at', 'ASC');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $rows = $q->get($limit)->getResultArray();

        $count = 0;
        foreach ($rows as $row) {
            $res = $this->fanOutQueued((string) $row['id']);
            if ($res->ok) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array<string,mixed>|null */
    private function find(string $campaignId): ?array
    {
        return $this->db->table('notification_campaigns')->where('id', $campaignId)->get()->getRowArray() ?: null;
    }
}
