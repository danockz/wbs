<?php

declare(strict_types=1);

namespace WBS\Notifications\Controllers;

use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Http\BaseController;

/**
 * Direct/transactional notification send (SRS FR-NOT-006/007).
 *
 * Every send passes through the RetentionPolicyGate; suppressed/deferred results
 * are recorded, not silently dropped.
 */
final class NotificationController extends BaseController
{
    public function send()
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondWith(NotificationServices::notifications()->send(
            $orgId,
            (string) ($in['user_id'] ?? ''),
            (string) ($in['channel'] ?? 'email'),
            (string) ($in['category'] ?? 'community'),
            [
                'body'          => $in['body'] ?? null,
                'context'       => $in['context'] ?? [],
                'category'      => $in['category'] ?? 'community',
                'campaign_id'   => $in['campaign_id'] ?? null,
                'frequency_cap' => $in['frequency_cap'] ?? null,
                'dedupe_key'    => $in['dedupe_key'] ?? null,
            ],
        ));
    }
}
