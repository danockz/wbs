<?php

declare(strict_types=1);

/**
 * JobRouter — account-teardown fan-out wiring (Theme B, AC3).
 *
 * Pins the contract that the Identity teardown events route to the ACL grant
 * cascade:
 *   - account.deactivated / suspended / anonymized -> grantCascade for the
 *     event's user_id, with the reason = the topic;
 *   - account.merged -> grantCascade for the LOSER's user id (never the survivor);
 *   - empty org / missing subject short-circuits (acked, no cascade call);
 *   - the job is acked (returns true) so it is not retried forever.
 *
 * JobRouter resolves collaborators through `final` module Services FACADES, so
 * this test registers FAKE facade classes under their exact FQCNs (the standalone
 * harness controls autoloading and never loads the real ones). A shared spy holds
 * the cascade calls the assertions read back.
 *
 *   php app/Modules/Shared/Messaging/tests/jobrouter_account_teardown_test.php
 */

namespace Spy {
    final class GrantCascade
    {
        /** @var list<array{org:string,subject:string,reason:string}> */
        public array $calls = [];
        public bool $throw = false;

        public function onAccountTornDown(string $org, string $subject, string $reason)
        {
            $this->calls[] = ['org' => $org, 'subject' => $subject, 'reason' => $reason];
            if ($this->throw) {
                throw new \RuntimeException('cascade boom');
            }

            return \WBS\Shared\Support\Result::ok();
        }
    }

    final class Commitments
    {
        /** @var list<array{org:string,subject:string,reason:string}> */
        public array $calls = [];

        public function cancelActiveForSubject(string $org, string $subject, string $reason): int
        {
            $this->calls[] = ['org' => $org, 'subject' => $subject, 'reason' => $reason];

            return 0;
        }
    }

    final class Journey
    {
        /** @var list<array{org:string,subject:string,reason:string}> */
        public array $paused = [];
        /** @var list<array{org:string,loser:string,survivor:string}> */
        public array $reassigned = [];

        /** @var list<array{org:string,subject:string}> */
        public array $resumed = [];

        public function pauseAllForSubject(string $org, string $subject, string $reason): int
        {
            $this->paused[] = ['org' => $org, 'subject' => $subject, 'reason' => $reason];

            return 0;
        }

        public function resumeAllForSubject(string $org, string $subject): int
        {
            $this->resumed[] = ['org' => $org, 'subject' => $subject];

            return 0;
        }

        public function reassignForMerge(string $org, string $loser, string $survivor): array
        {
            $this->reassigned[] = ['org' => $org, 'loser' => $loser, 'survivor' => $survivor];

            return ['repointed' => 0, 'archived_dupes' => 0];
        }
    }

    final class Notifications
    {
        /** @var list<array{org:string,subject:string,reason:string}> */
        public array $calls = [];

        public function onAccountTornDown(string $org, string $subject, string $reason): array
        {
            $this->calls[] = ['org' => $org, 'subject' => $subject, 'reason' => $reason];

            return ['cancelled' => 0, 'suppressed' => true];
        }
    }

    final class Sponsorships
    {
        /** @var list<array{org:string,subject:string,reason:string}> */
        public array $tornDown = [];
        /** @var list<array{org:string,loser:string,survivor:string}> */
        public array $reassigned = [];

        public function onAccountTornDown(string $org, string $subject, string $reason): int
        {
            $this->tornDown[] = ['org' => $org, 'subject' => $subject, 'reason' => $reason];

            return 0;
        }

        public function reassignForMerge(string $org, string $loser, string $survivor): array
        {
            $this->reassigned[] = ['org' => $org, 'loser' => $loser, 'survivor' => $survivor];

            return [
                'downline_repointed'  => 0,
                'downline_skipped'    => 0,
                'member_edges_closed' => 0,
                'links_repointed'     => 0,
            ];
        }
    }

    final class Enrollments
    {
        /** @var list<array{org:string,subject:string,reason:string}> */
        public array $withdrawn = [];
        /** @var list<array{org:string,loser:string,survivor:string}> */
        public array $reassigned = [];

        public function withdrawActiveForSubject(string $org, string $subject, string $reason): int
        {
            $this->withdrawn[] = ['org' => $org, 'subject' => $subject, 'reason' => $reason];

            return 0;
        }

        public function reassignForMerge(string $org, string $loser, string $survivor): array
        {
            $this->reassigned[] = ['org' => $org, 'loser' => $loser, 'survivor' => $survivor];

            return [
                'enrollments_repointed' => 0,
                'duplicates_withdrawn'  => 0,
                'completions_repointed' => 0,
            ];
        }
    }

    final class Registrations
    {
        /** @var list<array{org:string,subject:string,reason:string}> */
        public array $released = [];
        /** @var list<array{org:string,loser:string,survivor:string}> */
        public array $reassigned = [];

        public function releaseActiveForSubject(string $org, string $subject, string $reason)
        {
            $this->released[] = ['org' => $org, 'subject' => $subject, 'reason' => $reason];

            return \WBS\Shared\Support\Result::ok();
        }

        public function reassignForMerge(string $org, string $loser, string $survivor): array
        {
            $this->reassigned[] = ['org' => $org, 'loser' => $loser, 'survivor' => $survivor];

            return [
                'registrations_repointed'  => 0,
                'registrations_superseded' => 0,
                'attendance_repointed'     => 0,
                'attendance_voided'        => 0,
                'holds_repointed'          => 0,
                'holds_released'           => 0,
                'waitlist_repointed'       => 0,
                'waitlist_expired'         => 0,
            ];
        }
    }

    final class Memberships
    {
        /** @var list<array{org:string,subject:string,reason:string}> */
        public array $ended = [];
        /** @var list<array{org:string,loser:string,survivor:string}> */
        public array $reassigned = [];

        /** @var list<array{org:string,subject:string}> */
        public array $restored = [];

        public function endActiveForSubject(string $org, string $subject, string $reason): int
        {
            $this->ended[] = ['org' => $org, 'subject' => $subject, 'reason' => $reason];

            return 0;
        }

        public function restoreForSubject(string $org, string $subject): int
        {
            $this->restored[] = ['org' => $org, 'subject' => $subject];

            return 0;
        }

        public function reassignForMerge(string $org, string $loser, string $survivor): array
        {
            $this->reassigned[] = ['org' => $org, 'loser' => $loser, 'survivor' => $survivor];

            return ['repointed' => 0, 'superseded' => 0, 'history_repointed' => 0];
        }
    }

    final class Reg
    {
        public static GrantCascade $cascade;
        public static Commitments $commitments;
        public static Journey $journey;
        public static Notifications $notifications;
        public static Sponsorships $sponsorships;
        public static Enrollments $enrollments;
        public static Registrations $registrations;
        public static Memberships $memberships;

        public static function reset(): void
        {
            self::$cascade = new GrantCascade();
            self::$commitments = new Commitments();
            self::$journey = new Journey();
            self::$notifications = new Notifications();
            self::$sponsorships = new Sponsorships();
            self::$enrollments = new Enrollments();
            self::$registrations = new Registrations();
            self::$memberships = new Memberships();
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
        public static function commitments(bool $shared = true)
        {
            return \Spy\Reg::$commitments;
        }
    }
}
namespace WBS\Courses\Config {
    final class Services
    {
        public static function enrollments(bool $shared = true)
        {
            return \Spy\Reg::$enrollments;
        }
    }
}
namespace WBS\Events\Config {
    final class Services
    {
        public static function eventRegistrations(bool $shared = true)
        {
            return \Spy\Reg::$registrations;
        }
    }
}
namespace WBS\Gamification\Config {
    final class Services
    {
    }
}
namespace WBS\Groups\Config {
    final class Services
    {
        public static function memberships(bool $shared = true)
        {
            return \Spy\Reg::$memberships;
        }
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
        public static function notifications(bool $shared = true)
        {
            return \Spy\Reg::$notifications;
        }
    }
}

namespace WBS\Referrals\Config {
    final class Services
    {
        public static function sponsorships(bool $shared = true)
        {
            return \Spy\Reg::$sponsorships;
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

    // ---- each teardown topic routes to the cascade with reason = topic -------
    foreach (['account.deactivated', 'account.suspended', 'account.anonymized'] as $topic) {
        Reg::reset();
        $acked = $router->dispatch($topic, ['organization_id' => $ORG, 'data' => [
            'user_id' => 'u1', 'organization_id' => $ORG, 'to_status' => 'x',
        ]]);
        $chk("{$topic}: acked", $acked === true);
        $chk("{$topic}: one cascade call", count(Reg::$cascade->calls) === 1, (string) count(Reg::$cascade->calls));
        if (Reg::$cascade->calls) {
            $c = Reg::$cascade->calls[0];
            $chk("{$topic}: cascade subject = user_id", $c['subject'] === 'u1');
            $chk("{$topic}: cascade org threaded", $c['org'] === $ORG);
            $chk("{$topic}: reason = topic", $c['reason'] === $topic);
        }
        // C8 — commitments consumer is also fanned out.
        $chk("{$topic}: one commitment-cancel call", count(Reg::$commitments->calls) === 1);
        if (Reg::$commitments->calls) {
            $cc = Reg::$commitments->calls[0];
            $chk("{$topic}: commitment subject = user_id", $cc['subject'] === 'u1');
            $chk("{$topic}: commitment reason = topic", $cc['reason'] === $topic);
        }
        // J4 — journeys are PAUSED on a non-merge teardown, never reassigned.
        $chk("{$topic}: one journey-pause call", count(Reg::$journey->paused) === 1);
        $chk("{$topic}: no journey-reassign on non-merge", Reg::$journey->reassigned === []);
        if (Reg::$journey->paused) {
            $chk("{$topic}: journey pause subject = user_id", Reg::$journey->paused[0]['subject'] === 'u1');
        }
        // N7 — notification teardown consumer is fanned out too.
        $chk("{$topic}: one notification-teardown call", count(Reg::$notifications->calls) === 1);
        if (Reg::$notifications->calls) {
            $chk("{$topic}: notification subject = user_id", Reg::$notifications->calls[0]['subject'] === 'u1');
        }
        // R7 — the subject's referral links are disabled on a non-merge teardown,
        // never reassigned.
        $chk("{$topic}: one sponsorship-teardown call", count(Reg::$sponsorships->tornDown) === 1);
        $chk("{$topic}: no sponsorship-reassign on non-merge", Reg::$sponsorships->reassigned === []);
        if (Reg::$sponsorships->tornDown) {
            $chk("{$topic}: sponsorship subject = user_id", Reg::$sponsorships->tornDown[0]['subject'] === 'u1');
            $chk("{$topic}: sponsorship reason = topic", Reg::$sponsorships->tornDown[0]['reason'] === $topic);
        }
        // CO6 — the subject's in-flight enrollments are withdrawn on a non-merge
        // teardown, never reassigned.
        $chk("{$topic}: one enrollment-teardown call", count(Reg::$enrollments->withdrawn) === 1);
        $chk("{$topic}: no enrollment-reassign on non-merge", Reg::$enrollments->reassigned === []);
        if (Reg::$enrollments->withdrawn) {
            $chk("{$topic}: enrollment subject = user_id", Reg::$enrollments->withdrawn[0]['subject'] === 'u1');
            $chk("{$topic}: enrollment reason = topic", Reg::$enrollments->withdrawn[0]['reason'] === $topic);
        }

        // E-B2 — the subject's future event footprint is released on a non-merge
        // teardown, never reassigned.
        $chk("{$topic}: one registration-teardown call", count(Reg::$registrations->released) === 1);
        $chk("{$topic}: no registration-reassign on non-merge", Reg::$registrations->reassigned === []);
        if (Reg::$registrations->released) {
            $chk("{$topic}: registration subject = user_id", Reg::$registrations->released[0]['subject'] === 'u1');
            $chk("{$topic}: registration reason = topic", Reg::$registrations->released[0]['reason'] === $topic);
        }

        // M1 (belonging half) — the subject's ACTIVE group memberships are ended
        // on a non-merge teardown, never reassigned.
        $chk("{$topic}: one membership-teardown call", count(Reg::$memberships->ended) === 1);
        $chk("{$topic}: no membership-reassign on non-merge", Reg::$memberships->reassigned === []);
        if (Reg::$memberships->ended) {
            $chk("{$topic}: membership subject = user_id", Reg::$memberships->ended[0]['subject'] === 'u1');
            $chk("{$topic}: membership reason = topic", Reg::$memberships->ended[0]['reason'] === $topic);
        }
    }

    // ---- merge routes the LOSER (not survivor) ------------------------------
    Reg::reset();
    $router->dispatch('account.merged', ['organization_id' => $ORG, 'data' => [
        'loser_user_id' => 'loser-1', 'survivor_user_id' => 'survivor-1', 'organization_id' => $ORG,
    ]]);
    $chk('merge: one cascade call', count(Reg::$cascade->calls) === 1);
    if (Reg::$cascade->calls) {
        $c = Reg::$cascade->calls[0];
        $chk('merge: cascade targets the LOSER', $c['subject'] === 'loser-1');
        $chk('merge: NOT the survivor', $c['subject'] !== 'survivor-1');
        $chk('merge: reason = account.merged', $c['reason'] === 'account.merged');
    }
    $chk('merge: commitment-cancel targets the LOSER', count(Reg::$commitments->calls) === 1
        && Reg::$commitments->calls[0]['subject'] === 'loser-1');
    // J4 merge half: journeys are RE-POINTED to the survivor, NOT paused.
    $chk('merge: one journey-reassign call', count(Reg::$journey->reassigned) === 1);
    $chk('merge: journeys NOT paused on merge', Reg::$journey->paused === []);
    if (Reg::$journey->reassigned) {
        $rj = Reg::$journey->reassigned[0];
        $chk('merge: reassign loser -> survivor', $rj['loser'] === 'loser-1' && $rj['survivor'] === 'survivor-1');
    }
    $chk('merge: notification-teardown targets the LOSER', count(Reg::$notifications->calls) === 1
        && Reg::$notifications->calls[0]['subject'] === 'loser-1');
    // R7 merge half: sponsorship graph is RE-POINTED to the survivor, NOT disabled.
    $chk('merge: one sponsorship-reassign call', count(Reg::$sponsorships->reassigned) === 1);
    $chk('merge: sponsorship links NOT disabled on merge', Reg::$sponsorships->tornDown === []);
    if (Reg::$sponsorships->reassigned) {
        $rs = Reg::$sponsorships->reassigned[0];
        $chk('merge: sponsorship reassign loser -> survivor', $rs['loser'] === 'loser-1' && $rs['survivor'] === 'survivor-1');
    }
    // CO6 merge half: enrollments/completions RE-POINTED to the survivor, NOT withdrawn.
    $chk('merge: one enrollment-reassign call', count(Reg::$enrollments->reassigned) === 1);
    $chk('merge: enrollments NOT withdrawn on merge', Reg::$enrollments->withdrawn === []);
    if (Reg::$enrollments->reassigned) {
        $re = Reg::$enrollments->reassigned[0];
        $chk('merge: enrollment reassign loser -> survivor', $re['loser'] === 'loser-1' && $re['survivor'] === 'survivor-1');
    }
    // E-B2 merge half: event footprint RE-POINTED to the survivor, NOT released.
    $chk('merge: one registration-reassign call', count(Reg::$registrations->reassigned) === 1);
    $chk('merge: registrations NOT released on merge', Reg::$registrations->released === []);
    if (Reg::$registrations->reassigned) {
        $rr = Reg::$registrations->reassigned[0];
        $chk('merge: registration reassign loser -> survivor', $rr['loser'] === 'loser-1' && $rr['survivor'] === 'survivor-1');
    }
    // M2 merge half: group memberships RE-POINTED to the survivor, NOT ended.
    $chk('merge: one membership-reassign call', count(Reg::$memberships->reassigned) === 1);
    $chk('merge: memberships NOT ended on merge', Reg::$memberships->ended === []);
    if (Reg::$memberships->reassigned) {
        $rm = Reg::$memberships->reassigned[0];
        $chk('merge: membership reassign loser -> survivor', $rm['loser'] === 'loser-1' && $rm['survivor'] === 'survivor-1');
    }

    // ---- M10: reactivation is the INVERSE of teardown -----------------------
    Reg::reset();
    $acked = $router->dispatch('account.reactivated', ['organization_id' => $ORG, 'data' => [
        'user_id' => 'u1', 'organization_id' => $ORG, 'from_status' => 'suspended', 'to_status' => 'active',
    ]]);
    $chk('reactivate: acked', $acked === true);
    // journeys are RESUMED, never paused/reassigned.
    $chk('reactivate: one journey-resume call', count(Reg::$journey->resumed) === 1);
    $chk('reactivate: journey NOT paused on reactivate', Reg::$journey->paused === []);
    if (Reg::$journey->resumed) {
        $chk('reactivate: journey resume subject = user_id', Reg::$journey->resumed[0]['subject'] === 'u1');
        $chk('reactivate: journey resume org threaded', Reg::$journey->resumed[0]['org'] === $ORG);
    }
    // memberships are RESTORED, never ended/reassigned.
    $chk('reactivate: one membership-restore call', count(Reg::$memberships->restored) === 1);
    $chk('reactivate: membership NOT ended on reactivate', Reg::$memberships->ended === []);
    if (Reg::$memberships->restored) {
        $chk('reactivate: membership restore subject = user_id', Reg::$memberships->restored[0]['subject'] === 'u1');
    }
    // reactivation does NOT touch teardown-only consumers (grants/commitments/etc.)
    $chk('reactivate: no grant cascade', Reg::$cascade->calls === []);
    $chk('reactivate: no commitment cancel', Reg::$commitments->calls === []);
    $chk('reactivate: no membership teardown', Reg::$memberships->ended === []);

    // missing subject short-circuits (acked, no restore call).
    Reg::reset();
    $acked = $router->dispatch('account.reactivated', ['organization_id' => $ORG, 'data' => ['to_status' => 'active']]);
    $chk('reactivate: missing user_id acked', $acked === true);
    $chk('reactivate: missing user_id no resume call', Reg::$journey->resumed === []);
    $chk('reactivate: missing user_id no restore call', Reg::$memberships->restored === []);

    // ---- fault isolation: a throwing consumer does not block the others ------
    Reg::reset();
    Reg::$cascade->throw = true;
    $acked = $router->dispatch('account.deactivated', ['organization_id' => $ORG, 'data' => [
        'user_id' => 'u1', 'organization_id' => $ORG,
    ]]);
    $chk('throwing consumer still acks the job', $acked === true);
    $chk('throwing consumer was attempted', count(Reg::$cascade->calls) === 1);
    $chk('later consumer still runs after an earlier throw', count(Reg::$commitments->calls) === 1);

    // ---- guards: missing subject / org short-circuit (acked, no call) -------
    Reg::reset();
    $acked = $router->dispatch('account.deactivated', ['organization_id' => $ORG, 'data' => ['to_status' => 'x']]);
    $chk('missing user_id: acked', $acked === true);
    $chk('missing user_id: no cascade call', Reg::$cascade->calls === []);

    Reg::reset();
    $acked = $router->dispatch('account.merged', ['organization_id' => '', 'data' => ['loser_user_id' => '']]);
    $chk('empty merge payload: acked', $acked === true);
    $chk('empty merge payload: no cascade call', Reg::$cascade->calls === []);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
