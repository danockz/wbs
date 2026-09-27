<?php

declare(strict_types=1);

/**
 * Stream-giving live-only gate test (Phase 0: gap ST1).
 *
 * Giving is a money path (it delegates to the Contributions ledger). ST1 requires
 * give() to accept a contribution ONLY while the stream is actually `live`, so a
 * lingering widget on a draft/ended stream can't capture money outside the window.
 *
 * This test drives give() against draft / ended / missing / live streams and
 * asserts the gate fires BEFORE any contribution intent is created. A recording
 * fake ContributionService proves no intent is created on rejection, and one IS
 * created when live.
 *
 *   php app/Modules/Streaming/Services/tests/stream_giving_live_gate_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows)
        {
        }

        public function getRowArray()
        {
            return $this->rows[0] ?? null;
        }

        public function getResultArray()
        {
            return $this->rows;
        }
    }

    class QB
    {
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function get(): RS
        {
            return new RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
        }

        public function countAllResults(): int
        {
            return count(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace WBS\Contributions\Services {
    use WBS\Shared\Support\Result;

    class ContributionService
    {
        public int $intents = 0;

        public function createIntent(string $organizationId, string $causeId, array $data): Result
        {
            $this->intents++;

            return Result::created(['intent_id' => 'intent-' . $this->intents]);
        }
    }

    class CauseService
    {
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Contributions\Services\CauseService;
    use WBS\Contributions\Services\ContributionService;
    use WBS\Shared\Support\Clock;
    use WBS\Streaming\Services\StreamGivingService;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Streaming/Services/StreamGivingService.php';

    $passed = 0;
    $failed = 0;
    function chk(string $label, bool $cond): void
    {
        global $passed, $failed;
        if ($cond) {
            $passed++;
        } else {
            $failed++;
            echo "  FAIL {$label}\n";
        }
    }

    $ORG = 'org-1';

    $mk = static function (?string $streamStatus) use ($ORG): array {
        $db = new BaseConnection();
        $db->rows['stream_giving_configs'] = [[
            'id' => 'cfg-1', 'organization_id' => $ORG, 'stream_id' => 's-1',
            'enabled' => 1, 'widget_enabled' => 1, 'cause_id' => 'cause-1',
            'currency' => 'USD', 'min_amount_minor' => null, 'max_amount_minor' => null,
            'allow_anonymous' => 1, 'ack_enabled' => 0, 'ack_show_amount_allowed' => 0,
        ]];
        $db->rows['streams'] = $streamStatus === null ? [] : [[
            'id' => 's-1', 'organization_id' => $ORG, 'status' => $streamStatus,
        ]];
        $db->rows['stream_giving_intents'] = [];
        $contrib = new ContributionService();
        $svc = new StreamGivingService($db, new Clock(), $contrib, new CauseService());

        return [$svc, $contrib];
    };

    $give = ['amount_minor' => 5000, 'currency' => 'USD', 'user_id' => 'u-1'];

    // draft -> rejected, no intent
    [$svc, $contrib] = $mk('draft');
    $r = $svc->give($ORG, 's-1', $give);
    chk('draft stream rejected', ! $r->ok && $r->code === 'STREAM_NOT_LIVE');
    chk('draft creates no intent', $contrib->intents === 0);

    // ended -> rejected
    [$svc, $contrib] = $mk('ended');
    $r = $svc->give($ORG, 's-1', $give);
    chk('ended stream rejected', ! $r->ok && $r->code === 'STREAM_NOT_LIVE');
    chk('ended creates no intent', $contrib->intents === 0);

    // missing stream -> not found
    [$svc, $contrib] = $mk(null);
    $r = $svc->give($ORG, 's-1', $give);
    chk('missing stream not found', ! $r->ok && $r->code === 'STREAM_NOT_FOUND');
    chk('missing creates no intent', $contrib->intents === 0);

    // live -> accepted, intent created
    [$svc, $contrib] = $mk('live');
    $r = $svc->give($ORG, 's-1', $give);
    chk('live stream accepted', $r->ok);
    chk('live creates one intent', $contrib->intents === 1);

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
