<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

use CodeIgniter\Database\BaseConnection;
use WBS\Notifications\Services\NotificationService;

/**
 * Performs the REAL outbound delivery for a dequeued `notification.dispatch`
 * job — the behaviour that was previously stubbed (the dispatch handler used to
 * flip a delivery to "sent" without ever calling a transport).
 *
 * Flow:
 *   1. Load the durable delivery row (recipient snapshot, channel, status).
 *   2. Idempotency: a row that is missing or already past "queued" is a no-op
 *      ack (a redelivered job must not send twice).
 *   3. Resolve the {@see ChannelTransport} for the channel and call it with the
 *      already-rendered body.
 *   4. Map the {@see TransportResult} onto the delivery lifecycle + circuit
 *      breaker (via {@see NotificationService::updateDeliveryStatus()}):
 *        accepted → "sent"     (ack; breaker success)
 *        rejected → "failed"   (ack; permanent, breaker untouched)
 *        failed   → throw       (nack → queue retries; breaker failure)
 *
 * The breaker *admission* check stays in the JobRouter (fail fast before any
 * work); this class owns the transport call and the outcome mapping.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly TransportRegistry $transports,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param array<string,mixed> $data notification.dispatch payload
     *        { delivery_id, channel, category, body, organization_id }
     *
     * @return bool true to ack the job; throws to trigger a retry
     */
    public function dispatch(array $data): bool
    {
        $deliveryId = (string) ($data['delivery_id'] ?? '');
        if ($deliveryId === '') {
            return true;
        }

        $row = $this->db->table('notification_deliveries')
            ->select('id, organization_id, group_id, channel, category, status, recipient_snapshot')
            ->where('id', $deliveryId)
            ->get()
            ->getRowArray();

        // Unknown or already-progressed delivery: nothing to do (idempotent ack).
        if ($row === null || (string) $row['status'] !== 'queued') {
            return true;
        }

        $channel = (string) ($data['channel'] ?? $row['channel']);

        // No transport for the channel is a hard error, not a silent "sent" —
        // the queue retries and operators see the misconfiguration.
        if (! $this->transports->has($channel)) {
            throw new \RuntimeException(sprintf('No transport for channel "%s".', $channel));
        }

        $recipient = $this->resolveRecipient($channel, $row['recipient_snapshot'] ?? null);

        $message = new TransportMessage(
            deliveryId: $deliveryId,
            organizationId: (string) ($data['organization_id'] ?? $row['organization_id']),
            channel: $channel,
            category: (string) ($data['category'] ?? $row['category']),
            recipient: $recipient,
            body: (string) ($data['body'] ?? ''),
            subject: isset($data['subject']) ? (string) $data['subject'] : null,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
            // Whose credentials this sends on: the job payload wins (a campaign
            // dispatch may name it), else the group stamped on the delivery row.
            groupId: isset($data['group_id']) && $data['group_id'] !== ''
                ? (string) $data['group_id']
                : (isset($row['group_id']) && $row['group_id'] !== '' ? (string) $row['group_id'] : null),
        );

        $result = $this->transports->for($channel)->deliver($message);

        if ($result->isAccepted()) {
            $this->notifications->updateDeliveryStatus($deliveryId, 'sent', $result->providerRequestId);

            return true;
        }

        if ($result->isRejected()) {
            // Permanent per-message failure: record it, do NOT retry, and do NOT
            // blame the provider's circuit (updateDeliveryStatus feeds the breaker
            // only for sent/delivered/failed — a rejection maps to "failed" here,
            // which the breaker treats as a fault; to keep a bad address from
            // tripping the breaker we record the terminal state directly).
            $this->notifications->recordTerminalRejection($deliveryId, (string) $result->reason);

            return true;
        }

        // Transient provider fault: raise so the queue retries with backoff. The
        // breaker failure signal is recorded on the way out.
        $this->notifications->updateDeliveryStatus($deliveryId, 'failed');

        throw new \RuntimeException(sprintf(
            'notification transport failed for %s: %s',
            $channel,
            (string) $result->reason,
        ));
    }

    /**
     * Extract the channel-appropriate endpoint from the immutable recipient
     * snapshot recorded at send() time. The snapshot is the source of truth so a
     * later change to the user's contact details cannot retarget an in-flight
     * delivery.
     *
     * @param mixed $snapshot JSON string or already-decoded array
     */
    private function resolveRecipient(string $channel, mixed $snapshot): string
    {
        $data = [];
        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);
            $data    = is_array($decoded) ? $decoded : [];
        } elseif (is_array($snapshot)) {
            $data = $snapshot;
        }

        $keys = match (strtolower($channel)) {
            'email'        => ['email', 'address', 'endpoint'],
            'sms'          => ['phone', 'msisdn', 'endpoint'],
            'push'         => ['device_token', 'token', 'endpoint'],
            default        => ['endpoint', 'address'],
        };
        foreach ($keys as $k) {
            if (isset($data[$k]) && is_scalar($data[$k]) && (string) $data[$k] !== '') {
                return (string) $data[$k];
            }
        }

        return '';
    }
}
