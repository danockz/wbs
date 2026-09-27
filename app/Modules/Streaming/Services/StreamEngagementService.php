<?php

declare(strict_types=1);

namespace WBS\Streaming\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Contributions\Services\ContributionService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Live engagement: chat, moderation, polls, metrics (SRS FR-STR-007/010).
 *
 * Chat is authenticated-only and honors per-stream slow mode + bans/mutes.
 * Moderation actions REQUIRE a visible policy reason and are recorded as audit
 * evidence. Metric samples are stored exactly as reported by a provider, each
 * tagged with source/exactness/retrieval time — the platform never fabricates a
 * metric a provider does not expose (FR-STR-010).
 */
final class StreamEngagementService
{
    /** Reaction types accepted (S4). */
    private const REACTIONS = ['like', 'love', 'clap', 'celebrate', 'amen', 'wow'];

    /** Coarse device buckets (S3) — no fingerprinting, no raw UA stored. */
    private const DEVICE_TYPES = ['desktop', 'mobile', 'tablet', 'tv', 'other'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly string $ipSalt = 'wbs-stream-salt',
        private readonly ?ContributionService $contributions = null,
    ) {
    }

    public function postChat(string $streamId, string $userId, string $body): Result
    {
        $body = trim($body);
        if ($body === '') {
            return Result::fail('EMPTY_MESSAGE', 'stream.empty_message', 422);
        }

        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null || $stream['status'] !== 'live') {
            return Result::fail('NOT_LIVE', 'stream.not_live', 409);
        }

        // Ban / mute enforcement.
        $ban = $this->db->table('stream_bans')
            ->where('stream_id', $streamId)->where('user_id', $userId)
            ->get()->getRowArray();
        if ($ban !== null) {
            if ((int) $ban['banned'] === 1) {
                return Result::denied('stream.banned', 'BANNED');
            }
            if ($ban['muted_until'] !== null && $ban['muted_until'] > $this->clock->nowUtcMicro()) {
                return Result::denied('stream.muted', 'MUTED');
            }
        }

        // Slow mode: enforce minimum seconds between a user's messages.
        $slow = (int) $stream['slow_mode_secs'];
        if ($slow > 0) {
            $last = $this->db->table('stream_chat_messages')
                ->where('stream_id', $streamId)->where('user_id', $userId)
                ->orderBy('created_at', 'DESC')->limit(1)->get()->getRowArray();
            if ($last !== null) {
                $elapsed = strtotime($this->clock->nowUtcString()) - strtotime($last['created_at']);
                if ($elapsed < $slow) {
                    return Result::fail('SLOW_MODE', 'stream.slow_mode', 429, [], ['retry_after' => $slow - $elapsed]);
                }
            }
        }

        $id = Uuid::v7();
        $this->db->table('stream_chat_messages')->insert([
            'id'         => $id,
            'stream_id'  => $streamId,
            'user_id'    => $userId,
            'body'       => mb_substr($body, 0, 2000),
            'status'     => 'visible',
            'created_at' => $this->clock->nowUtcMicro(),
        ]);

        return Result::created(['message_id' => $id]);
    }

    public function setSlowMode(string $streamId, int $seconds): Result
    {
        $this->db->table('streams')->where('id', $streamId)->update([
            'slow_mode_secs' => max(0, min($seconds, 3600)),
            'updated_at'     => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['stream_id' => $streamId, 'slow_mode_secs' => max(0, min($seconds, 3600))]);
    }

    /** Moderation action with a MANDATORY visible reason (FR-STR-007). */
    public function moderate(
        string $streamId,
        string $moderatorId,
        string $action,
        string $reason,
        ?string $targetUserId = null,
        ?string $messageId = null,
        ?int $muteSeconds = null,
    ): Result {
        if (! in_array($action, ['delete', 'mute', 'ban', 'unban', 'slow_mode'], true)) {
            return Result::fail('BAD_ACTION', 'stream.bad_action', 422);
        }
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'stream.reason_required', 422);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->transStart();

        if ($action === 'delete' && $messageId !== null) {
            $this->db->table('stream_chat_messages')->where('id', $messageId)
                ->update(['status' => 'deleted']);
        }
        if (in_array($action, ['mute', 'ban', 'unban'], true) && $targetUserId !== null) {
            $this->upsertBan($streamId, $targetUserId, $action, $muteSeconds, $now);
        }

        $modId = Uuid::v7();
        $this->db->table('stream_moderation')->insert([
            'id'             => $modId,
            'stream_id'      => $streamId,
            'moderator_id'   => $moderatorId,
            'target_user_id' => $targetUserId,
            'message_id'     => $messageId,
            'action'         => $action,
            'reason'         => mb_substr($reason, 0, 500),
            'created_at'     => $now,
        ]);

        $this->db->transComplete();

        return Result::ok(['moderation_id' => $modId, 'action' => $action]);
    }

    /** @param list<array{key:string,label:string}> $options */
    public function launchPoll(string $streamId, string $question, array $options): Result
    {
        if (trim($question) === '' || count($options) < 2) {
            return Result::fail('BAD_POLL', 'stream.bad_poll', 422);
        }
        $id = Uuid::v7();
        $this->db->table('stream_polls')->insert([
            'id'         => $id,
            'stream_id'  => $streamId,
            'question'   => mb_substr($question, 0, 500),
            'options'    => json_encode(array_values($options)),
            'status'     => 'open',
            'created_at' => $this->clock->nowUtcMicro(),
        ]);

        return Result::created(['poll_id' => $id]);
    }

    public function vote(string $pollId, string $userId, string $optionKey): Result
    {
        $poll = $this->db->table('stream_polls')->where('id', $pollId)->get()->getRowArray();
        if ($poll === null || $poll['status'] !== 'open') {
            return Result::fail('POLL_CLOSED', 'stream.poll_closed', 409);
        }
        $options = json_decode((string) $poll['options'], true) ?: [];
        $keys    = array_column($options, 'key');
        if (! in_array($optionKey, $keys, true)) {
            return Result::fail('BAD_OPTION', 'stream.bad_option', 422);
        }
        try {
            $this->db->table('stream_poll_votes')->insert([
                'poll_id'    => $pollId,
                'user_id'    => $userId,
                'option_key' => $optionKey,
                'created_at' => $this->clock->nowUtcMicro(),
            ]);
        } catch (\Throwable) {
            return Result::fail('ALREADY_VOTED', 'stream.already_voted', 409);
        }

        return Result::created(['poll_id' => $pollId, 'option' => $optionKey]);
    }

    public function closePoll(string $pollId, bool $revealResults): Result
    {
        $this->db->table('stream_polls')->where('id', $pollId)->update([
            'status'           => 'closed',
            'results_revealed' => $revealResults ? 1 : 0,
            'closed_at'        => $this->clock->nowUtcMicro(),
        ]);
        $tally = $this->db->table('stream_poll_votes')
            ->select('option_key, COUNT(*) AS votes')
            ->where('poll_id', $pollId)->groupBy('option_key')->get()->getResultArray();

        return Result::ok(['poll_id' => $pollId, 'revealed' => $revealResults, 'tally' => $tally]);
    }

    /**
     * Record a provider-sourced metric sample. `exactness` MUST be one of
     * exact|estimated|unavailable so the dashboard never implies false precision.
     */
    public function recordMetric(string $streamId, string $source, string $metric, ?float $value, string $exactness, ?string $note = null): Result
    {
        if (! in_array($exactness, ['exact', 'estimated', 'unavailable'], true)) {
            return Result::fail('BAD_EXACTNESS', 'stream.bad_exactness', 422);
        }
        $id = Uuid::v7();
        $this->db->table('stream_metric_samples')->insert([
            'id'           => $id,
            'stream_id'    => $streamId,
            'source'       => $source,
            'metric'       => $metric,
            'value'        => $exactness === 'unavailable' ? null : $value,
            'exactness'    => $exactness,
            'retrieved_at' => $this->clock->nowUtcMicro(),
            'note'         => $note,
        ]);

        return Result::created(['sample_id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Viewer session tracking (S3) — privacy-safe: hashed identifiers only.
    // -------------------------------------------------------------------------

    /**
     * Record a viewer joining. Stores ONLY a salted ip_hash, a ua_hash, and a
     * coarse device bucket — never a raw IP/UA/city/referrer.
     *
     * @param array<string,mixed> $ctx ip, user_agent, device_type, viewer_id
     */
    public function trackViewer(string $streamId, array $ctx = []): Result
    {
        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }

        $device = (string) ($ctx['device_type'] ?? 'other');
        if (! in_array($device, self::DEVICE_TYPES, true)) {
            $device = 'other';
        }

        $id = Uuid::v7();
        $this->db->table('stream_viewers')->insert([
            'id'          => $id,
            'stream_id'   => $streamId,
            'viewer_id'   => $ctx['viewer_id'] ?? null,
            'ip_hash'     => isset($ctx['ip']) ? hash_hmac('sha256', (string) $ctx['ip'], $this->ipSalt) : null,
            'ua_hash'     => isset($ctx['user_agent']) ? hash('sha256', (string) $ctx['user_agent']) : null,
            'device_type' => $device,
            'joined_at'   => $this->clock->nowUtcMicro(),
        ]);

        return Result::created(['viewer_session_id' => $id, 'concurrent' => $this->concurrentViewers($streamId)]);
    }

    /** Mark a viewer session ended (sets left_at) and return current concurrency. */
    public function endViewer(string $viewerSessionId): Result
    {
        $this->db->table('stream_viewers')
            ->where('id', $viewerSessionId)->where('left_at', null)
            ->update(['left_at' => $this->clock->nowUtcMicro()]);

        return Result::ok(['viewer_session_id' => $viewerSessionId, 'ended' => true]);
    }

    /** Current concurrent viewers = sessions with no left_at. */
    public function concurrentViewers(string $streamId): int
    {
        return $this->db->table('stream_viewers')
            ->where('stream_id', $streamId)->where('left_at', null)
            ->countAllResults();
    }

    // -------------------------------------------------------------------------
    // Reactions (S4).
    // -------------------------------------------------------------------------

    public function addReaction(string $streamId, string $userId, string $reactionType): Result
    {
        if (! in_array($reactionType, self::REACTIONS, true)) {
            return Result::fail('BAD_REACTION', 'stream.bad_reaction', 422);
        }
        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null || $stream['status'] !== 'live') {
            return Result::fail('NOT_LIVE', 'stream.not_live', 409);
        }

        $id = Uuid::v7();
        $this->db->table('stream_reactions')->insert([
            'id'            => $id,
            'stream_id'     => $streamId,
            'user_id'       => $userId,
            'reaction_type' => $reactionType,
            'created_at'    => $this->clock->nowUtcMicro(),
        ]);

        return Result::created(['reaction_id' => $id, 'type' => $reactionType]);
    }

    // -------------------------------------------------------------------------
    // Stream giving (S5) — routed through Contributions, never a fake completion.
    // -------------------------------------------------------------------------

    /**
     * Record a viewer "giving" during a live stream. This does NOT mark money as
     * received (unlike the source spec's processGiving, which blindly wrote
     * status='completed'). It creates a Contributions INTENT tagged with the
     * stream; the real payment completes via the Contributions provider webhook.
     *
     * @param array<string,mixed> $data amount_minor, currency, cause_id,
     *                                   provider, user_id, recognition,
     *                                   idempotency_key
     */
    public function recordGiving(string $organizationId, string $streamId, array $data): Result
    {
        if ($this->contributions === null) {
            return Result::fail('GIVING_UNAVAILABLE', 'stream.giving_unavailable', 501);
        }
        $causeId = (string) ($data['cause_id'] ?? '');
        if ($causeId === '') {
            return Result::fail('CAUSE_REQUIRED', 'stream.cause_required', 422);
        }
        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }

        // Tag the intent with the originating stream so post-webhook reconciliation
        // and stream giving totals can attribute it, via a deterministic source ref.
        $intent = $this->contributions->createIntent($organizationId, $causeId, [
            'amount_minor'    => $data['amount_minor'] ?? 0,
            'currency'        => $data['currency'] ?? '',
            'provider'        => $data['provider'] ?? null,
            'user_id'         => $data['user_id'] ?? null,
            'recognition'     => $data['recognition'] ?? 'public',
            'idempotency_key' => $data['idempotency_key'] ?? ('stream:' . $streamId . ':' . Uuid::v7()),
        ]);

        if ($intent->failed()) {
            return $intent;
        }

        return Result::created([
            'stream_id' => $streamId,
            'intent'    => $intent->data,
            'note'      => 'giving intent created; completion arrives via contributions webhook',
        ], ['deferred_completion' => true]);
    }

    // -------------------------------------------------------------------------
    // Real-time metrics (S6) — read-side aggregation over the tables above.
    // -------------------------------------------------------------------------

    /**
     * Compute live engagement metrics for a stream from what we actually record:
     * current viewers, chat/reactions in a recent window, and a simple
     * engagement score. All numbers are ours (exact) — provider fan-in is a
     * separate concern (see recordMetric / the deferred analytics-fan-in TODO).
     */
    public function realTimeMetrics(string $streamId, int $windowMinutes = 5): Result
    {
        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }

        $since = $this->clock->now()->modify("-{$windowMinutes} minutes")->format('Y-m-d H:i:s.u');

        $concurrent = $this->concurrentViewers($streamId);
        $chatCount  = $this->db->table('stream_chat_messages')
            ->where('stream_id', $streamId)->where('created_at >=', $since)
            ->where('status', 'visible')->countAllResults();
        $reactCount = $this->db->table('stream_reactions')
            ->where('stream_id', $streamId)->where('created_at >=', $since)->countAllResults();

        $reactionBreakdown = $this->db->table('stream_reactions')
            ->select('reaction_type, COUNT(*) AS n')
            ->where('stream_id', $streamId)->where('created_at >=', $since)
            ->groupBy('reaction_type')->get()->getResultArray();

        // Engagement score: interactions per active viewer in the window,
        // clamped to [0,100]. Honest and simple — not a black box.
        $interactions = $chatCount + $reactCount;
        $score        = $concurrent > 0
            ? (int) min(100, round(($interactions / $concurrent) * 100))
            : 0;

        // Honest degradation (FR-STR-013): if the relay is degraded/down or an
        // incident is open, these figures may be partial (some destinations off
        // the relay, provider metrics unavailable). Never imply normal tracking.
        $relayState  = (string) ($stream['relay_state'] ?? 'healthy');
        $openIncident = $this->db->table('stream_relay_incidents')
            ->where('stream_id', $streamId)->whereIn('status', ['open', 'acknowledged'])
            ->countAllResults() > 0;
        $degraded = $relayState !== 'healthy' || $openIncident;

        return Result::ok([
            'stream_id'          => $streamId,
            'window_minutes'     => $windowMinutes,
            'concurrent_viewers' => $concurrent,
            'chat_last_window'   => $chatCount,
            'reactions_last_window' => $reactCount,
            'reaction_breakdown' => $reactionBreakdown,
            'engagement_score'   => $score,
            'relay_state'        => $relayState,
            'metrics_degraded'   => $degraded,
            'metrics_exactness'  => $degraded ? 'estimated' : 'exact',
        ]);
    }

    private function upsertBan(string $streamId, string $userId, string $action, ?int $muteSeconds, string $now): void
    {
        $banned      = $action === 'ban' ? 1 : 0;
        $mutedUntil  = null;
        if ($action === 'mute' && $muteSeconds !== null) {
            $mutedUntil = $this->clock->now()->modify("+{$muteSeconds} seconds")->format('Y-m-d H:i:s.u');
        }
        if ($action === 'unban') {
            $this->db->table('stream_bans')->where('stream_id', $streamId)->where('user_id', $userId)->delete();

            return;
        }
        $existing = $this->db->table('stream_bans')
            ->where('stream_id', $streamId)->where('user_id', $userId)->get()->getRowArray();
        $row = [
            'stream_id'   => $streamId,
            'user_id'     => $userId,
            'muted_until' => $mutedUntil,
            'banned'      => $banned,
            'updated_at'  => $now,
        ];
        if ($existing === null) {
            $this->db->table('stream_bans')->insert($row);
        } else {
            $this->db->table('stream_bans')->where('stream_id', $streamId)->where('user_id', $userId)->update($row);
        }
    }
}
