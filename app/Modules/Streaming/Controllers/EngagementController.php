<?php

declare(strict_types=1);

namespace WBS\Streaming\Controllers;

use WBS\Shared\Http\BaseController;
use WBS\Streaming\Config\Services as StreamingServices;

/**
 * Live chat, moderation, polls, metrics (SRS FR-STR-007/010).
 *
 * Chat is rate-limited at the route (ratelimit:stream.chat) in addition to
 * per-stream slow mode enforced in the service.
 */
final class EngagementController extends BaseController
{
    public function chat(string $streamId = '')
    {
        $in     = $this->input();
        $userId = $this->currentUserId('user_id');

        return $this->respondWith(StreamingServices::streamEngagement()->postChat(
            $streamId,
            $userId,
            (string) ($in['body'] ?? ''),
        ));
    }

    public function moderate(string $streamId = '')
    {
        $in  = $this->input();
        $mod = $this->currentUserId('moderator_id');

        return $this->respondWith(StreamingServices::streamEngagement()->moderate(
            $streamId,
            $mod,
            (string) ($in['action'] ?? ''),
            (string) ($in['reason'] ?? ''),
            $in['target_user_id'] ?? null,
            $in['message_id'] ?? null,
            isset($in['mute_seconds']) ? (int) $in['mute_seconds'] : null,
        ));
    }

    public function launchPoll(string $streamId = '')
    {
        $in = $this->input();

        return $this->respondWith(StreamingServices::streamEngagement()->launchPoll(
            $streamId,
            (string) ($in['question'] ?? ''),
            (array) ($in['options'] ?? []),
        ));
    }

    public function vote(string $pollId = '')
    {
        $in     = $this->input();
        $userId = $this->currentUserId('user_id');

        return $this->respondWith(StreamingServices::streamEngagement()->vote(
            $pollId,
            $userId,
            (string) ($in['option_key'] ?? ''),
        ));
    }

    public function closePoll(string $pollId = '')
    {
        return $this->respondWith(StreamingServices::streamEngagement()->closePoll(
            $pollId,
            (bool) $this->field('reveal_results', false),
        ));
    }

    public function metric(string $streamId = '')
    {
        $in = $this->input();

        return $this->respondWith(StreamingServices::streamEngagement()->recordMetric(
            $streamId,
            (string) ($in['source'] ?? ''),
            (string) ($in['metric'] ?? ''),
            isset($in['value']) ? (float) $in['value'] : null,
            (string) ($in['exactness'] ?? 'estimated'),
            $in['note'] ?? null,
        ));
    }

    /** S3: record a viewer joining (hashed identifiers only). */
    public function trackViewer(string $streamId = '')
    {
        $in = $this->input();

        return $this->respondWith(StreamingServices::streamEngagement()->trackViewer($streamId, [
            'ip'          => $this->request->getIPAddress(),
            'user_agent'  => $this->request->getUserAgent()->getAgentString(),
            'device_type' => $in['device_type'] ?? null,
            'viewer_id'   => $this->actorId('viewer_id'),
        ]));
    }

    /** S3: mark a viewer session ended. */
    public function endViewer(string $viewerSessionId = '')
    {
        return $this->respondWith(StreamingServices::streamEngagement()->endViewer($viewerSessionId));
    }

    /** S4: add a live reaction. */
    public function react(string $streamId = '')
    {
        $in     = $this->input();
        $userId = $this->currentUserId('user_id');

        return $this->respondWith(StreamingServices::streamEngagement()->addReaction(
            $streamId,
            $userId,
            (string) ($in['reaction_type'] ?? ''),
        ));
    }

    /** S6: real-time engagement metrics (read-side aggregation). */
    public function realtime(string $streamId = '')
    {
        $window = (int) $this->field('window_minutes', 5);
        $result = StreamingServices::streamEngagement()->realTimeMetrics($streamId, $window);
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Real-time engagement', $streamId);
        }

        return $this->respondWith($result, 'WBS\Streaming\Views\engagement_realtime', null, ['metrics' => $result->ok ? $result->data : null]);
    }

    /** S5: record a giving INTENT during a stream (completion via webhook). */
    public function giving(string $streamId = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondWith(StreamingServices::streamEngagement()->recordGiving($orgId, $streamId, [
            'amount_minor'    => $in['amount_minor'] ?? 0,
            'currency'        => $in['currency'] ?? '',
            'cause_id'        => $in['cause_id'] ?? '',
            'provider'        => $in['provider'] ?? null,
            'user_id'         => $this->actorId('user_id'),
            'recognition'     => $in['recognition'] ?? 'public',
            'idempotency_key' => $in['idempotency_key'] ?? null,
        ]));
    }
}
