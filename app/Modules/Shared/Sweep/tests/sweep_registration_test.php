<?php

declare(strict_types=1);

/**
 * Sweep registration integrity test (Theme C).
 *
 * Asserts the module-contributed sweep ADAPTERS satisfy the SweepContract shape
 * and register cleanly with unique keys — the wiring the `sweep:run` command and
 * SweepRunner depend on. Uses the real adapter classes (constructing the ones
 * that need no DB, and a DB-less stub connection for the involvement adapter),
 * so a renamed/duplicate/empty key fails CI here rather than at 3am on cron.
 *
 *   php app/Modules/Shared/Sweep/tests/sweep_registration_test.php
 */

namespace CodeIgniter\Database {
    // Minimal stand-in so the involvement adapter can be constructed without a
    // live DB. Its run() is not invoked in this test.
    class BaseConnection
    {
    }
}

namespace {
    use WBS\Shared\Sweep\SweepContract;
    use WBS\Shared\Sweep\SweepRegistry;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Sweep/SweepResult.php';
    require_once $root . '/app/Modules/Shared/Sweep/SweepContract.php';
    require_once $root . '/app/Modules/Shared/Sweep/SweepRegistry.php';
    require_once $root . '/app/Modules/AccessControl/Sweep/AccessExpirySweep.php';
    require_once $root . '/app/Modules/Contributions/Sweep/CommitmentDueSweep.php';
    require_once $root . '/app/Modules/Events/Sweep/EventCloseDueSweep.php';
    require_once $root . '/app/Modules/Events/Sweep/EventRemindersDueSweep.php';
    require_once $root . '/app/Modules/Events/Sweep/ExpireHoldsSweep.php';
    require_once $root . '/app/Modules/Integrations/Sweep/PruneRetiredCredentialsSweep.php';
    require_once $root . '/app/Modules/Identity/Sweep/SessionPruneSweep.php';
    require_once $root . '/app/Modules/Journey/Sweep/InvolvementRecomputeSweep.php';
    require_once $root . '/app/Modules/Meetings/Sweep/EvidenceReconcileSweep.php';
    require_once $root . '/app/Modules/Notifications/Sweep/ReleaseDeferredSweep.php';
    require_once $root . '/app/Modules/Notifications/Sweep/BuildDigestsSweep.php';
    require_once $root . '/app/Modules/Notifications/Sweep/RunQueuedCampaignsSweep.php';
    require_once $root . '/app/Modules/Gamification/Sweep/ReclaimRolloversSweep.php';
    require_once $root . '/app/Modules/Contributions/Sweep/ReconcileLedgerSweep.php';
    require_once $root . '/app/Modules/Referrals/Sweep/FollowUpDecaySweep.php';
    require_once $root . '/app/Modules/Journey/Sweep/MarkDormantSweep.php';
    require_once $root . '/app/Modules/Journey/Sweep/ProposalAgingSweep.php';
    require_once $root . '/app/Modules/Identity/Sweep/VerifyExpirySweep.php';
    require_once $root . '/app/Modules/Streaming/Sweep/RelayHealthSweep.php';
    require_once $root . '/app/Modules/Community/Sweep/RetentionPurgeSweep.php';
    require_once $root . '/app/Modules/Gamification/Sweep/HeldReviewAgingSweep.php';
    require_once $root . '/app/Modules/Events/Sweep/CommitteeAuthorityExpirySweep.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    // The sweeps the Shared factory registers (mirrors sweepRegistry()).
    $sweeps = [
        new WBS\AccessControl\Sweep\AccessExpirySweep(),
        new WBS\Contributions\Sweep\CommitmentDueSweep(),
        new WBS\Events\Sweep\EventCloseDueSweep(),
        new WBS\Events\Sweep\EventRemindersDueSweep(),
        new WBS\Events\Sweep\ExpireHoldsSweep(),
        new WBS\Events\Sweep\CommitteeAuthorityExpirySweep(),
        new WBS\Integrations\Sweep\PruneRetiredCredentialsSweep(),
        new WBS\Identity\Sweep\SessionPruneSweep(),
        new WBS\Journey\Sweep\InvolvementRecomputeSweep(new CodeIgniter\Database\BaseConnection()),
        new WBS\Meetings\Sweep\EvidenceReconcileSweep(),
        new WBS\Notifications\Sweep\ReleaseDeferredSweep(),
        new WBS\Notifications\Sweep\BuildDigestsSweep(),
        new WBS\Notifications\Sweep\RunQueuedCampaignsSweep(),
        new WBS\Gamification\Sweep\ReclaimRolloversSweep(),
        new WBS\Contributions\Sweep\ReconcileLedgerSweep(),
        new WBS\Referrals\Sweep\FollowUpDecaySweep(),
        new WBS\Journey\Sweep\MarkDormantSweep(),
        new WBS\Journey\Sweep\ProposalAgingSweep(),
        new WBS\Identity\Sweep\VerifyExpirySweep(),
        new WBS\Streaming\Sweep\RelayHealthSweep(),
        new WBS\Community\Sweep\RetentionPurgeSweep(),
        new WBS\Gamification\Sweep\HeldReviewAgingSweep(),
    ];

    // Every adapter is a SweepContract with a non-empty key + description.
    foreach ($sweeps as $s) {
        $chk('implements SweepContract: ' . get_class($s), $s instanceof SweepContract);
        $chk('non-empty key: ' . get_class($s), $s->key() !== '');
        $chk('non-empty description: ' . $s->key(), $s->description() !== '');
    }

    // Registers cleanly with unique keys.
    $reg = new SweepRegistry($sweeps);
    $chk('all sweeps registered', count($reg->keys()) === count($sweeps));

    // The stable key set the scheduler/manifest depends on.
    $expected = [
        'acl.expire',
        'contributions.commitment-due',
        'events.close-due',
        'events.reminders-due',
        'events.expire-holds',
        'events.committee-expiry',
        'identity.session-prune',
        'journey.involvement-recompute',
        'meetings.evidence-reconcile',
        'notifications.release-deferred',
        'notifications.build-digests',
        'notifications.run-queued-campaigns',
        'gamification.reclaim-rollovers',
        'contributions.reconcile-ledger',
        'referrals.follow-up-decay',
        'integrations.prune-retired-credentials',
        'journey.mark-dormant',
        'journey.proposal-aging',
        'identity.verify-expiry',
        'streaming.relay-health',
        'community.retention-purge',
        'gamification.held-review-aging',
    ];
    sort($expected);
    $chk('registered keys match the expected manifest', $reg->keys() === $expected,
        implode(',', $reg->keys()));

    // Each key resolves back to a contract.
    foreach ($expected as $k) {
        $chk("registry resolves {$k}", $reg->get($k) instanceof SweepContract);
    }

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
