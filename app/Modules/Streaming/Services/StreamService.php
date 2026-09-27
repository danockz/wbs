<?php

declare(strict_types=1);

namespace WBS\Streaming\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\AccessControl\Services\AuthorizationService;
use WBS\Integrations\Providers\ProviderRegistry;
use WBS\Integrations\Services\ProviderReliabilityService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Stream lifecycle + destinations + access policy (SRS FR-STR-002/003/006/012).
 *
 * Access policy is independent of any provider's own policy: a `restricted`
 * stream requires an authenticated, authorized member even if the underlying
 * provider broadcast is public. Provider credentials are never stored or
 * returned here — destinations reference an integration connection whose secrets
 * live in the credential vault.
 */
final class StreamService
{
    /** Approved destination providers (honest capability lives per-row). */
    private const PROVIDERS = ['youtube', 'twitch', 'facebook', 'vimeo', 'telegram', 'rtmp', 'webrtc'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AuthorizationService $authz,
        private readonly ?ProviderRegistry $providers = null,
        private readonly ?ProviderReliabilityService $reliability = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(string $organizationId, string $createdBy, array $data): Result
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            return Result::fail('TITLE_REQUIRED', 'stream.title_required', 422);
        }
        $access = (string) ($data['access_policy'] ?? 'restricted');
        if (! in_array($access, ['public', 'restricted'], true)) {
            return Result::fail('BAD_ACCESS', 'stream.bad_access', 422);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('streams')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'event_id'        => $data['event_id'] ?? null,
            'group_id'        => $data['group_id'] ?? null,
            'created_by'      => $createdBy,
            'title'           => mb_substr((string) $data['title'], 0, 200),
            'description'     => $data['description'] ?? null,
            'access_policy'   => $access,
            'status'          => 'draft',
            'scheduled_at'    => $data['scheduled_at'] ?? null,
            'created_at'      => $now,
        ]);

        return Result::created(['stream_id' => $id, 'access_policy' => $access, 'status' => 'draft']);
    }

    /** @param array<string,mixed> $data */
    public function addDestination(string $streamId, array $data): Result
    {
        $provider = (string) ($data['provider'] ?? '');
        if (! in_array($provider, self::PROVIDERS, true)) {
            return Result::fail('BAD_PROVIDER', 'stream.bad_provider', 422);
        }

        $id = Uuid::v7();
        $this->db->table('stream_destinations')->insert([
            'id'            => $id,
            'stream_id'     => $streamId,
            'provider'      => $provider,
            'connection_id' => $data['connection_id'] ?? null,
            'label'         => $data['label'] ?? null,
            'capabilities'  => isset($data['capabilities']) ? json_encode($data['capabilities']) : null,
            'status'        => 'pending',
            'external_ref'  => $data['external_ref'] ?? null,
            'created_at'    => $this->clock->nowUtcMicro(),
        ]);

        return Result::created(['destination_id' => $id, 'provider' => $provider]);
    }

    public function goLive(string $streamId): Result
    {
        $result = $this->transition($streamId, 'live', ['started_at' => $this->clock->nowUtcMicro()]);
        if ($result->failed()) {
            return $result;
        }

        $dispatch = $this->dispatchToProviders($streamId, 'start');

        return Result::ok(['stream_id' => $streamId, 'status' => 'live', 'destinations' => $dispatch]);
    }

    public function end(string $streamId): Result
    {
        $result = $this->transition($streamId, 'ended', ['ended_at' => $this->clock->nowUtcMicro()]);
        if ($result->failed()) {
            return $result;
        }

        $dispatch = $this->dispatchToProviders($streamId, 'end');

        return Result::ok(['stream_id' => $streamId, 'status' => 'ended', 'destinations' => $dispatch]);
    }

    /**
     * Create the provider broadcast for each pending destination that has a
     * registered adapter. Stores the returned non-secret external_ref on the
     * destination; secrets (stream keys) are stored in the vault by the adapter.
     * A provider failure marks THAT destination 'error' but never aborts the
     * others — the local stream is already provisioned independently.
     */
    public function provisionDestinations(string $streamId): Result
    {
        if ($this->providers === null) {
            return Result::ok(['stream_id' => $streamId, 'destinations' => []]);
        }

        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }

        $destinations = $this->db->table('stream_destinations')
            ->where('stream_id', $streamId)->where('status', 'pending')
            ->get()->getResultArray();

        $out = [];
        foreach ($destinations as $dest) {
            $provider = (string) $dest['provider'];
            if (! $this->providers->has($provider)) {
                $out[] = ['destination_id' => $dest['id'], 'provider' => $provider, 'status' => 'skipped'];
                continue;
            }

            // FR-INT-012: fail fast when this provider's circuit is open, so a
            // broken provider isn't hammered — the destination is marked error
            // and the operator can engage the documented fallback (hosted link).
            $org = (string) ($stream['organization_id'] ?? '');
            if ($this->reliability !== null && $org !== '') {
                $gate = $this->reliability->admit($org, $provider);
                if (! $gate->ok) {
                    $this->db->table('stream_destinations')->where('id', $dest['id'])->update(['status' => 'error']);
                    $out[] = [
                        'destination_id' => $dest['id'],
                        'provider'       => $provider,
                        'status'         => 'error',
                        'detail'         => $gate->message,
                        'fallback'       => 'hosted_link',
                    ];
                    continue;
                }
            }

            try {
                $adapter = $this->providers->get($provider);
                $result  = $adapter->createBroadcast($stream, $dest);
                if ($this->reliability !== null && $org !== '') {
                    $this->reliability->recordSuccess($org, $provider);
                }
                $this->db->table('stream_destinations')->where('id', $dest['id'])->update([
                    'external_ref' => $result->externalRef,
                    'status'       => 'ready',
                ]);
                $out[] = [
                    'destination_id' => $dest['id'],
                    'provider'       => $provider,
                    'status'         => 'ready',
                    'external_ref'   => $result->externalRef,
                    'watch_url'      => $result->watchUrl,
                ];
            } catch (Throwable $e) {
                if ($this->reliability !== null && $org !== '') {
                    $this->reliability->recordFailure($org, $provider, $e->getMessage());
                }
                $this->db->table('stream_destinations')->where('id', $dest['id'])->update(['status' => 'error']);
                $out[] = ['destination_id' => $dest['id'], 'provider' => $provider, 'status' => 'error', 'detail' => $e->getMessage(), 'fallback' => 'hosted_link'];
            }
        }

        return Result::ok(['stream_id' => $streamId, 'destinations' => $out]);
    }

    /**
     * Dispatch a start/end transition to every ready destination's provider.
     * Provider failures are recorded per-destination and never abort the batch.
     *
     * @return list<array<string,mixed>>
     */
    private function dispatchToProviders(string $streamId, string $action): array
    {
        if ($this->providers === null) {
            return [];
        }

        $org = (string) ($this->db->table('streams')->select('organization_id')
            ->where('id', $streamId)->get()->getRowArray()['organization_id'] ?? '');

        $destinations = $this->db->table('stream_destinations')
            ->where('stream_id', $streamId)
            ->whereIn('status', ['ready', 'active'])
            ->get()->getResultArray();

        $out = [];
        foreach ($destinations as $dest) {
            $provider = (string) $dest['provider'];
            if (! $this->providers->has($provider)) {
                continue;
            }

            // FR-INT-012: skip a provider whose circuit is open (fail fast).
            if ($this->reliability !== null && $org !== '') {
                $gate = $this->reliability->admit($org, $provider);
                if (! $gate->ok) {
                    $this->db->table('stream_destinations')->where('id', $dest['id'])->update(['status' => 'error']);
                    $out[] = ['destination_id' => $dest['id'], 'provider' => $provider, 'status' => 'error', 'detail' => $gate->message, 'fallback' => 'hosted_link'];
                    continue;
                }
            }

            try {
                $adapter = $this->providers->get($provider);
                $action === 'start' ? $adapter->startBroadcast($dest) : $adapter->endBroadcast($dest);
                if ($this->reliability !== null && $org !== '') {
                    $this->reliability->recordSuccess($org, $provider);
                }
                $this->db->table('stream_destinations')->where('id', $dest['id'])->update([
                    'status' => $action === 'start' ? 'active' : 'removed',
                ]);
                $out[] = ['destination_id' => $dest['id'], 'provider' => $provider, 'status' => 'ok'];
            } catch (Throwable $e) {
                if ($this->reliability !== null && $org !== '') {
                    $this->reliability->recordFailure($org, $provider, $e->getMessage());
                }
                $this->db->table('stream_destinations')->where('id', $dest['id'])->update(['status' => 'error']);
                $out[] = ['destination_id' => $dest['id'], 'provider' => $provider, 'status' => 'error', 'detail' => $e->getMessage(), 'fallback' => 'hosted_link'];
            }
        }

        return $out;
    }

    /** Link a provider-retained recording as an external archived asset (FR-STR-012). */
    public function linkArchive(string $streamId, string $provider, string $externalUrl, string $accessPolicy = 'restricted'): Result
    {
        if (! preg_match('#^https://#i', $externalUrl)) {
            return Result::fail('BAD_URL', 'stream.bad_archive_url', 422);
        }
        $id = Uuid::v7();
        $this->db->table('stream_archives')->insert([
            'id'            => $id,
            'stream_id'     => $streamId,
            'provider'      => $provider,
            'external_url'  => $externalUrl,
            'access_policy' => in_array($accessPolicy, ['public', 'restricted'], true) ? $accessPolicy : 'restricted',
            'created_at'    => $this->clock->nowUtcMicro(),
        ]);

        return Result::created(['archive_id' => $id]);
    }

    /**
     * Resolve whether a viewer may access a stream (FR-STR-006). Public streams
     * are open; restricted streams require an authorized authenticated member.
     */
    public function canView(string $streamId, ?string $viewerId): Result
    {
        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }
        if ($stream['access_policy'] === 'public') {
            return Result::ok(['allowed' => true, 'reason' => 'public']);
        }
        if ($viewerId === null) {
            return Result::denied('stream.auth_required', 'AUTH_REQUIRED');
        }
        $allowed = $this->authz->isAllowed(new AccessRequest(
            organizationId: $stream['organization_id'],
            subjectId: $viewerId,
            action: 'stream.view',
            objectType: 'stream',
            objectId: $streamId,
            attributes: ['group_id' => $stream['group_id']],
        ));

        return $allowed
            ? Result::ok(['allowed' => true, 'reason' => 'authorized'])
            : Result::denied('stream.forbidden', 'FORBIDDEN');
    }

    public function find(string $streamId): ?array
    {
        return $this->db->table('streams')->where('id', $streamId)->get()->getRowArray() ?: null;
    }

    /**
     * Streams for an organization (SRS FR-STR-001) for the streams index page and
     * API — live first, then most recently started.
     *
     * @return list<array<string,mixed>>
     */
    public function listForOrg(string $organizationId, int $limit = 50): array
    {
        return $this->db->table('streams')
            ->select('id, title, status, access_policy, started_at')
            ->where('organization_id', $organizationId)
            ->orderBy("CASE WHEN status = 'live' THEN 0 ELSE 1 END", 'ASC', false)
            ->orderBy('started_at', 'DESC')
            ->limit(max(1, min($limit, 200)))
            ->get()->getResultArray();
    }

    /**
     * Organizer dashboard aggregation (SRS FR-STR-010/012).
     *
     * Every metric it returns is derived from data the platform actually holds —
     * destinations, chat volume, poll participation, and the LATEST provider
     * metric sample per (source, metric) with its exactness + retrieval time. It
     * never fabricates a provider-only figure: if no sample exists for a metric,
     * it is reported as unavailable rather than guessed.
     */
    public function organizerDashboard(string $streamId): Result
    {
        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }

        $destinations = $this->db->table('stream_destinations')
            ->select('id, provider, label, status, external_ref')
            ->where('stream_id', $streamId)->get()->getResultArray();

        $chatTotal = (int) $this->db->table('stream_chat_messages')
            ->where('stream_id', $streamId)->where('status', 'visible')->countAllResults();

        $polls = (int) $this->db->table('stream_polls')->where('stream_id', $streamId)->countAllResults();
        $pollVotes = (int) $this->db->query(
            'SELECT COUNT(*) AS c FROM stream_poll_votes v
             JOIN stream_polls p ON p.id = v.poll_id WHERE p.stream_id = ?',
            [$streamId],
        )->getRowArray()['c'] ?? 0;

        $archives = (int) $this->db->table('stream_archives')->where('stream_id', $streamId)->countAllResults();

        // Latest provider metric sample per (source, metric) — honest, timestamped.
        $samples = $this->db->query(
            'SELECT s.source, s.metric, s.value, s.exactness, s.retrieved_at, s.note
             FROM stream_metric_samples s
             JOIN (
               SELECT source, metric, MAX(retrieved_at) AS mx
               FROM stream_metric_samples WHERE stream_id = ?
               GROUP BY source, metric
             ) latest ON latest.source = s.source AND latest.metric = s.metric AND latest.mx = s.retrieved_at
             WHERE s.stream_id = ?',
            [$streamId, $streamId],
        )->getResultArray();

        return Result::ok([
            'stream'   => [
                'id'            => $stream['id'],
                'title'         => $stream['title'],
                'status'        => $stream['status'],
                'access_policy' => $stream['access_policy'],
                'started_at'    => $stream['started_at'],
                'ended_at'      => $stream['ended_at'],
            ],
            'destinations' => $destinations,
            'engagement'   => [
                'chat_messages'      => $chatTotal,
                'polls'              => $polls,
                'poll_votes'         => $pollVotes,
                'archived_recordings' => $archives,
            ],
            // Provider metrics: each carries source + exactness + retrieval time.
            'provider_metrics' => $samples,
            'metrics_note'     => 'Provider metrics reflect the latest sample per source; absent metrics are unavailable, not estimated.',
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function transition(string $streamId, string $status, array $extra = []): Result
    {
        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }
        $this->db->table('streams')->where('id', $streamId)->update(array_merge([
            'status'     => $status,
            'updated_at' => $this->clock->nowUtcMicro(),
        ], $extra));

        return Result::ok(['stream_id' => $streamId, 'status' => $status]);
    }
}
