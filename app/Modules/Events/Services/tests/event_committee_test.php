<?php

declare(strict_types=1);

/**
 * EVENT COMMITTEE + PLAN service test — the governance core of the optional
 * pre-event committee feature, exercised against an in-memory query builder (no
 * MySQL, no framework boot):
 *
 *   1. Capability gating: `event_committee` resolves from HIERARCHICAL GROUP CONFIG
 *      and FAILS CLOSED (no group, no config, garbage config, a throwing resolver).
 *   2. The authority seam: REACH IS NOT AUTHORITY. An actor whose role assignment
 *      covers the event's group is still refused unless the platform's decision
 *      point says they HOLD the capability there — and every governance act asks it,
 *      with the group, so a delegation counts and a bare scope never does.
 *   3. Formation: mandate required, one committee per event (idempotent), formable
 *      event states only, an accountable oversight group always, and a bounded
 *      authority window (event end + grace) — never open-ended.
 *   4. Membership: the chair may appoint only while sub-delegation is allowed and
 *      only if they still hold the chair's own capability; a lane delegates exactly
 *      its existing bit; head-count is clamped; a returning member is reactivated,
 *      never duplicated; removal REVOKES the delegation.
 *   5. Dissolve + close-out: every member's authority is revoked, open decisions are
 *      cancelled, the rows stay as history, and the hook is idempotent.
 *   6. The oversight queue: maker-checker with NO expiry, the group's rung deciding
 *      what needs approval, self-approval refused, approval demanding the same
 *      capability as the act, and an effect that fails leaving the decision pending
 *      rather than reading as done.
 *   7. The work engine: cross-event rows refused, the dependency graph kept acyclic,
 *      predecessors gating completion unless forced, derived roll-ups, and the
 *      topological order the plan renders in.
 *
 *   php app/Modules/Events/Services/tests/event_committee_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public int $inserts = 0;

        public function table(string $t): \Fake\QB
        {
            if (! isset($this->rows[$t])) {
                $this->rows[$t] = [];
            }

            return new \Fake\QB($this, $t);
        }

        public function tableExists(string $t): bool
        {
            return isset($this->rows[$t]);
        }
    }
}

namespace Fake {
    class RS
    {
        /** @param list<array<string,mixed>> $rows */
        public function __construct(private array $rows)
        {
        }

        public function getRowArray(): ?array
        {
            return $this->rows[0] ?? null;
        }

        public function getFirstRow(): ?array
        {
            return $this->rows[0] ?? null;
        }

        public function getResultArray(): array
        {
            return $this->rows;
        }

        public function getNumRows(): int
        {
            return count($this->rows);
        }
    }

    /**
     * Just enough query builder: equality + comparison wheres, whereIn, OR groups,
     * ordering, limit, insert/update/delete and count. In-memory, deterministic.
     */
    class QB
    {
        /** @var list<array{or:list<array<string,mixed>}>} AND-ed items, each an OR-ed predicate list */
        private array $and = [];

        /** @var list<array<string,mixed>>|null */
        private ?array $group = null;

        /** @var list<array{col:string,dir:string}> */
        private array $order = [];

        private ?int $limit = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s = null, $escape = true): self
        {
            return $this;
        }

        public function distinct($v = true): self
        {
            return $this;
        }

        public function join($table, $cond, $type = ''): self
        {
            return $this;
        }

        public function groupStart(): self
        {
            $this->group = [];

            return $this;
        }

        public function groupEnd(): self
        {
            if ($this->group !== null && $this->group !== []) {
                $this->and[] = ['or' => $this->group];
            }
            $this->group = null;

            return $this;
        }

        public function where($k, $v = null, $escape = true): self
        {
            $pred = $this->pred((string) $k, $v, '=');
            if ($this->group !== null) {
                $this->group[] = $pred;
            } else {
                $this->and[] = ['or' => [$pred]];
            }

            return $this;
        }

        public function orWhere($k, $v = null, $escape = true): self
        {
            $pred = $this->pred((string) $k, $v, '=');
            if ($this->group !== null) {
                $this->group[] = $pred;
            } elseif ($this->and !== []) {
                $last = count($this->and) - 1;
                $this->and[$last]['or'][] = $pred;
            } else {
                $this->and[] = ['or' => [$pred]];
            }

            return $this;
        }

        public function whereIn($k, ?array $v = null, $escape = true): self
        {
            $pred = ['col' => trim((string) $k), 'op' => 'in', 'val' => array_map('strval', (array) $v)];
            if ($this->group !== null) {
                $this->group[] = $pred;
            } else {
                $this->and[] = ['or' => [$pred]];
            }

            return $this;
        }

        public function whereNotIn($k, ?array $v = null, $escape = true): self
        {
            $pred = ['col' => trim((string) $k), 'op' => 'notin', 'val' => array_map('strval', (array) $v)];
            $this->and[] = ['or' => [$pred]];

            return $this;
        }

        public function orderBy($k, $d = 'ASC', $e = true): self
        {
            foreach ((array) $k as $col) {
                $this->order[] = ['col' => trim((string) $col), 'dir' => strtoupper((string) $d) === 'DESC' ? 'DESC' : 'ASC'];
            }

            return $this;
        }

        public function limit($n = null, $offset = 0): self
        {
            $this->limit = $n === null ? null : max(0, (int) $n);

            return $this;
        }

        public function offset($n): self
        {
            return $this;
        }

        public function get($limit = null, $offset = 0): RS
        {
            if ($limit !== null) {
                $this->limit = (int) $limit;
            }
            $rows = $this->rowsFor();
            if ($this->order !== []) {
                usort($rows, function (array $a, array $b): int {
                    foreach ($this->order as $o) {
                        $av = isset($a[$o['col']]) && $a[$o['col']] !== null ? (string) $a[$o['col']] : '';
                        $bv = isset($b[$o['col']]) && $b[$o['col']] !== null ? (string) $b[$o['col']] : '';
                        $c  = strcmp($av, $bv);
                        if ($c !== 0) {
                            return $o['dir'] === 'DESC' ? -$c : $c;
                        }
                    }

                    return 0;
                });
            }
            if ($this->limit !== null) {
                $rows = array_slice($rows, 0, $this->limit);
            }

            return new RS($rows);
        }

        public function countAllResults(bool $reset = true): int
        {
            return count($this->rowsFor());
        }

        public function insert(array $set, bool $escape = true): bool
        {
            $this->db->rows[$this->t][] = $set;
            $this->db->inserts++;

            return true;
        }

        public function insertBatch(array $set, bool $escape = true): int
        {
            foreach ($set as $row) {
                $this->insert($row);
            }

            return count($set);
        }

        public function set($k, $v = null): self
        {
            return $this;
        }

        public function update(?array $set = null, $where = null, $limit = null): bool
        {
            foreach ($this->db->rows[$this->t] as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, (array) $set);
                }
            }

            return true;
        }

        public function delete($where = null, int $limit = 0, bool $purge = true): bool
        {
            $keep = [];
            foreach ($this->db->rows[$this->t] as $r) {
                if (! $this->matches($r)) {
                    $keep[] = $r;
                }
            }
            $this->db->rows[$this->t] = $keep;

            return true;
        }

        private function pred(string $key, mixed $val, string $defaultOp): array
        {
            $op  = $defaultOp;
            $col = trim($key);
            foreach (['<=', '>=', '!=', '<>', '<', '>'] as $candidate) {
                if (str_ends_with($col, $candidate)) {
                    $col = trim(substr($col, 0, -strlen($candidate)));
                    $op  = $candidate === '<>' ? '!=' : $candidate;
                    break;
                }
            }

            return ['col' => $col, 'op' => $op, 'val' => $val];
        }

        private function rowsFor(): array
        {
            return array_values(array_filter(
                $this->db->rows[$this->t] ?? [],
                fn (array $r): bool => $this->matches($r),
            ));
        }

        private function matches(array $row): bool
        {
            foreach ($this->and as $item) {
                $any = false;
                foreach ($item['or'] as $p) {
                    if ($this->satisfies($row, $p)) {
                        $any = true;
                        break;
                    }
                }
                if (! $any) {
                    return false;
                }
            }

            return true;
        }

        /** @param array<string,mixed> $p */
        private function satisfies(array $row, array $p): bool
        {
            $col = $p['col'];
            $val = $p['val'];
            $rv  = $row[$col] ?? null;

            switch ($p['op']) {
                case 'in':
                    return $rv !== null && in_array((string) $rv, (array) $val, true);
                case 'notin':
                    return $rv === null || ! in_array((string) $rv, (array) $val, true);
                case '<':
                case '<=':
                case '>':
                case '>=':
                    if ($rv === null) {
                        return false;
                    }
                    $c = strcmp((string) $rv, (string) $val);

                    return match ($p['op']) {
                        '<'     => $c < 0,
                        '<='    => $c <= 0,
                        '>'     => $c > 0,
                        default => $c >= 0,
                    };
                case '!=':
                    return $val === null ? $rv !== null : (string) $rv !== (string) $val;
                default:
                    if ($val === null) {
                        return $rv === null;
                    }
                    if (is_bool($val)) {
                        return (bool) $rv === $val;
                    }

                    return (string) $rv === (string) $val;
            }
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Shared/Support/ScopeMode.php';
require_once $root . '/app/Modules/Shared/Support/GroupScopeResolver.php';
require_once $root . '/app/Modules/Audit/Services/AuditLogger.php';
require_once $root . '/app/Modules/Events/Support/CommitteeOversight.php';
require_once $root . '/app/Modules/Events/Support/CommitteeResponsibility.php';
require_once $root . '/app/Modules/Events/Support/CommitteeConfig.php';
require_once $root . '/app/Modules/Events/Support/WorkPlan.php';
require_once $root . '/app/Modules/Events/Services/EventConfigPort.php';
require_once $root . '/app/Modules/Events/Services/CommitteeAuthorityPort.php';
require_once $root . '/app/Modules/Events/Services/CommitteeService.php';
require_once $root . '/app/Modules/Events/Services/EventWorkService.php';
require_once $root . '/app/Modules/Events/Services/CommitteeDecisionService.php';

use WBS\Audit\Services\AuditLogger;
use WBS\Events\Services\CommitteeAuthorityPort;
use WBS\Events\Services\CommitteeDecisionService;
use WBS\Events\Services\CommitteeService;
use WBS\Events\Services\EventConfigPort;
use WBS\Events\Services\EventWorkService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\ScopeMode;

const ORG = 'org-1';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

/** Hierarchical group config, in memory. `throws` makes the resolver blow up. */
class ConfigSpy implements EventConfigPort
{
    /** @param array<string,mixed> $map "group|capability" => value */
    public function __construct(public array $map = [], public bool $throws = false)
    {
    }

    public int $calls = 0;

    public function value(string $groupId, string $capability): mixed
    {
        $this->calls++;
        if ($this->throws) {
            throw new RuntimeException('config store unavailable');
        }

        return $this->map[$groupId . '|' . $capability] ?? null;
    }
}

/** The authority seam: a spy over the platform's decision point + delegations. */
class AuthSpy implements CommitteeAuthorityPort
{
    /** @var list<array{s:string,p:string,g:?string}> */
    public array $grants = [];

    /** @var list<array{s:string,p:string,g:?string}> */
    public array $asked = [];

    /** @var list<array<string,mixed>> */
    public array $delegated = [];

    /** @var list<array<string,mixed>> */
    public array $revoked = [];

    public bool $delegateFails = false;

    public bool $revokeFails = false;

    public function holds(string $organizationId, string $subjectId, string $permission, ?string $groupId): bool
    {
        $this->asked[] = ['s' => $subjectId, 'p' => $permission, 'g' => $groupId];
        foreach ($this->grants as $g) {
            if ($g['s'] === $subjectId && $g['p'] === $permission && $g['g'] === $groupId) {
                return true;
            }
        }

        return false;
    }

    public function delegate(string $organizationId, string $delegatorId, string $delegateId, string $permission, array $opts): Result
    {
        if ($this->delegateFails) {
            return Result::fail('DELEGATION_FAILED', 'access.delegation.failed', 409);
        }
        $this->delegated[] = ['from' => $delegatorId, 'to' => $delegateId, 'permission' => $permission] + $opts;

        return Result::ok([
            'delegation_id' => 'del-' . count($this->delegated),
            'effective_to'  => null,
        ]);
    }

    public function revoke(string $organizationId, string $actorId, string $delegationId, string $reason): Result
    {
        if ($this->revokeFails) {
            return Result::fail('REVOKE_FAILED', 'access.delegation.failed', 409);
        }
        $this->revoked[] = ['id' => $delegationId, 'by' => $actorId, 'reason' => $reason];

        return Result::ok(['revoked' => true]);
    }

    public function grant(string $subject, string $permission, ?string $group): void
    {
        $this->grants[] = ['s' => $subject, 'p' => $permission, 'g' => $group];
    }

    /** Was the decision point asked exactly this question? */
    public function wasAsked(string $subject, string $permission, ?string $group): bool
    {
        foreach ($this->asked as $a) {
            if ($a['s'] === $subject && $a['p'] === $permission && $a['g'] === $group) {
                return true;
            }
        }

        return false;
    }
}

/** The canonical fixture: one org, a small hierarchy, four people, four events. */
function fixture(): \CodeIgniter\Database\BaseConnection
{
    $db = new CodeIgniter\Database\BaseConnection();
    $now = '2026-09-21 12:00:00';

    $db->rows['events'] = [
        // A live event with a group and a full window ahead of it.
        ['id' => 'ev-1', 'organization_id' => ORG, 'title' => 'Convention 2026', 'status' => 'published',
            'group_id' => 'g-local', 'starts_at' => '2026-10-01 09:00:00', 'ends_at' => '2026-10-03 18:00:00'],
        // Still published, but the window (plus grace) is behind us.
        ['id' => 'ev-past', 'organization_id' => ORG, 'title' => 'Old Rally', 'status' => 'published',
            'group_id' => 'g-local', 'starts_at' => '2026-01-05 09:00:00', 'ends_at' => '2026-01-06 18:00:00'],
        // Closed: nothing may be delegated for it any more.
        ['id' => 'ev-done', 'organization_id' => ORG, 'title' => 'Last Year', 'status' => 'completed',
            'group_id' => 'g-local', 'starts_at' => '2025-10-01 09:00:00', 'ends_at' => '2025-10-02 18:00:00'],
        // Org-wide: no group, so an oversight group must be named explicitly.
        ['id' => 'ev-wide', 'organization_id' => ORG, 'title' => 'National Stream', 'status' => 'draft',
            'group_id' => null, 'starts_at' => '2026-11-01 09:00:00', 'ends_at' => '2026-11-02 18:00:00'],
    ];
    $db->rows['users'] = [
        ['id' => 'u-leader', 'organization_id' => ORG, 'display_name' => 'Area Leader'],
        ['id' => 'u-chair', 'organization_id' => ORG, 'display_name' => 'Chair Person'],
        ['id' => 'u-fin', 'organization_id' => ORG, 'display_name' => 'Finance Lead'],
        ['id' => 'u-comms', 'organization_id' => ORG, 'display_name' => 'Comms Lead'],
        ['id' => 'u-scoped', 'organization_id' => ORG, 'display_name' => 'Cell Leader (check-in only)'],
        ['id' => 'u-stranger', 'organization_id' => ORG, 'display_name' => 'Nobody In Particular'],
    ];
    $db->rows['groups'] = [
        ['id' => 'g-area', 'organization_id' => ORG, 'name' => 'Greater Accra', 'parent_id' => null, 'path' => '/g-area/', 'depth' => 1, 'status' => 'active'],
        ['id' => 'g-local', 'organization_id' => ORG, 'name' => 'Ridge Assembly', 'parent_id' => 'g-area', 'path' => '/g-area/g-local/', 'depth' => 2, 'status' => 'active'],
    ];
    $db->rows['group_closure'] = [
        ['ancestor_id' => 'g-area', 'descendant_id' => 'g-area', 'distance' => 0],
        ['ancestor_id' => 'g-area', 'descendant_id' => 'g-local', 'distance' => 1],
        ['ancestor_id' => 'g-local', 'descendant_id' => 'g-local', 'distance' => 0],
    ];
    // A cell leader scoped to the event's group who holds NO event capability:
    // the fixture that proves reach is not authority.
    $db->rows['role_assignments'] = [
        ['id' => 'ra-1', 'organization_id' => ORG, 'subject_id' => 'u-scoped', 'scope_group_id' => 'g-local',
            'scope_mode' => ScopeMode::SELF, 'include_descendants' => 0, 'include_crosscut' => 0,
            'status' => 'active', 'effective_from' => null, 'effective_to' => null],
    ];
    $db->rows['delegations']         = [];
    $db->rows['grant_scope_groups']  = [];
    $db->rows['audit_log']           = [];
    $db->rows['event_committees']    = [];
    $db->rows['event_committee_members'] = [];
    $db->rows['event_committee_decisions'] = [];
    $db->rows['event_workstreams']   = [];
    $db->rows['event_tasks']         = [];
    $db->rows['event_milestones']    = [];
    $db->rows['event_task_dependencies'] = [];
    $db->rows['group_crosscut_links'] = [];

    return $db;
}

/**
 * Wire the three services over a fixture.
 *
 * @param array<string,mixed> $opts config (map), grants (list of [s,p,g]), authority
 *                                  (wire the port), scope (wire the resolver),
 *                                  committeesForDecisions, workForDecisions
 *
 * @return array<string,mixed>
 */
function svc(array $opts = []): array
{
    Clock::freeze(new DateTimeImmutable('2026-09-21 12:00:00', new DateTimeZone('UTC')));
    $clock = new Clock();
    $db    = $opts['db'] ?? fixture();

    $config = new ConfigSpy($opts['config'] ?? ['g-local|event_committee' => ['enabled' => true]]);
    $auth   = new AuthSpy();
    foreach ($opts['grants'] ?? [] as [$s, $p, $g]) {
        $auth->grant($s, $p, $g);
    }
    $audit    = new AuditLogger($db, $clock);
    $scope    = ($opts['scope'] ?? true) ? new GroupScopeResolver($db) : null;
    $authority = ($opts['authority'] ?? true) ? $auth : null;

    $committees = new CommitteeService($db, $clock, $config, $scope, $authority, $audit);
    $work       = new EventWorkService($db, $clock, $config, $committees, $authority, $audit);
    $decisions  = new CommitteeDecisionService(
        $db,
        $clock,
        $config,
        ($opts['committeesForDecisions'] ?? true) ? $committees : null,
        ($opts['workForDecisions'] ?? true) ? $work : null,
        $authority,
        $audit,
    );

    return compact('db', 'clock', 'config', 'auth', 'audit', 'committees', 'work', 'decisions');
}

/** A formed committee with a chair and a finance lead, ready to govern. */
function formedCommittee(array $opts = []): array
{
    $s = svc($opts + ['grants' => array_merge([
        ['u-leader', 'event.create', 'g-local'],
        ['u-leader', 'event.logistics.manage', 'g-local'],
        ['u-leader', 'event.expense.approve', 'g-local'],
        ['u-leader', 'event.schedule.approve', 'g-local'],
        ['u-chair', 'event.logistics.manage', 'g-local'],
    ], $opts['grants'] ?? [])]);
    $res = $s['committees']->form(ORG, 'ev-1', 'u-leader', [
        'mandate'       => 'Run Convention 2026 as a project',
        'chair_user_id' => 'u-chair',
        'members'       => [['user_id' => 'u-fin', 'responsibility' => 'finance']],
    ]);

    return $s + ['formed' => $res, 'committeeId' => (string) ($res->data['committee_id'] ?? '')];
}

/** One row of a table by id. */
function rowById(\CodeIgniter\Database\BaseConnection $db, string $table, string $id): ?array
{
    foreach ($db->rows[$table] ?? [] as $r) {
        if ((string) ($r['id'] ?? '') === $id) {
            return $r;
        }
    }

    return null;
}

// ── 1. Capability gating fails closed ─────────────────────────────────────────
echo "capability gating (default OFF, fail closed)\n";
$s = svc(['config' => []]);
chk('no config anywhere ⇒ disabled', $s['committees']->enabledFor('g-local') === false);
chk('no group ⇒ disabled', $s['committees']->enabledFor(null) === false);
chk('empty group id ⇒ disabled', $s['committees']->enabledFor('') === false);
chk('config for an unknown group is off', $s['committees']->configFor('g-nope')->enabled === false);

$s = svc(['config' => ['g-local|event_committee' => ['enabled' => true]]]);
chk('an enabling config turns the capability on', $s['committees']->enabledFor('g-local') === true);
chk('the resolved config carries the group\'s settings', $s['committees']->configFor('g-local')->maxMembers === 12);

$s = svc(['config' => ['g-local|event_committee' => 'not json at all']]);
chk('garbage config fails closed', $s['committees']->enabledFor('g-local') === false);

$s = svc(['config' => ['g-local|event_committee' => ['enabled' => true]], 'db' => fixture()]);
$s['config']->throws = true;
chk('a throwing config store fails closed (never "on")', $s['committees']->enabledFor('g-local') === false);
chk('the resolver was actually consulted', $s['config']->calls > 0);

// ── 2. The authority seam: reach is not authority ─────────────────────────────
echo "\nauthority seam (PDP decides; scope coverage does not)\n";
$s = svc(['grants' => [['u-chair', 'event.logistics.manage', 'g-local']]]);
$denied = $s['work']->createTask(ORG, 'ev-1', 'u-scoped', ['title' => 'Set up the hall']);
chk('a scoped-but-unauthorized actor is refused the plan', ! $denied->ok, (string) $denied->code);
chk('refusal is 403 NOT_AUTHORIZED', $denied->code === 'NOT_AUTHORIZED' && $denied->status === 403, (string) $denied->status);
chk('their role assignment DOES cover the group (reach)', $s['committees']->scopeCovers(ORG, 'u-scoped', 'g-local') === true);
chk('the decision point was asked, with the group', $s['auth']->wasAsked('u-scoped', 'event.logistics.manage', 'g-local'));

$ok = $s['work']->createTask(ORG, 'ev-1', 'u-chair', ['title' => 'Set up the hall']);
chk('a holder of the capability may plan', $ok->ok, (string) ($ok->message ?? ''));
chk('the task was written', count($s['db']->rows['event_tasks']) === 1);

$s = svc(['authority' => false]);
$noPdp = $s['work']->createTask(ORG, 'ev-1', 'u-chair', ['title' => 'Set up the hall']);
chk('no decision point wired ⇒ refused (fail closed)', ! $noPdp->ok && $noPdp->code === 'NOT_AUTHORIZED', (string) $noPdp->code);

$s = svc(['config' => [], 'grants' => [['u-chair', 'event.logistics.manage', 'g-local']]]);
$gated = $s['work']->createTask(ORG, 'ev-1', 'u-chair', ['title' => 'Set up the hall']);
chk('capability OFF ⇒ the plan is refused even for a holder', ! $gated->ok && $gated->code === 'PLAN_DISABLED' && $gated->status === 403, (string) $gated->code);
chk('…and nothing was written', count($s['db']->rows['event_tasks']) === 0);

$missing = svc();
$noEvent = $missing['work']->createTask(ORG, 'ev-nope', 'u-chair', ['title' => 'x']);
chk('an unknown event is a 404', ! $noEvent->ok && $noEvent->status === 404, (string) $noEvent->status);

// A member's SEAT is the authority to plan, even with no capability of their own.
$s = formedCommittee();
$memberPlan = $s['work']->createTask(ORG, 'ev-1', 'u-fin', ['title' => 'Draft the budget']);
chk('a committee member may plan without holding the bit', $memberPlan->ok, (string) ($memberPlan->message ?? ''));
$outsider = $s['work']->createTask(ORG, 'ev-1', 'u-stranger', ['title' => 'Sneak in']);
chk('a non-member without the bit may not', ! $outsider->ok && $outsider->code === 'NOT_AUTHORIZED');

// ── 3. Formation ──────────────────────────────────────────────────────────────
echo "\nformation\n";
$s = svc(['grants' => [['u-leader', 'event.create', 'g-local']]]);
$noMandate = $s['committees']->form(ORG, 'ev-1', 'u-leader', ['chair_user_id' => 'u-chair']);
chk('a committee without a mandate is refused', ! $noMandate->ok && $noMandate->code === 'MANDATE_REQUIRED' && $noMandate->status === 422, (string) $noMandate->code);
chk('…and nothing was written', count($s['db']->rows['event_committees']) === 0);

$noAuth = $s['committees']->form(ORG, 'ev-1', 'u-stranger', ['mandate' => 'Run it']);
chk('forming without event.create over the group is refused', ! $noAuth->ok && $noAuth->code === 'NOT_AUTHORIZED' && $noAuth->status === 403);
chk('the PDP was asked for event.create over the oversight group', $s['auth']->wasAsked('u-stranger', 'event.create', 'g-local'));

$closed = $s['committees']->form(ORG, 'ev-done', 'u-leader', ['mandate' => 'Run it']);
chk('a completed event cannot form a committee', ! $closed->ok && $closed->code === 'BAD_EVENT_STATE' && $closed->status === 409, (string) $closed->code);

$past = $s['committees']->form(ORG, 'ev-past', 'u-leader', ['mandate' => 'Run it']);
chk('a closed window cannot delegate authority', ! $past->ok && $past->code === 'EVENT_WINDOW_CLOSED' && $past->status === 409, (string) $past->code);

$wide = $s['committees']->form(ORG, 'ev-wide', 'u-leader', ['mandate' => 'Run it']);
chk('an org-wide event must name an oversight group', ! $wide->ok && $wide->code === 'OVERSIGHT_GROUP_REQUIRED' && $wide->status === 422, (string) $wide->code);

$off = svc(['config' => [], 'grants' => [['u-leader', 'event.create', 'g-local']]]);
$gatedForm = $off['committees']->form(ORG, 'ev-1', 'u-leader', ['mandate' => 'Run it']);
chk('capability OFF ⇒ formation refused', ! $gatedForm->ok && $gatedForm->code === 'COMMITTEES_DISABLED' && $gatedForm->status === 403);

$s = formedCommittee();
chk('formation succeeds', $s['formed']->ok && $s['formed']->status === 201, (string) ($s['formed']->message ?? ''));
$committee = rowById($s['db'], 'event_committees', $s['committeeId']);
chk('one committee row', count($s['db']->rows['event_committees']) === 1);
chk('it is active', (string) $committee['status'] === 'active');
chk('the event group is its scope', (string) $committee['scope_group_id'] === 'g-local');
chk('the oversight group defaults to the event group', (string) $committee['oversight_group_id'] === 'g-local');
chk('the oversight rung comes from group config', (string) $committee['oversight_mode'] === 'formation_and_major');
chk('the head-count limit comes from group config', (int) $committee['max_members'] === 12);
chk('the grace window comes from group config', (int) $committee['grace_days'] === 7);
chk('the mandate is stored', (string) $committee['mandate'] === 'Run Convention 2026 as a project');
chk('the chair is recorded', (string) $committee['chair_user_id'] === 'u-chair');
chk('formation is attributed', (string) $committee['formed_by'] === 'u-leader');

$members = $s['db']->rows['event_committee_members'];
chk('the chair and the listed member were seated', count($members) === 2, (string) count($members));
$chairRow = null;
$finRow   = null;
foreach ($members as $m) {
    if ((string) $m['user_id'] === 'u-chair') {
        $chairRow = $m;
    }
    if ((string) $m['user_id'] === 'u-fin') {
        $finRow = $m;
    }
}
chk('the chair row is the chair', (int) $chairRow['is_chair'] === 1 && (string) $chairRow['responsibility'] === 'chair');
chk('the chair was delegated the plan capability', (string) $chairRow['delegated_permission'] === 'event.logistics.manage');
chk('the finance lane was delegated submit-only (SoD)', (string) $finRow['delegated_permission'] === 'event.expense.submit');
chk('no lane was delegated an approval bit', ! str_contains((string) $finRow['delegated_permission'], '.approve')
    && ! str_contains((string) $chairRow['delegated_permission'], '.approve'));
chk('the window ends at the event end plus grace', (string) $chairRow['effective_to'] === '2026-10-10 18:00:00', (string) $chairRow['effective_to']);
chk('authority is bounded to the member themself', (string) $chairRow['scope_mode'] === ScopeMode::SELF);
chk('authority is bounded to the oversight group', (string) $chairRow['scope_group_id'] === 'g-local');
chk('a delegation was cut for each lane', count($s['auth']->delegated) === 2, (string) count($s['auth']->delegated));
chk('the delegation is bounded in days (<= 365)', (int) $s['auth']->delegated[0]['duration_days'] <= 365 && (int) $s['auth']->delegated[0]['duration_days'] >= 1);
chk('the delegation names its purpose (the mandate)', str_contains((string) $s['auth']->delegated[0]['purpose'], 'Convention 2026'));
chk('the delegator is whoever appointed', (string) $s['auth']->delegated[0]['from'] === 'u-leader');
chk('formation was audited', (bool) array_filter($s['db']->rows['audit_log'], static fn ($r): bool => (string) $r['action'] === 'event.committee.formed'));
chk('seating was audited', count(array_filter($s['db']->rows['audit_log'], static fn ($r): bool => (string) $r['action'] === 'event.committee.member.added')) === 2);

$again = $s['committees']->form(ORG, 'ev-1', 'u-leader', ['mandate' => 'Run it twice']);
chk('forming twice is idempotent', $again->ok && ! empty($again->meta['deduplicated']));
chk('…and still only one committee exists', count($s['db']->rows['event_committees']) === 1);

// Authority failure never blocks the seat, but is recorded.
$s = formedCommittee();
$s['auth']->delegateFails = true;
$seat = $s['committees']->addMember(ORG, $s['committeeId'], 'u-leader', ['user_id' => 'u-comms', 'responsibility' => 'communications']);
chk('a failed delegation still seats the member', $seat->ok, (string) ($seat->message ?? ''));
chk('…and reports the authority error', (string) ($seat->data['authority_error'] ?? '') === 'DELEGATION_FAILED');
$comms = null;
foreach ($s['db']->rows['event_committee_members'] as $m) {
    if ((string) $m['user_id'] === 'u-comms') {
        $comms = $m;
    }
}
chk('the seat carries no delegation id when the grant failed', ($comms['delegation_id'] ?? null) === null);
chk('the intended capability is still visible on the row', (string) ($comms['delegated_permission'] ?? '') === 'notification.send');
chk('the partial outcome was audited', (bool) array_filter($s['db']->rows['audit_log'], static fn ($r): bool => (string) ($r['outcome'] ?? '') === 'partial'));

// ── 4. Membership ─────────────────────────────────────────────────────────────
echo "\nmembership, lanes and sub-delegation\n";
$s = formedCommittee();
$chairAdds = $s['committees']->addMember(ORG, $s['committeeId'], 'u-chair', ['user_id' => 'u-comms', 'responsibility' => 'communications', 'label' => 'Publicity']);
chk('the chair may appoint while holding their own capability', $chairAdds->ok, (string) ($chairAdds->message ?? ''));
chk('the label is stored as the specific remit', (function ($db) {
    foreach ($db->rows['event_committee_members'] as $m) {
        if ((string) $m['user_id'] === 'u-comms') {
            return (string) ($m['responsibility_label'] ?? '') === 'Publicity';
        }
    }

    return false;
})($s['db']));

$noWay = $s['committees']->addMember(ORG, $s['committeeId'], 'u-stranger', ['user_id' => 'u-comms']);
chk('a stranger may not appoint', ! $noWay->ok && $noWay->code === 'NOT_AUTHORIZED');

$ghost = $s['committees']->addMember(ORG, $s['committeeId'], 'u-leader', ['user_id' => 'u-nobody']);
chk('an unknown person cannot be seated', ! $ghost->ok && $ghost->status === 404 && $ghost->code === 'USER_NOT_FOUND', (string) $ghost->code);

$blank = $s['committees']->addMember(ORG, $s['committeeId'], 'u-leader', ['user_id' => '']);
chk('appointing nobody is refused', ! $blank->ok && $blank->code === 'USER_REQUIRED');

$general = $s['committees']->addMember(ORG, $s['committeeId'], 'u-leader', ['user_id' => 'u-stranger', 'responsibility' => 'general']);
chk('a general member is seated', $general->ok);
chk('a general member gets participation, not authority', array_key_exists('delegated_permission', $general->data)
    && $general->data['delegated_permission'] === null && count($s['auth']->delegated) === 3, (string) count($s['auth']->delegated));

$dupe = $s['committees']->addMember(ORG, $s['committeeId'], 'u-leader', ['user_id' => 'u-fin', 'responsibility' => 'finance']);
chk('re-appointing a seated member does not duplicate the seat', $dupe->ok
    && count(array_filter($s['db']->rows['event_committee_members'], static fn ($m): bool => (string) $m['user_id'] === 'u-fin')) === 1);

// Sub-delegation switched off by group config.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'allow_subdelegation' => false]]]);
$noSub = $s['committees']->addMember(ORG, $s['committeeId'], 'u-chair', ['user_id' => 'u-comms', 'responsibility' => 'communications']);
chk('with sub-delegation off the chair cannot appoint', ! $noSub->ok && $noSub->code === 'SUBDELEGATION_DISABLED' && $noSub->status === 403, (string) $noSub->code);
$leaderStill = $s['committees']->addMember(ORG, $s['committeeId'], 'u-leader', ['user_id' => 'u-comms', 'responsibility' => 'communications']);
chk('…but the leader still can', $leaderStill->ok, (string) ($leaderStill->message ?? ''));

// A chair who no longer holds their own capability cannot appoint.
$s = formedCommittee(['grants' => [
    ['u-leader', 'event.create', 'g-local'],
    ['u-leader', 'event.expense.approve', 'g-local'],
    ['u-leader', 'event.schedule.approve', 'g-local'],
]]);
$expiredChair = $s['committees']->addMember(ORG, $s['committeeId'], 'u-chair', ['user_id' => 'u-comms']);
chk('a chair without the capability cannot appoint (delegation expired)', ! $expiredChair->ok && $expiredChair->code === 'NOT_AUTHORIZED');

// Head count.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'max_members' => 2]]]);
$third = $s['committees']->addMember(ORG, $s['committeeId'], 'u-leader', ['user_id' => 'u-comms', 'responsibility' => 'communications']);
chk('the head-count limit is enforced', ! $third->ok && $third->code === 'COMMITTEE_FULL' && $third->status === 409, (string) $third->code);

// Removal revokes authority.
$s = formedCommittee();
$finId = (string) (function ($db) {
    foreach ($db->rows['event_committee_members'] as $m) {
        if ((string) $m['user_id'] === 'u-fin') {
            return $m['id'];
        }
    }

    return '';
})($s['db']);
$removed = $s['committees']->removeMember(ORG, $finId, 'u-leader', 'no longer available');
chk('removal succeeds', $removed->ok, (string) ($removed->message ?? ''));
$finRow = rowById($s['db'], 'event_committee_members', $finId);
chk('the seat is marked removed', (string) $finRow['status'] === 'removed');
chk('the reason is recorded', (string) $finRow['removal_reason'] === 'no longer available');
chk('their delegation was revoked', ($removed->data['delegation_revoked'] ?? false) === true && count($s['auth']->revoked) === 1, json_encode($s['auth']->revoked));
chk('removal was audited', (bool) array_filter($s['db']->rows['audit_log'], static fn ($r): bool => (string) $r['action'] === 'event.committee.member.removed'));
$againRemove = $s['committees']->removeMember(ORG, $finId, 'u-leader');
chk('removing twice is a no-op', $againRemove->ok && ! empty($againRemove->meta['deduplicated']));

// Self-resignation needs no governance authority.
$s = formedCommittee();
$finId = (string) (function ($db) {
    foreach ($db->rows['event_committee_members'] as $m) {
        if ((string) $m['user_id'] === 'u-fin') {
            return $m['id'];
        }
    }

    return '';
})($s['db']);
$resign = $s['committees']->removeMember(ORG, $finId, 'u-fin');
chk('a member may resign without governance authority', $resign->ok, (string) ($resign->message ?? ''));
chk('resignation is recorded as such', (string) rowById($s['db'], 'event_committee_members', $finId)['removal_reason'] === 'resigned');
chk('resignation was audited distinctly', (bool) array_filter($s['db']->rows['audit_log'], static fn ($r): bool => (string) $r['action'] === 'event.committee.member.resigned'));

// The chair cannot simply be removed while others remain.
$s = formedCommittee();
$chairId = (string) (function ($db) {
    foreach ($db->rows['event_committee_members'] as $m) {
        if ((int) ($m['is_chair'] ?? 0) === 1) {
            return $m['id'];
        }
    }

    return '';
})($s['db']);
$chairOut = $s['committees']->removeMember(ORG, $chairId, 'u-leader');
chk('removing the chair while members remain is refused', ! $chairOut->ok && $chairOut->code === 'CHAIR_MUST_BE_REPLACED' && $chairOut->status === 409, (string) $chairOut->code);
chk('the chair is still seated', (string) rowById($s['db'], 'event_committee_members', $chairId)['status'] === 'active');

// A dissolved committee appoints nobody.
$s = formedCommittee();
$dissolved = $s['committees']->dissolve(ORG, $s['committeeId'], 'u-leader', 'event postponed indefinitely');
chk('dissolution succeeds', $dissolved->ok, (string) ($dissolved->message ?? ''));
chk('the committee is dissolved, not deleted', (string) rowById($s['db'], 'event_committees', $s['committeeId'])['status'] === 'dissolved');
chk('the reason and actor are recorded', (string) rowById($s['db'], 'event_committees', $s['committeeId'])['dissolution_reason'] === 'event postponed indefinitely');
chk('every delegation was revoked', count($s['auth']->revoked) === 2, (string) count($s['auth']->revoked));
chk('the revoked count is reported', (int) ($dissolved->data['delegations_revoked'] ?? 0) === 2);
$afterDissolve = $s['committees']->addMember(ORG, $s['committeeId'], 'u-leader', ['user_id' => 'u-comms']);
chk('a dissolved committee appoints nobody', ! $afterDissolve->ok && $afterDissolve->code === 'COMMITTEE_DISSOLVED' && $afterDissolve->status === 409, (string) $afterDissolve->code);
chk('dissolving twice is a no-op', $s['committees']->dissolve(ORG, $s['committeeId'], 'u-leader', 'again')->ok);

$noReason = formedCommittee();
chk('dissolution needs a reason', ! $noReason['committees']->dissolve(ORG, $noReason['committeeId'], 'u-leader', '   ')->ok);
$notYours = formedCommittee();
chk('dissolution needs governance authority', ! $notYours['committees']->dissolve(ORG, $notYours['committeeId'], 'u-stranger', 'because')->ok);

// ── 5. Close-out hook + expiry sweep ──────────────────────────────────────────
echo "\nclose-out and window expiry\n";
$s = formedCommittee();
$s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Pay the band', 'amount' => 5000]);
$closed = $s['committees']->onEventClosed(ORG, 'ev-1', 'u-leader');
chk('close-out dissolves the committee', $closed['dissolved'] === true);
chk('close-out revoked every delegation', $closed['delegations_revoked'] === 2, (string) $closed['delegations_revoked']);
chk('the committee row is dissolved', (string) rowById($s['db'], 'event_committees', $s['committeeId'])['status'] === 'dissolved');
chk('the dissolution reason is the event closing', (string) rowById($s['db'], 'event_committees', $s['committeeId'])['dissolution_reason'] === 'event_closed');
chk('open decisions were cancelled, not left pending', (string) $s['db']->rows['event_committee_decisions'][0]['status'] === 'cancelled');
chk('close-out was audited', (bool) array_filter($s['db']->rows['audit_log'], static fn ($r): bool => (string) $r['action'] === 'event.committee.dissolved'));
chk('close-out is idempotent', $s['committees']->onEventClosed(ORG, 'ev-1', 'u-leader') === ['dissolved' => false, 'delegations_revoked' => 0]);
chk('an event with no committee closes quietly', $s['committees']->onEventClosed(ORG, 'ev-wide') === ['dissolved' => false, 'delegations_revoked' => 0]);

// The sweep: a window that has passed expires the seat AND revokes the authority.
$s = formedCommittee();
$finId = (string) (function ($db) {
    foreach ($db->rows['event_committee_members'] as $m) {
        if ((string) $m['user_id'] === 'u-fin') {
            return $m['id'];
        }
    }

    return '';
})($s['db']);
foreach ($s['db']->rows['event_committee_members'] as $i => $m) {
    $s['db']->rows['event_committee_members'][$i]['effective_to'] = '2026-09-01 00:00:00';
}
$expired = $s['committees']->expireDue(ORG);
chk('the sweep found both expired seats', $expired['scanned'] === 2 && $expired['expired'] === 2, json_encode($expired));
chk('the sweep revoked their authority', $expired['revoked'] === 2 && count($s['auth']->revoked) === 2);
chk('expired seats are marked expired', (string) rowById($s['db'], 'event_committee_members', $finId)['status'] === 'expired');
chk('the expiry reason is recorded', (string) rowById($s['db'], 'event_committee_members', $finId)['removal_reason'] === 'window_expired');
chk('a second sweep finds nothing', $s['committees']->expireDue(ORG)['scanned'] === 0);

// ── 6. The oversight queue ────────────────────────────────────────────────────
echo "\noversight: maker-checker, rungs, effects, no expiry\n";
$s = formedCommittee();
$noCommittee = $s['decisions']->request(ORG, 'ev-wide', 'u-chair', ['title' => 'Something']);
chk('a decision needs an active committee', ! $noCommittee->ok && $noCommittee->code === 'COMMITTEE_NOT_FOUND' && $noCommittee->status === 404, (string) $noCommittee->code);
$noTitle = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget']);
chk('a decision needs a title', ! $noTitle->ok && $noTitle->code === 'TITLE_REQUIRED' && $noTitle->status === 422);
$stranger = $s['decisions']->request(ORG, 'ev-1', 'u-stranger', ['title' => 'Let me in']);
chk('a non-member who is not the leader may not record a decision', ! $stranger->ok && $stranger->code === 'NOT_AUTHORIZED');
$leaderDirection = $s['decisions']->request(ORG, 'ev-1', 'u-leader', ['title' => 'Leader direction: no fireworks', 'kind' => 'governance']);
chk('the leader may record a direction of their own', $leaderDirection->ok, (string) ($leaderDirection->message ?? ''));

// Same world, but the verifier is unwired: membership cannot be checked, so the
// service must refuse rather than guess.
$noVerifier = new CommitteeDecisionService($s['db'], $s['clock'], $s['config'], null, $s['work'], $s['auth'], $s['audit']);
$unverified = $noVerifier->request(ORG, 'ev-1', 'u-leader', ['title' => 'Anything']);
chk('membership that cannot be verified fails closed (503)', ! $unverified->ok && $unverified->code === 'OVERSIGHT_UNAVAILABLE' && $unverified->status === 503, (string) $unverified->code);

// Default rung: money under the threshold is the committee's, schedule is not.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'budget_approval_threshold' => 1000]]]);
$small = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Buy water', 'amount' => 250]);
chk('a small spend is noted, not queued', $small->ok && (string) $small->data['status'] === 'noted', (string) ($small->data['status'] ?? ''));
chk('…and needs no approval', ($small->data['needs_approval'] ?? true) === false);
$big = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Pay the band', 'amount' => 5000]);
chk('a big spend goes to the leader', $big->ok && (string) $big->data['status'] === 'pending');
chk('approving it needs the expense approval bit', (string) $big->data['required_permission'] === 'event.expense.approve');
$sched = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'schedule', 'title' => 'Move the opening an hour']);
chk('a schedule change goes to the leader', (string) $sched->data['status'] === 'pending');
chk('approving it needs the schedule approval bit', (string) $sched->data['required_permission'] === 'event.schedule.approve');
$routine = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'other', 'title' => 'Order of service printed']);
chk('routine business is noted', (string) $routine->data['status'] === 'noted');

$dedupe = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Pay the band', 'amount' => 5000]);
chk('a re-submit does not open a second request', $dedupe->ok && ! empty($dedupe->meta['deduplicated']));
chk('…and only one row exists', count(array_filter($s['db']->rows['event_committee_decisions'], static fn ($r): bool => (string) $r['title'] === 'Pay the band')) === 1);

// observe_only informs; maker_checker_all blocks everything.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'oversight' => 'observe_only', 'budget_approval_threshold' => 1]]]);
$observed = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Pay the band', 'amount' => 99999]);
chk('observe_only never blocks', (string) $observed->data['status'] === 'noted');
chk('observe_only still records the row', count($s['db']->rows['event_committee_decisions']) === 1);
chk('observe_only still audits it', (bool) array_filter($s['db']->rows['audit_log'], static fn ($r): bool => (string) $r['action'] === 'event.committee.decision.noted'));

$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'oversight' => 'maker_checker_all']]]);
$all = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'other', 'title' => 'Print the order of service']);
chk('maker_checker_all queues even routine business', (string) $all->data['status'] === 'pending');

// An effect the platform cannot perform is never promised.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'oversight' => 'observe_only']]]);
$badEffect = $s['decisions']->request(ORG, 'ev-1', 'u-chair', [
    'kind' => 'other', 'title' => 'Launch the rockets', 'effect' => ['action' => 'launch.rockets'],
]);
chk('an unknown effect action is dropped, not stored', $badEffect->ok && ($s['db']->rows['event_committee_decisions'][0]['effect_json'] ?? null) === null);
$effectRow = $s['decisions']->request(ORG, 'ev-1', 'u-chair', [
    'kind' => 'other', 'title' => 'Publish the programme',
    'effect' => ['action' => 'milestone.meet', 'milestone_id' => 'ms-1', 'evidence' => 'signed off'],
]);
chk('a known effect is stored', (string) ($effectRow->data['status'] ?? '') === 'noted');
chk('an effect that cannot be performed is reported, not hidden', ($effectRow->data['effect_applied'] ?? true) === false);

// Approve / reject / withdraw.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'budget_approval_threshold' => 1000]]]);
$task = $s['work']->createTask(ORG, 'ev-1', 'u-chair', ['title' => 'Book the band', 'assignee_user_id' => 'u-fin', 'due_at' => '2026-09-30']);
$taskId = (string) $task->data['task_id'];
$req = $s['decisions']->request(ORG, 'ev-1', 'u-chair', [
    'kind' => 'budget', 'title' => 'Pay the band', 'amount' => 5000,
    'effect' => ['action' => 'task.status', 'task_id' => $taskId, 'status' => 'in_progress'],
]);
$decisionId = (string) $req->data['decision_id'];
chk('the request is pending', (string) $req->data['status'] === 'pending');

$self = $s['decisions']->approve(ORG, $decisionId, 'u-chair');
chk('the maker cannot approve their own request', ! $self->ok && $self->code === 'SELF_APPROVAL' && $self->status === 403, (string) $self->code);
$noBit = $s['decisions']->approve(ORG, $decisionId, 'u-stranger');
chk('somebody without the approval bit cannot approve', ! $noBit->ok && $noBit->code === 'OUTSIDE_SCOPE' && $noBit->status === 403, (string) $noBit->code);
chk('the PDP was asked for the decision\'s own capability', $s['auth']->wasAsked('u-stranger', 'event.expense.approve', 'g-local'));
$missingDecision = $s['decisions']->approve(ORG, 'nope', 'u-leader');
chk('an unknown decision is a 404', ! $missingDecision->ok && $missingDecision->status === 404);

$approved = $s['decisions']->approve(ORG, $decisionId, 'u-leader', 'within budget');
chk('the leader approves', $approved->ok, (string) ($approved->message ?? ''));
$row = rowById($s['db'], 'event_committee_decisions', $decisionId);
chk('the decision is approved', (string) $row['status'] === 'approved');
chk('the decider and the moment are recorded', (string) $row['decided_by'] === 'u-leader' && ! empty($row['decided_at']));
chk('the note is recorded', (string) $row['decision_note'] === 'within budget');
chk('the effect was applied', (int) $row['effect_applied'] === 1);
chk('the effect actually happened (the task moved)', (string) rowById($s['db'], 'event_tasks', $taskId)['status'] === 'in_progress');
chk('approval was audited', (bool) array_filter($s['db']->rows['audit_log'], static fn ($r): bool => (string) $r['action'] === 'event.committee.decision.approved'));
$twice = $s['decisions']->approve(ORG, $decisionId, 'u-leader');
chk('a settled decision cannot be settled again', ! $twice->ok && $twice->code === 'BAD_STATE' && $twice->status === 409, (string) $twice->code);

// An effect that fails leaves the decision PENDING (never reads as done).
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'budget_approval_threshold' => 1000]]]);
$req = $s['decisions']->request(ORG, 'ev-1', 'u-chair', [
    'kind' => 'budget', 'title' => 'Pay a ghost', 'amount' => 2000,
    'effect' => ['action' => 'task.status', 'task_id' => 'task-does-not-exist', 'status' => 'done'],
]);
$blocked = $s['decisions']->approve(ORG, (string) $req->data['decision_id'], 'u-leader');
chk('a failed effect blocks the approval', ! $blocked->ok && $blocked->message === 'Events.decision.errEffectFailed', (string) $blocked->message);
chk('the decision stays pending', (string) rowById($s['db'], 'event_committee_decisions', (string) $req->data['decision_id'])['status'] === 'pending');
chk('the blocked approval was audited', (bool) array_filter($s['db']->rows['audit_log'], static fn ($r): bool => (string) $r['action'] === 'event.committee.decision.approval_blocked'));

// The world moved while the request sat in the queue.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'budget_approval_threshold' => 1000]]]);
$req = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Pay the band', 'amount' => 5000]);
$did = (string) $req->data['decision_id'];
$s['db']->rows['events'][0]['status'] = 'completed';
$afterClose = $s['decisions']->approve(ORG, $did, 'u-leader');
chk('a closed event cannot be decided for', ! $afterClose->ok && $afterClose->code === 'EVENT_CLOSED' && $afterClose->status === 409, (string) $afterClose->code);
$s['db']->rows['events'][0]['status'] = 'published';
$s['committees']->dissolve(ORG, $s['committeeId'], 'u-leader', 'stood down');
chk('dissolving cancels the open request', (string) rowById($s['db'], 'event_committee_decisions', $did)['status'] === 'cancelled');

// Reject + withdraw.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'budget_approval_threshold' => 1000]]]);
$req = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Pay the band', 'amount' => 5000]);
$did = (string) $req->data['decision_id'];
chk('the maker cannot reject their own request either', ! $s['decisions']->reject(ORG, $did, 'u-chair')->ok);
chk('rejecting needs the same authority as approving', $s['decisions']->reject(ORG, $did, 'u-stranger')->code === 'OUTSIDE_SCOPE');
$rejected = $s['decisions']->reject(ORG, $did, 'u-leader', 'not this year');
chk('the leader rejects with a reason', $rejected->ok && (string) rowById($s['db'], 'event_committee_decisions', $did)['status'] === 'rejected');
chk('the reason is stored for the maker', (string) rowById($s['db'], 'event_committee_decisions', $did)['decision_note'] === 'not this year');

$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'budget_approval_threshold' => 1000]]]);
$req = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Pay the band', 'amount' => 5000]);
$did = (string) $req->data['decision_id'];
$byStranger = $s['decisions']->cancel(ORG, $did, 'u-stranger');
chk('a stranger cannot withdraw somebody else\'s request', ! $byStranger->ok && $byStranger->code === 'NOT_AUTHORIZED');
$byMaker = $s['decisions']->cancel(ORG, $did, 'u-chair', 'found a cheaper band');
chk('the maker may withdraw their own request', $byMaker->ok && (string) rowById($s['db'], 'event_committee_decisions', $did)['status'] === 'cancelled');

// NO expiry: an ancient request is still pending, and still decidable.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'budget_approval_threshold' => 1000]]]);
$req = $s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Pay the band', 'amount' => 5000]);
$did = (string) $req->data['decision_id'];
$s['db']->rows['event_committee_decisions'][0]['created_at'] = '2025-08-01 09:00:00';
$queue = $s['decisions']->queue(ORG, ['status' => 'pending']);
chk('a 400-day-old request is still in the queue (no expiry)', count($queue) === 1 && (string) $queue[0]['status'] === 'pending');
chk('…and can still be approved', $s['decisions']->approve(ORG, $did, 'u-leader')->ok);

// Queue decoration + the read bound.
$s = formedCommittee(['config' => ['g-local|event_committee' => ['enabled' => true, 'budget_approval_threshold' => 1000]]]);
$s['decisions']->request(ORG, 'ev-1', 'u-chair', ['kind' => 'budget', 'title' => 'Pay the band', 'amount' => 5000]);
$rows = $s['decisions']->queue(ORG, ['status' => 'pending']);
chk('the queue carries the event title', (string) ($rows[0]['event_title'] ?? '') === 'Convention 2026');
chk('the queue carries the event status', (string) ($rows[0]['event_status'] ?? '') === 'published');
chk('the queue carries the requester\'s name', (string) ($rows[0]['requested_by_name'] ?? '') === 'Chair Person');
chk('the queue carries the effect vocabulary', array_key_exists('effect', $rows[0]));
$bounded = $s['decisions']->queue(ORG, ['status' => 'pending', 'group_ids' => ['g-other']]);
chk('the queue is bounded to the groups passed in', $bounded === []);
$inScope = $s['decisions']->queue(ORG, ['status' => 'pending', 'group_ids' => ['g-local']]);
chk('…and shows rows inside them', count($inScope) === 1);
chk('a settled filter returns nothing pending', $s['decisions']->queue(ORG, ['status' => 'approved']) === []);
chk('pendingForOversight lists what awaits this actor', count($s['decisions']->pendingForOversight(ORG, 'u-leader')) === 1);
chk('countPending agrees with the queue', $s['decisions']->countPending(ORG, 'g-local') === 1);

// ── 7. The work engine ────────────────────────────────────────────────────────
echo "\nwork engine: rows, dependencies, roll-ups\n";
$s = formedCommittee();
$work = $s['work'];
$ws = $work->createWorkstream(ORG, 'ev-1', 'u-chair', ['name' => 'Venue', 'owner_user_id' => 'u-chair', 'responsibility' => 'logistics', 'weight' => 2, 'due_date' => '2026-09-28']);
chk('a workstream is created', $ws->ok && $ws->status === 201, (string) ($ws->message ?? ''));
$wsId = (string) $ws->data['workstream_id'];
chk('the workstream is stored with its weight', (int) rowById($s['db'], 'event_workstreams', $wsId)['weight'] === 2);
$noName = $work->createWorkstream(ORG, 'ev-1', 'u-chair', ['name' => '  ']);
chk('a workstream needs a name', ! $noName->ok && $noName->code === 'NAME_REQUIRED', (string) $noName->code);

$other = $work->createWorkstream(ORG, 'ev-wide', 'u-leader', ['name' => 'Elsewhere']);
chk('a workstream on a gated-off event is refused', ! $other->ok, (string) $other->code);
$mismatch = $work->createTask(ORG, 'ev-1', 'u-chair', ['title' => 'Cross-event', 'workstream_id' => 'ws-elsewhere']);
chk('a task cannot borrow another event\'s workstream', ! $mismatch->ok, (string) $mismatch->code);

$t1 = $work->createTask(ORG, 'ev-1', 'u-chair', ['title' => 'Book the hall', 'workstream_id' => $wsId, 'assignee_user_id' => 'u-fin', 'due_at' => '2026-09-25', 'priority' => 'high']);
$t2 = $work->createTask(ORG, 'ev-1', 'u-chair', ['title' => 'Pay the deposit', 'workstream_id' => $wsId, 'assignee_user_id' => 'u-fin', 'due_at' => '2026-09-27']);
$t3 = $work->createTask(ORG, 'ev-1', 'u-chair', ['title' => 'Print programmes', 'due_at' => '2026-09-30']);
chk('tasks are created', $t1->ok && $t2->ok && $t3->ok, (string) ($t1->message ?? ''));
chk('a new task starts as todo', (string) rowById($s['db'], 'event_tasks', (string) $t1->data['task_id'])['status'] === 'todo');
chk('tasks keep their sort order', (int) rowById($s['db'], 'event_tasks', (string) $t3->data['task_id'])['sort_order'] > 0);
$noTitle = $work->createTask(ORG, 'ev-1', 'u-chair', ['title' => '']);
chk('a task needs a title', ! $noTitle->ok && $noTitle->code === 'TITLE_REQUIRED', (string) $noTitle->code);

$id1 = (string) $t1->data['task_id'];
$id2 = (string) $t2->data['task_id'];
$id3 = (string) $t3->data['task_id'];

$dep = $work->addDependency(ORG, $id2, $id1, 'u-chair');
chk('a dependency is added', $dep->ok, (string) ($dep->message ?? ''));
$selfDep = $work->addDependency(ORG, $id1, $id1, 'u-chair');
chk('a task cannot wait on itself', ! $selfDep->ok && $selfDep->code === 'SELF_DEPENDENCY', (string) $selfDep->code);
$cycle = $work->addDependency(ORG, $id1, $id2, 'u-chair');
chk('a cycle is refused before the edge is written', ! $cycle->ok && $cycle->code === 'DEPENDENCY_CYCLE', (string) $cycle->code);
chk('…and no second edge exists', count($s['db']->rows['event_task_dependencies']) === 1);
$dupDep = $work->addDependency(ORG, $id2, $id1, 'u-chair');
chk('re-adding the same edge is a no-op', $dupDep->ok && ! empty($dupDep->meta['deduplicated']) && count($s['db']->rows['event_task_dependencies']) === 1);

$early = $work->completeTask(ORG, $id2, 'u-chair');
chk('a gated task cannot be finished early', ! $early->ok && $early->code === 'PREDECESSOR_OPEN', (string) $early->code);
chk('the task is still open', (string) rowById($s['db'], 'event_tasks', $id2)['status'] !== 'done');
$forced = $work->completeTask(ORG, $id2, 'u-chair', true);
chk('forcing overrides the dependency', $forced->ok && (string) rowById($s['db'], 'event_tasks', $id2)['status'] === 'done');
$noReason = $work->blockTask(ORG, $id1, 'u-chair', '   ');
chk('blocking needs a reason', ! $noReason->ok && $noReason->code === 'BLOCK_REASON_REQUIRED', (string) $noReason->code);
$blocked = $work->blockTask(ORG, $id1, 'u-chair', 'venue not confirmed');
chk('a task can be blocked with a reason', $blocked->ok && (string) rowById($s['db'], 'event_tasks', $id1)['status'] === 'blocked');
chk('the reason is stored', (string) rowById($s['db'], 'event_tasks', $id1)['blocked_reason'] === 'venue not confirmed');
$illegal = $work->updateTask(ORG, $id1, 'u-chair', ['status' => 'done']);
chk('blocked → done is refused (unblock first)', ! $illegal->ok && $illegal->code === 'BAD_TRANSITION', (string) $illegal->code);
chk('unblocking restores the work', $work->unblockTask(ORG, $id1, 'u-chair')->ok && (string) rowById($s['db'], 'event_tasks', $id1)['status'] === 'in_progress');
chk('finishing now works', $work->completeTask(ORG, $id1, 'u-chair')->ok);
chk('a finished task is 100%', (int) rowById($s['db'], 'event_tasks', $id1)['progress_pct'] === 100);
chk('completion is attributed', (string) rowById($s['db'], 'event_tasks', $id1)['completed_by'] === 'u-chair');
chk('cancelled work leaves the roll-up', $work->cancelTask(ORG, $id3, 'u-chair', 'not needed')->ok
    && (string) rowById($s['db'], 'event_tasks', $id3)['status'] === 'cancelled');

// Derived roll-ups on the workstream row.
$stream = rowById($s['db'], 'event_workstreams', $wsId);
chk('the workstream rolled up its tasks', (int) $stream['task_count'] === 2 && (int) $stream['done_count'] === 2, json_encode([$stream['task_count'] ?? null, $stream['done_count'] ?? null]));
chk('the workstream status is derived (all done)', (string) $stream['status'] === 'done', (string) $stream['status']);
chk('the workstream progress is derived', (int) $stream['progress_pct'] === 100);

// Milestones.
$noDate = $work->createMilestone(ORG, 'ev-1', 'u-chair', ['title' => 'Contracts signed']);
chk('a milestone needs a date', ! $noDate->ok && $noDate->code === 'DUE_DATE_REQUIRED', (string) $noDate->code);
$ms = $work->createMilestone(ORG, 'ev-1', 'u-chair', ['title' => 'Contracts signed', 'due_at' => '2026-09-28', 'weight' => 3]);
chk('a milestone is created', $ms->ok, (string) ($ms->message ?? ''));
$msId = (string) $ms->data['milestone_id'];
chk('it starts pending', (string) rowById($s['db'], 'event_milestones', $msId)['status'] === 'pending');
$met = $work->meetMilestone(ORG, $msId, 'u-chair', 'signed and scanned');
chk('a milestone can be met with evidence', $met->ok && (string) rowById($s['db'], 'event_milestones', $msId)['status'] === 'met');
chk('the evidence and the moment are stored', (string) rowById($s['db'], 'event_milestones', $msId)['evidence'] === 'signed and scanned'
    && ! empty(rowById($s['db'], 'event_milestones', $msId)['met_at']));
chk('a met milestone can be reopened', $work->reopenMilestone(ORG, $msId, 'u-chair')->ok
    && (string) rowById($s['db'], 'event_milestones', $msId)['status'] === 'pending');
chk('a milestone can be marked missed', $work->missMilestone(ORG, $msId, 'u-chair', 'vendor pulled out')->ok
    && (string) rowById($s['db'], 'event_milestones', $msId)['status'] === 'missed');

// Deleting a workstream that still has work.
$ws2 = $work->createWorkstream(ORG, 'ev-1', 'u-chair', ['name' => 'Comms']);
$ws2Id = (string) $ws2->data['workstream_id'];
$work->createTask(ORG, 'ev-1', 'u-chair', ['title' => 'Post the flyer', 'workstream_id' => $ws2Id]);
$refused = $work->deleteWorkstream(ORG, $ws2Id, 'u-chair');
chk('a workstream with tasks is not silently deleted', ! $refused->ok && $refused->code === 'WORKSTREAM_NOT_EMPTY', (string) $refused->code);
$moved = $work->deleteWorkstream(ORG, $ws2Id, 'u-chair', true);
chk('…unless its tasks are reassigned', $moved->ok);
$orphan = null;
foreach ($s['db']->rows['event_tasks'] as $t) {
    if ((string) $t['title'] === 'Post the flyer') {
        $orphan = $t;
    }
}
chk('the tasks survive with no workstream', $orphan !== null && array_key_exists('workstream_id', $orphan) && $orphan['workstream_id'] === null);
chk('the workstream row is gone', rowById($s['db'], 'event_workstreams', $ws2Id) === null);

// Reads: plan, board, my tasks, ids.
$plan = $work->plan(ORG, 'ev-1');
chk('the plan lists workstreams, tasks and milestones', count($plan['workstreams']) >= 1 && count($plan['tasks']) === 4 && count($plan['milestones']) === 1, json_encode([count($plan['workstreams']), count($plan['tasks']), count($plan['milestones'])]));
chk('tasks with no workstream are bucketed separately', count($plan['unstreamed']) === 2, (string) count($plan['unstreamed']));
chk('the plan carries a derived percentage', is_int($plan['progress_pct']));
chk('the plan carries a health verdict', in_array($plan['health'], ['on_track', 'at_risk', 'behind', 'blocked', 'complete'], true), (string) $plan['health']);
chk('the plan flags what needs attention', isset($plan['attention']['counts']['late'], $plan['attention']['counts']['blocked']));
chk('the plan knows today', (string) $plan['today'] === '2026-09-21');
$annotated = null;
foreach ($plan['tasks'] as $t) {
    if ((string) $t['id'] === $id2) {
        $annotated = $t;
    }
}
chk('each task carries completion + risk', isset($annotated['completion'], $annotated['risk']));
chk('a finished task reads 100% and no risk', (int) $annotated['completion'] === 100 && (string) $annotated['risk'] === 'ok');
chk('predecessors are annotated', array_key_exists('predecessors', $annotated));

$board = $work->board(ORG, 'ev-1');
chk('the board has the five columns', count($board) === 5 && isset($board['todo'], $board['in_progress'], $board['blocked'], $board['done'], $board['cancelled']));
chk('finished work is in the done column', count(array_filter($board['done'], static fn (array $t): bool => (string) $t['id'] === $id1)) === 1);
chk('cancelled work is in the cancelled column', count($board['cancelled']) === 1);

chk('finished work drops off my open list', $work->myTasks(ORG, 'u-fin') === []);
$mineAll = $work->myTasks(ORG, 'u-fin', true);
chk('includeDone shows the history, and only mine', count($mineAll) === 2
    && count(array_filter($mineAll, static fn (array $t): bool => (string) ($t['assignee_user_id'] ?? '') !== 'u-fin')) === 0, (string) count($mineAll));
chk('a stranger has no tasks', $work->myTasks(ORG, 'u-stranger') === []);

chk('eventIdFor resolves a task', $work->eventIdFor(ORG, $id1) === 'ev-1');
chk('eventIdFor resolves a workstream', $work->eventIdFor(ORG, $wsId) === 'ev-1');
chk('eventIdFor resolves a milestone', $work->eventIdFor(ORG, $msId) === 'ev-1');
chk('eventIdFor returns null for an unknown row', $work->eventIdFor(ORG, 'nope') === null);

chk('the work engine needs the capability but NOT a committee', (function (): bool {
    $s = svc(['config' => ['g-local|event_committee' => ['enabled' => true]], 'grants' => [['u-leader', 'event.logistics.manage', 'g-local']]]);

    return $s['work']->createTask(ORG, 'ev-1', 'u-leader', ['title' => 'Plan without a committee'])->ok;
})());

// ── 8. Reads the consoles depend on ───────────────────────────────────────────
echo "\nconsole reads\n";
$s = formedCommittee();
$found = $s['committees']->find(ORG, 'ev-1');
chk('find() returns the decorated committee', is_array($found) && (string) $found['event_title'] === 'Convention 2026');
chk('it carries the chair\'s name', (string) ($found['chair_name'] ?? '') === 'Chair Person');
chk('it carries its members with their names', count($found['members']) === 2 && (string) $found['members'][0]['user_name'] !== '');
chk('it counts active members', (int) $found['member_count'] === 2);
chk('it counts pending decisions', array_key_exists('pending_decisions', $found));
chk('it reports which lanes are covered', isset($found['responsibilities']['filled'], $found['responsibilities']['vacant']));
chk('the finance lane is filled', in_array('finance', $found['responsibilities']['filled'], true));
chk('the media lane is vacant', in_array('media', $found['responsibilities']['vacant'], true));
chk('it carries the resolved config', isset($found['config']['oversight']));
chk('find() on an event with no committee is null', $s['committees']->find(ORG, 'ev-wide') === null);
chk('isMember says who sits on it', $s['committees']->isMember(ORG, 'ev-1', 'u-fin') === true && $s['committees']->isMember(ORG, 'ev-1', 'u-stranger') === false);
chk('isChair says who chairs it', $s['committees']->isChair(ORG, 'ev-1', 'u-chair') === true && $s['committees']->isChair(ORG, 'ev-1', 'u-fin') === false);

$names = $s['committees']->displayNames(ORG, ['u-chair', 'u-fin', 'u-chair', '']);
chk('displayNames resolves in one pass, deduped', $names === ['u-chair' => 'Chair Person', 'u-fin' => 'Finance Lead'], json_encode($names));
chk('displayNames of nobody is empty', $s['committees']->displayNames(ORG, []) === []);

$mine = $s['committees']->committeesForUser(ORG, 'u-fin');
chk('committeesForUser lists my seats with event context', count($mine) === 1 && (string) $mine[0]['event_title'] === 'Convention 2026');
chk('my seat knows its lane', (string) $mine[0]['responsibility'] === 'finance');
chk('a stranger sits on nothing', $s['committees']->committeesForUser(ORG, 'u-stranger') === []);

$choices = $s['committees']->oversightChoices(ORG, 'g-local');
chk('oversight choices include the event group and its ancestors', $choices !== [] && in_array('g-local', array_column($choices, 'id'), true)
    && in_array('g-area', array_column($choices, 'id'), true), json_encode(array_column($choices, 'id')));

$memberId = (string) (function ($db) {
    foreach ($db->rows['event_committee_members'] as $m) {
        if ((string) $m['user_id'] === 'u-fin') {
            return $m['id'];
        }
    }

    return '';
})($s['db']);
chk('eventIdForMember maps a seat back to its event', $s['committees']->eventIdForMember(ORG, $memberId) === 'ev-1');
chk('eventIdForMember of an unknown seat is null', $s['committees']->eventIdForMember(ORG, 'nope') === null);

$scopeGroups = $s['committees']->scopeGroupsForUser(ORG, 'u-scoped');
chk('a scoped actor\'s read bound is their group', $scopeGroups === ['g-local'], json_encode($scopeGroups));
chk('an actor with no grants has an empty read bound', $s['committees']->scopeGroupsForUser(ORG, 'u-stranger') === []);
chk('scopeCovers is false outside the bound', $s['committees']->scopeCovers(ORG, 'u-stranger', 'g-local') === false);

Clock::freeze(null);

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);

}
