<?php

declare(strict_types=1);

/**
 * JobRouter — Option-C journey-signal emission wiring.
 *
 * The async outbox relay turns domain events into queue jobs; JobRouter routes
 * them to the right coordinator AND (this is what we pin here) emits the
 * matching membership-journey SIGNAL so a completed course or a verified gift can
 * auto-advance the giver/learner's journey when a leader's membership rule says
 * so. This test locks the wiring contract:
 *
 *   - topic 'course.completed'       -> journey.signal.course.completed
 *   - topic 'contribution.succeeded' -> journey.signal.contribution.verified
 *   - the signal is fault-isolated: a throwing emitter never fails the job, and
 *     the primary coordinator (reward/points) still runs;
 *   - empty user_id / org short-circuits the signal (no spurious ingest);
 *   - a course with no points rule still emits its journey signal;
 *   - REGRESSION: the contribution signal is scoped to the CAUSE'S OWNING GROUP.
 *     The staged contribution payload does NOT carry the cause's group, so
 *     JobRouter resolves it from the cause (scope_group_id = cause.group_id);
 *     previously it read a never-present $data['group_id'] and every cause-scoped
 *     leader rule was silently un-fireable.
 *
 * JobRouter resolves collaborators through `final` module Services FACADES and
 * has no constructor seam, so this test registers FAKE facade classes under
 * their exact FQCNs and never loads the real ones (the standalone harness
 * controls autoloading). Each fake returns a spy the assertions read back.
 *
 *   php app/Modules/Shared/Messaging/tests/jobrouter_journey_signal_test.php
 */

namespace WBS\Shared\Support {
    // Minimal Result stand-in so spy coordinators can return something ok-ish.
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

namespace Spy {
    /** Records journey signals ingested. */
    final class JourneySignals
    {
        /** @var list<array{org:string,payload:array}> */
        public array $calls = [];

        public bool $throw = false;

        public function ingest(string $orgId, array $payload)
        {
            $this->calls[] = ['org' => $orgId, 'payload' => $payload];
            if ($this->throw) {
                throw new \RuntimeException('signal boom');
            }

            return \WBS\Shared\Support\Result::ok();
        }
    }

    /** Records reward/points calls. */
    final class RewardCoordinator
    {
        public array $succeeded = [];

        public function onSucceeded(array $event)
        {
            $this->succeeded[] = $event;

            return \WBS\Shared\Support\Result::ok();
        }

        public function onRefunded(array $event)
        {
            return \WBS\Shared\Support\Result::ok();
        }
    }

    final class PointsEngine
    {
        public array $awards = [];

        public function award(string $org, string $rule, string $subject, string $ref, array $opts = [])
        {
            $this->awards[] = compact('org', 'rule', 'subject', 'ref', 'opts');

            return \WBS\Shared\Support\Result::ok();
        }
    }

    final class Causes
    {
        /** @var array<string,array<string,mixed>> causeId => row */
        public array $rows = [];

        public function find(string $causeId): ?array
        {
            return $this->rows[$causeId] ?? null;
        }
    }

    /** Shared holder so fakes and assertions see the same spy instances. */
    final class Reg
    {
        public static JourneySignals $signals;
        public static RewardCoordinator $reward;
        public static PointsEngine $points;
        public static Causes $causes;

        public static function reset(): void
        {
            self::$signals = new JourneySignals();
            self::$reward  = new RewardCoordinator();
            self::$points  = new PointsEngine();
            self::$causes  = new Causes();
        }
    }
}

// ---- Fake module Services facades (exact FQCNs; real files never loaded) -----
namespace WBS\Contributions\Config {
    final class Services
    {
        public static function rewardCoordinator(bool $shared = true)
        {
            return \Spy\Reg::$reward;
        }

        public static function causes(bool $shared = true)
        {
            return \Spy\Reg::$causes;
        }
    }
}

namespace WBS\Gamification\Config {
    final class Services
    {
        public static function pointsEngine(bool $shared = true)
        {
            return \Spy\Reg::$points;
        }
    }
}

namespace WBS\Journey\Config {
    final class Services
    {
        public static function journeySignals(bool $shared = true)
        {
            return \Spy\Reg::$signals;
        }
    }
}

// Facades JobRouter references in `use` but this test's topics don't exercise.
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
namespace WBS\Integrations\Config {
    final class Services
    {
    }
}
namespace WBS\Notifications\Config {
    final class Services
    {
    }
}

namespace WBS\Shared\Messaging {
    // CI4 global used by JobRouter::unknown(); stub it in the router's namespace
    // so the unqualified call resolves here instead of hitting the framework.
    if (! function_exists(__NAMESPACE__ . '\\log_message')) {
        function log_message(string $level, string $message): bool
        {
            return true;
        }
    }
}

namespace {
    // Load ONLY JobRouter (its `use` targets are all satisfied by the fakes above).
    require_once dirname(__DIR__, 5) . '/app/Modules/Shared/Messaging/JobRouter.php';

    use Spy\Reg;
    use WBS\Shared\Messaging\JobRouter;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    $router = new JobRouter();
    $ORG    = 'org1';

    // ---- course.completed emits journey.signal.course.completed -----------
    echo "course.completed -> course journey signal\n";
    Reg::reset();
    $router->dispatch('course.completed', ['organization_id' => $ORG, 'data' => [
        'points_rule_code' => 'course.completed', 'user_id' => 'u1',
        'source_ref' => 'course_completion:e1', 'group_id' => 'g_course',
        'course_id' => 'c1', 'course_code' => 'FND101',
    ]]);
    chk('points awarded', count(Reg::$points->awards) === 1);
    chk('one journey signal', count(Reg::$signals->calls) === 1, (string) count(Reg::$signals->calls));
    if (Reg::$signals->calls) {
        $p = Reg::$signals->calls[0]['payload'];
        chk('course action', ($p['action'] ?? null) === 'journey.signal.course.completed', (string) ($p['action'] ?? ''));
        chk('course scope = course group', ($p['scope_group_id'] ?? null) === 'g_course');
        chk('course user forwarded', ($p['user_id'] ?? null) === 'u1');
        chk('course evidence_ref = source_ref', ($p['evidence_ref'] ?? null) === 'course_completion:e1');
        chk('course_code in attributes', ($p['attributes']['course_code'] ?? null) === 'FND101');
    }

    // ---- course with no points rule still signals -------------------------
    echo "course.completed w/o points rule still signals\n";
    Reg::reset();
    $router->dispatch('course.completed', ['organization_id' => $ORG, 'data' => [
        'user_id' => 'u2', 'source_ref' => 'course_completion:e2', 'course_id' => 'c1',
    ]]);
    chk('no award (no rule)', Reg::$points->awards === []);
    chk('still one journey signal', count(Reg::$signals->calls) === 1);

    // ---- contribution.succeeded -> verified signal, scoped to CAUSE group --
    echo "contribution.succeeded -> contribution journey signal (cause-group scoped)\n";
    Reg::reset();
    Reg::$causes->rows['cause9'] = ['id' => 'cause9', 'group_id' => 'g_cause', 'organization_id' => 'org1'];
    $router->dispatch('contribution.succeeded', ['organization_id' => $ORG, 'data' => [
        'contribution_id' => 'con1', 'cause_id' => 'cause9', 'user_id' => 'giver1',
        'amount_minor' => 5000, 'source_ref' => 'contribution:con1',
    ]]);
    chk('reward coordinator ran', count(Reg::$reward->succeeded) === 1);
    chk('one journey signal', count(Reg::$signals->calls) === 1);
    if (Reg::$signals->calls) {
        $p = Reg::$signals->calls[0]['payload'];
        chk('contribution action', ($p['action'] ?? null) === 'journey.signal.contribution.verified');
        chk('REGRESSION: scope = resolved cause group', ($p['scope_group_id'] ?? null) === 'g_cause',
            'got ' . json_encode($p['scope_group_id'] ?? null));
        chk('project_code = cause id', ($p['project_code'] ?? null) === 'cause9');
        chk('giver forwarded', ($p['user_id'] ?? null) === 'giver1');
        chk('amount in attributes', ($p['attributes']['amount_minor'] ?? null) === 5000);
    }

    // ---- cause with no owning group -> org-wide scope (empty) --------------
    echo "cause with no group -> org-wide scope\n";
    Reg::reset();
    Reg::$causes->rows['cause0'] = ['id' => 'cause0', 'group_id' => null, 'organization_id' => 'org1'];
    $router->dispatch('contribution.succeeded', ['organization_id' => $ORG, 'data' => [
        'cause_id' => 'cause0', 'user_id' => 'giver2', 'source_ref' => 'contribution:con2',
    ]]);
    chk('scope empty (org-wide fallback)', (Reg::$signals->calls[0]['payload']['scope_group_id'] ?? null) === '');

    // ---- fault isolation: throwing signal never breaks the job -------------
    echo "signal fault isolation\n";
    Reg::reset();
    Reg::$signals->throw = true;
    Reg::$causes->rows['cause9'] = ['id' => 'cause9', 'group_id' => 'g_cause'];
    $threw = false;
    $ret = null;
    try {
        $ret = $router->dispatch('contribution.succeeded', ['organization_id' => $ORG, 'data' => [
            'cause_id' => 'cause9', 'user_id' => 'giver1', 'source_ref' => 'contribution:con1',
        ]]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    chk('job did not throw despite signal failure', ! $threw);
    chk('job acked (true)', $ret === true);
    chk('reward still ran', count(Reg::$reward->succeeded) === 1);

    // ---- empty user short-circuits the signal -----------------------------
    echo "empty user short-circuits signal\n";
    Reg::reset();
    $router->dispatch('course.completed', ['organization_id' => $ORG, 'data' => [
        'points_rule_code' => 'course.completed', 'user_id' => '', 'source_ref' => 'x',
    ]]);
    chk('no signal for empty user', Reg::$signals->calls === []);

    // ---- refund does NOT emit a journey signal ----------------------------
    echo "refund emits no journey signal\n";
    Reg::reset();
    $router->dispatch('contribution.refunded', ['organization_id' => $ORG, 'data' => ['source_ref' => 'contribution:con1']]);
    chk('no signal on refund', Reg::$signals->calls === []);

    // ---- unknown topic acked, no signal -----------------------------------
    echo "unknown topic\n";
    Reg::reset();
    $r = $router->dispatch('nonsense.topic', ['organization_id' => $ORG, 'data' => []]);
    chk('unknown acked true', $r === true && Reg::$signals->calls === []);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
