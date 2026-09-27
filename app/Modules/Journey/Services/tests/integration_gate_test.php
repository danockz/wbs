<?php

declare(strict_types=1);

/**
 * Integration gate test (FR-REF-3b) — the Journey side of the seam.
 *
 * JourneyService::transition() is the single choke point every advance funnels
 * through (auto-apply rules, proposal approval, manual moves). This test pins
 * that when an IntegrationGatePort is wired it is consulted BEFORE any write,
 * with the target stage and the journey's context group; a non-null verdict
 * refuses the move (INTEGRATION_REQUIRED) without touching member_journeys; a
 * null verdict (or no gate at all) leaves the transition exactly as before.
 *
 *   php app/Modules/Journey/Services/tests/integration_gate_test.php
 */

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Journey/Services/IntegrationGatePort.php';
require_once $root . '/app/Modules/Journey/Services/JourneyService.php';

}

// ---- Fakes ----------------------------------------------------------------
namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public bool $failTx = false;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function transStart(): void {}
        public function transComplete(): void {}
        public function transStatus(): bool { return ! $this->failTx; }
    }
}

namespace Fake {
    class QB
    {
        private array $wheres = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        private function key(string $k): string
        {
            $k = trim(explode(' ', trim($k))[0]);
            $p = explode('.', $k);

            return end($p);
        }

        public function where($k, $v = null)
        {
            $this->wheres[] = [$this->key((string) $k), $v, 'eq'];

            return $this;
        }

        public function whereIn($k, array $v)
        {
            $this->wheres[] = [$this->key((string) $k), $v, 'in'];

            return $this;
        }

        public function select($s) { return $this; }
        public function groupBy($g) { return $this; }
        public function orderBy($k, $dir = 'ASC') { return $this; }
        public function limit($n, $o = 0) { return $this; }

        public function get($limit = null, $offset = 0): \Fake\RS
        {
            return new \Fake\RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
        }

        public function getRowArray()
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));

            return $rows[0] ?? null;
        }

        public function getResultArray()
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function countAllResults(bool $reset = true): int
        {
            return count(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function insert(array $set, bool $escape = true): bool
        {
            $this->db->rows[$this->t][] = $set;

            return true;
        }

        public function update(?array $set = null, $where = null, $limit = null): bool
        {
            foreach ($this->db->rows[$this->t] ?? [] as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, (array) $set);
                }
            }

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->wheres as [$c, $v, $type]) {
                $actual = $r[$c] ?? null;
                if ($type === 'in') {
                    if (! in_array($actual, $v, true)) { return false; }
                } elseif ($actual !== $v) {
                    return false;
                }
            }

            return true;
        }
    }

    class RS
    {
        public function __construct(private array $rows) {}
        public function getRowArray() { return $this->rows[0] ?? null; }
        public function getResultArray() { return $this->rows; }
    }
}

namespace WBS\Journey\Services {
    /** Spy gate: records every consult and returns a controllable verdict. */
    class SpyGate implements IntegrationGatePort
    {
        /** @var list<array{org:string,user:string,stage:string,group:?string}> */
        public array $calls = [];

        /** @var array<string,mixed>|null */
        public ?array $verdict = null;

        public function gate(string $organizationId, string $userId, string $toStageCode, ?string $groupId): ?array
        {
            $this->calls[] = ['org' => $organizationId, 'user' => $userId, 'stage' => $toStageCode, 'group' => $groupId];

            return $this->verdict;
        }
    }
}

namespace {

use WBS\Journey\Services\JourneyService;
use WBS\Journey\Services\SpyGate;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$ORG   = 'org1';
$clock = new Clock();

$world = static function () use ($clock): \CodeIgniter\Database\BaseConnection {
    $db  = new \CodeIgniter\Database\BaseConnection();
    $mk  = static fn (string $code, string $phase, int $order): array => [
        'id' => "st_$code", 'organization_id' => 'org1', 'group_id' => null, 'code' => $code,
        'name' => ucfirst($code), 'phase' => $phase, 'sort_order' => $order, 'is_entry' => 0,
        'is_terminal' => 0, 'status' => 'active',
    ];
    $db->rows['journey_stages'] = [
        $mk('prospect', 'win', 10),
        $mk('first_timer', 'win', 20),
        $mk('new_believer', 'win', 30),
        $mk('in_foundation', 'build', 40),
        $mk('established', 'build', 50),
    ];
    $db->rows['member_journeys']            = [];
    $db->rows['member_journey_transitions'] = [];

    return $db;
};

// ---- 1. Blocking gate: refused BEFORE any write -------------------------------
echo "a blocking gate refuses the move before anything is written\n";
$db    = $world();
$gate  = new SpyGate();
$gate->verdict = ['blocked' => true, 'integrated' => false, 'stage' => 'in_foundation', 'outstanding' => ['salvation', 'water_baptism']];
$svc   = new JourneyService($db, $clock, null, [], null, $gate);
$r     = $svc->transition($ORG, 'alice', 'in_foundation', ['actor_id' => 'leader1']);
chk('refused with INTEGRATION_REQUIRED', $r->failed() && $r->code === 'INTEGRATION_REQUIRED', (string) $r->code);
chk('the blocked payload is surfaced', ($r->errors['outstanding'] ?? null) === ['salvation', 'water_baptism'], json_encode($r->errors));
chk('no journey row was written', $db->rows['member_journeys'] === []);
chk('gate consulted once, with the target stage', count($gate->calls) === 1 && $gate->calls[0]['stage'] === 'in_foundation');
chk('gate consulted with the user + org', $gate->calls[0]['user'] === 'alice' && $gate->calls[0]['org'] === 'org1');

// ---- 2. Group context passes through ------------------------------------------
echo "the journey's context group reaches the gate\n";
$db   = $world();
$gate = new SpyGate();
$svc  = new JourneyService($db, $clock, null, [], null, $gate);
$svc->transition($ORG, 'bob', 'in_foundation', ['group_id' => 'g1']);
chk('context group passed to the gate', $gate->calls[0]['group'] === 'g1');
chk('null group stays null when absent', (static function () use ($world, $clock): bool {
    $db   = $world();
    $gate = new SpyGate();
    $svc  = new JourneyService($db, $clock, null, [], null, $gate);
    $svc->transition('org1', 'carl', 'in_foundation', []);
    return $gate->calls[0]['group'] === null;
})());

// ---- 3. Allowing gate: transition proceeds ------------------------------------
echo "a null verdict lets the transition proceed as before\n";
$db   = $world();
$gate = new SpyGate();
$gate->verdict = null;
$svc  = new JourneyService($db, $clock, null, [], null, $gate);
$r    = $svc->transition($ORG, 'dina', 'in_foundation', ['actor_id' => 'leader1']);
chk('transition ok', $r->ok && ($r->data['stage_code'] ?? null) === 'in_foundation', json_encode($r->data));
chk('journey row written', count($db->rows['member_journeys']) === 1);

// ---- 4. No gate wired: identical behaviour ------------------------------------
echo "no gate wired ⇒ nothing changes\n";
$db  = $world();
$svc = new JourneyService($db, $clock);
$r   = $svc->transition($ORG, 'ed', 'in_foundation', ['actor_id' => 'leader1']);
chk('transition ok with no gate', $r->ok);

// ---- 5. Off the ladder still refuses before the gate matters ------------------
$db  = $world();
$gate = new SpyGate();
$svc = new JourneyService($db, $clock, null, [], null, $gate);
$r = $svc->transition($ORG, 'fay', 'no_such_stage', []);
chk('unknown stage still refused (NO_STAGE) without consulting the gate', $r->failed() && $r->code === 'NO_STAGE' && $gate->calls === []);

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);

}
