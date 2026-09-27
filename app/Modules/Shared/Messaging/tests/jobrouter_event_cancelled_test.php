<?php

declare(strict_types=1);

/**
 * JobRouter event.cancelled dispatch test (Theme B — E-B1 emits, MT5 consumes).
 *
 * Proves the router:
 *   - routes `event.cancelled` to Meetings' onEventCancelled with org + event_id
 *     and reason 'event.cancelled';
 *   - fault-isolates the consumer (a throw still acks the job);
 *   - short-circuits (acks, no call) when org or event_id is missing.
 *
 *   php app/Modules/Shared/Messaging/tests/jobrouter_event_cancelled_test.php
 */

namespace Spy {
    final class Meetings
    {
        /** @var list<array{org:string,event:string,reason:string}> */
        public array $calls = [];
        public bool $throw = false;

        public function onEventCancelled(string $org, string $event, string $reason)
        {
            $this->calls[] = ['org' => $org, 'event' => $event, 'reason' => $reason];
            if ($this->throw) {
                throw new \RuntimeException('meeting cancel boom');
            }

            return \WBS\Shared\Support\Result::ok();
        }
    }

    final class Reg
    {
        public static Meetings $meetings;

        public static function reset(): void
        {
            self::$meetings = new Meetings();
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
    }
}
namespace WBS\Contributions\Config {
    final class Services
    {
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
    }
}
namespace WBS\Meetings\Config {
    final class Services
    {
        public static function meetings(bool $shared = true)
        {
            return \Spy\Reg::$meetings;
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
    $chk  = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG    = 'org-1';
    $router = new JobRouter();

    // ---- routes to Meetings MT5 --------------------------------------------
    Reg::reset();
    $acked = $router->dispatch('event.cancelled', ['organization_id' => $ORG, 'data' => [
        'event_id' => 'ev-1', 'organization_id' => $ORG, 'status' => 'cancelled',
    ]]);
    $chk('acked', $acked === true);
    $chk('one meeting-event-cancel call', count(Reg::$meetings->calls) === 1);
    if (Reg::$meetings->calls) {
        $c = Reg::$meetings->calls[0];
        $chk('meeting call event = event_id', $c['event'] === 'ev-1');
        $chk('meeting call org threaded', $c['org'] === $ORG);
        $chk('meeting call reason = event.cancelled', $c['reason'] === 'event.cancelled');
    }

    // ---- fault isolation ----------------------------------------------------
    Reg::reset();
    Reg::$meetings->throw = true;
    $acked = $router->dispatch('event.cancelled', ['organization_id' => $ORG, 'data' => [
        'event_id' => 'ev-1', 'organization_id' => $ORG,
    ]]);
    $chk('throwing consumer still acks', $acked === true);
    $chk('throwing consumer was attempted', count(Reg::$meetings->calls) === 1);

    // ---- guards -------------------------------------------------------------
    Reg::reset();
    $chk('missing event_id: acked', $router->dispatch('event.cancelled', ['organization_id' => $ORG, 'data' => []]) === true);
    $chk('missing event_id: no call', Reg::$meetings->calls === []);
    Reg::reset();
    $chk('missing org: acked', $router->dispatch('event.cancelled', ['data' => ['event_id' => 'ev-1']]) === true);
    $chk('missing org: no call', Reg::$meetings->calls === []);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
