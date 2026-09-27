<?php

declare(strict_types=1);

/**
 * JobRouter — group-teardown fan-out wiring (Theme B group-half).
 *
 * Pins the contract that the Groups lifecycle events route to the group-scoped
 * consumers:
 *   - group.dissolved -> grantCascade->onGroupTornDown for the group, reason=topic;
 *   - group.merged    -> onGroupTornDown for the FROM (loser) group, never the
 *     survivor, reason=group.merged;
 *   - group.archived  -> recognised + acked as a REVERSIBLE no-op (no cascade);
 *   - missing org/group short-circuits (acked, no cascade);
 *   - a throwing consumer still acks the job (fault isolation);
 *   - the job is acked (returns true) throughout.
 *
 * Uses the same FAKE-facade pattern as jobrouter_account_teardown_test.
 *
 *   php app/Modules/Shared/Messaging/tests/jobrouter_group_teardown_test.php
 */

namespace Spy {
    final class GrantCascade
    {
        /** @var list<array{org:string,group:string,reason:string}> */
        public array $groupCalls = [];
        public bool $throw = false;

        public function onGroupTornDown(string $org, string $group, string $reason)
        {
            $this->groupCalls[] = ['org' => $org, 'group' => $group, 'reason' => $reason];
            if ($this->throw) {
                throw new \RuntimeException('group cascade boom');
            }

            return \WBS\Shared\Support\Result::ok();
        }
    }

    final class Journey
    {
        /** @var list<array{org:string,group:string,reason:string}> */
        public array $groupArchived = [];

        public function archiveGroupContextJourneys(string $org, string $group, string $reason): int
        {
            $this->groupArchived[] = ['org' => $org, 'group' => $group, 'reason' => $reason];

            return 0;
        }
    }

    final class Cause
    {
        /** @var list<array{org:string,group:string,reason:string,survivor:?string}> */
        public array $reattributed = [];

        public function reattributeGroupCauses(string $org, string $group, string $reason, ?string $survivor = null)
        {
            $this->reattributed[] = ['org' => $org, 'group' => $group, 'reason' => $reason, 'survivor' => $survivor];

            return \WBS\Shared\Support\Result::ok();
        }
    }

    final class Contact
    {
        /** @var list<array{org:string,group:string,reason:string,survivor:?string}> */
        public array $repaired = [];

        public function onGroupTornDown(string $org, string $group, string $reason, ?string $survivor = null)
        {
            $this->repaired[] = ['org' => $org, 'group' => $group, 'reason' => $reason, 'survivor' => $survivor];

            return \WBS\Shared\Support\Result::ok();
        }
    }

    final class Reg
    {
        public static GrantCascade $cascade;
        public static Journey $journey;
        public static Cause $cause;
        public static Contact $contact;

        public static function reset(): void
        {
            self::$cascade = new GrantCascade();
            self::$journey = new Journey();
            self::$cause   = new Cause();
            self::$contact = new Contact();
        }
    }
}

namespace WBS\Shared\Support {
    if (! class_exists(Result::class)) {
        final class Result
        {
            public function __construct(public bool $ok = true)
            {
            }

            public static function ok($d = null): self
            {
                return new self(true);
            }
        }
    }
}

// ---- Fake module Services facades (exact FQCNs; real files never loaded) -----
namespace WBS\AccessControl\Config {
    final class Services
    {
        public static function grantCascade(bool $shared = true)
        {
            return \Spy\Reg::$cascade;
        }
    }
}
namespace WBS\Contributions\Config {
    final class Services
    {
        public static function causes(bool $shared = true)
        {
            return \Spy\Reg::$cause;
        }
    }
}
namespace WBS\Courses\Config {
    final class Services
    {
    }
}
namespace WBS\Events\Config {
    final class Services
    {
    }
}
namespace WBS\Gamification\Config {
    final class Services
    {
    }
}
namespace WBS\Integrations\Config {
    final class Services
    {
    }
}
namespace WBS\Journey\Config {
    final class Services
    {
        public static function journey(bool $shared = true)
        {
            return \Spy\Reg::$journey;
        }
    }
}
namespace WBS\Notifications\Config {
    final class Services
    {
    }
}
namespace WBS\Referrals\Config {
    final class Services
    {
        public static function contactBook(bool $shared = true)
        {
            return \Spy\Reg::$contact;
        }
    }
}

namespace WBS\Shared\Messaging {
    if (! function_exists(__NAMESPACE__ . '\\log_message')) {
        function log_message(string $level, string $message): bool
        {
            return true;
        }
    }
}

namespace {
    require_once dirname(__DIR__, 5) . '/app/Modules/Shared/Messaging/JobRouter.php';

    use Spy\Reg;
    use WBS\Shared\Messaging\JobRouter;

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $router = new JobRouter();
    $ORG = 'org-1';

    // ---- group.dissolved routes to the group cascade, reason = topic --------
    Reg::reset();
    $acked = $router->dispatch('group.dissolved', ['organization_id' => $ORG, 'data' => [
        'group_id' => 'g1', 'organization_id' => $ORG, 'to_status' => 'dissolved',
    ]]);
    $chk('dissolved: acked', $acked === true);
    $chk('dissolved: one group cascade call', count(Reg::$cascade->groupCalls) === 1);
    if (Reg::$cascade->groupCalls) {
        $c = Reg::$cascade->groupCalls[0];
        $chk('dissolved: cascade group = group_id', $c['group'] === 'g1');
        $chk('dissolved: cascade org threaded', $c['org'] === $ORG);
        $chk('dissolved: reason = topic', $c['reason'] === 'group.dissolved');
    }
    // J4-group — the dead group's journey context is archived.
    $chk('dissolved: one journey-group-archive call', count(Reg::$journey->groupArchived) === 1);
    if (Reg::$journey->groupArchived) {
        $chk('dissolved: journey archive group = group_id', Reg::$journey->groupArchived[0]['group'] === 'g1');
        $chk('dissolved: journey archive reason = topic', Reg::$journey->groupArchived[0]['reason'] === 'group.dissolved');
    }
    // C6 — the dead group's causes are reattributed; dissolve carries NO survivor.
    $chk('dissolved: one cause-reattribution call', count(Reg::$cause->reattributed) === 1);
    if (Reg::$cause->reattributed) {
        $r = Reg::$cause->reattributed[0];
        $chk('dissolved: reattribute group = group_id', $r['group'] === 'g1');
        $chk('dissolved: reattribute reason = topic', $r['reason'] === 'group.dissolved');
        $chk('dissolved: reattribute survivor is null', $r['survivor'] === null);
    }
    // G5 — the dead group's outreach invite sources are repaired (fail-closed).
    $chk('dissolved: one contact-invite-repair call', count(Reg::$contact->repaired) === 1);
    if (Reg::$contact->repaired) {
        $r = Reg::$contact->repaired[0];
        $chk('dissolved: contact repair group = group_id', $r['group'] === 'g1');
        $chk('dissolved: contact repair survivor is null', $r['survivor'] === null);
    }

    // ---- group.merged routes the FROM (loser) group, never the survivor -----
    Reg::reset();
    $router->dispatch('group.merged', ['organization_id' => $ORG, 'data' => [
        'from_group_id' => 'loser-g', 'into_group_id' => 'survivor-g', 'survivor_group_id' => 'survivor-g',
        'group_id' => 'loser-g', 'organization_id' => $ORG,
    ]]);
    $chk('merged: one group cascade call', count(Reg::$cascade->groupCalls) === 1);
    if (Reg::$cascade->groupCalls) {
        $c = Reg::$cascade->groupCalls[0];
        $chk('merged: cascade targets the FROM/loser group', $c['group'] === 'loser-g');
        $chk('merged: NOT the survivor group', $c['group'] !== 'survivor-g');
        $chk('merged: reason = group.merged', $c['reason'] === 'group.merged');
    }
    // J4-group — the loser group's journeys are archived (never re-pointed to survivor).
    $chk('merged: one journey-group-archive call', count(Reg::$journey->groupArchived) === 1);
    if (Reg::$journey->groupArchived) {
        $chk('merged: journey archive targets the FROM/loser group', Reg::$journey->groupArchived[0]['group'] === 'loser-g');
    }
    // C6 — the loser group's causes are reattributed to the SURVIVOR on merge.
    $chk('merged: one cause-reattribution call', count(Reg::$cause->reattributed) === 1);
    if (Reg::$cause->reattributed) {
        $r = Reg::$cause->reattributed[0];
        $chk('merged: reattribute targets the FROM/loser group', $r['group'] === 'loser-g');
        $chk('merged: reattribute carries the survivor', $r['survivor'] === 'survivor-g');
        $chk('merged: reattribute reason = group.merged', $r['reason'] === 'group.merged');
    }
    // G5 — contact invite sources re-point to the survivor on merge.
    $chk('merged: one contact-invite-repair call', count(Reg::$contact->repaired) === 1);
    if (Reg::$contact->repaired) {
        $r = Reg::$contact->repaired[0];
        $chk('merged: contact repair targets the FROM/loser group', $r['group'] === 'loser-g');
        $chk('merged: contact repair carries the survivor', $r['survivor'] === 'survivor-g');
    }

    // ---- group.archived is a reversible no-op (recognised, no cascade) ------
    Reg::reset();
    $acked = $router->dispatch('group.archived', ['organization_id' => $ORG, 'data' => [
        'group_id' => 'g1', 'organization_id' => $ORG, 'to_status' => 'archived',
    ]]);
    $chk('archived: acked', $acked === true);
    $chk('archived: NO cascade (reversible)', Reg::$cascade->groupCalls === []);
    $chk('archived: NO journey archive (reversible)', Reg::$journey->groupArchived === []);
    $chk('archived: NO cause reattribution (reversible)', Reg::$cause->reattributed === []);
    $chk('archived: NO contact invite repair (reversible)', Reg::$contact->repaired === []);

    // ---- fault isolation: a throwing consumer still acks the job -----------
    Reg::reset();
    Reg::$cascade->throw = true;
    $acked = $router->dispatch('group.dissolved', ['organization_id' => $ORG, 'data' => [
        'group_id' => 'g1', 'organization_id' => $ORG,
    ]]);
    $chk('throwing consumer still acks the job', $acked === true);
    $chk('throwing consumer was attempted', count(Reg::$cascade->groupCalls) === 1);

    // ---- guards: missing group / org short-circuit ------------------------
    Reg::reset();
    $acked = $router->dispatch('group.dissolved', ['organization_id' => $ORG, 'data' => ['to_status' => 'dissolved']]);
    $chk('missing group_id: acked', $acked === true);
    $chk('missing group_id: no cascade call', Reg::$cascade->groupCalls === []);

    Reg::reset();
    $acked = $router->dispatch('group.merged', ['organization_id' => '', 'data' => ['from_group_id' => '']]);
    $chk('empty merge payload: acked', $acked === true);
    $chk('empty merge payload: no cascade call', Reg::$cascade->groupCalls === []);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
