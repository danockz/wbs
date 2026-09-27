<?php

declare(strict_types=1);

namespace WBS\Community\Sweep;

use WBS\Community\Config\Services as CommunityServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, CM4): hard-purge soft-deleted community content.
 *
 * Community posts/comments are soft-removed by state transition
 * (`status='deleted'`, stamped `deleted_at`) so moderators can restore them and
 * the `moderation_actions` audit trail survives — but nothing ever hard-removed
 * them, so terminal rows lingered indefinitely. This wraps the already-bounded,
 * idempotent ModerationService::purgeDeletedContent() (re-asserts the terminal
 * predicate in the DELETE, never touches active/hidden/archived rows, cascades
 * comments/reactions/topics, leaves the append-only audit intact) so deleted
 * content leaves storage once its grace window elapses.
 *
 * Retention hygiene — no config gate, no notifications (mirrors the IN3
 * credential prune + identity session/verify prunes).
 */
final class RetentionPurgeSweep implements SweepContract
{
    public function key(): string
    {
        return 'community.retention-purge';
    }

    public function description(): string
    {
        return 'Hard-delete soft-deleted community posts/comments past their retention grace window (audit trail kept).';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $graceDays = isset($options['grace_days']) ? (int) $options['grace_days'] : 30;
        $limit     = isset($options['limit']) ? (int) $options['limit'] : 500;

        $r = CommunityServices::moderation()->purgeDeletedContent($organizationId, $graceDays, $limit);

        return SweepResult::ok((int) $r['posts'], [
            'scanned'  => (int) $r['scanned'],
            'posts'    => (int) $r['posts'],
            'comments' => (int) $r['comments'],
        ]);
    }
}
