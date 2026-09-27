<?php

declare(strict_types=1);

/**
 * EVENT CLOSE AUTOMATION — auto-complete finished events (gap L3).
 *
 * A published event that has ended used to sit in `published` forever unless an
 * organizer pressed "Complete", leaving the attendance/mobilization reports and
 * certificate flows keyed off a stale status. L3 closes that. This test proves:
 *
 *   - complete() now stamps completed_at and stages ONE `event.completed`
 *     domain event (idempotent: a re-complete short-circuits and stages nothing);
 *   - it still records completed / completed_no_attendance from real attendance;
 *   - EventCloser sweeps finished published events and closes them through the
 *     SAME complete() path — never a fork;
 *   - the sweep is HIERARCHICAL-CONFIG gated, DEFAULT OFF (gated-off orgs are
 *     skipped, not closed), resolving the gate ONCE per event;
 *   - the grace window is honoured (a just-finished event is left alone);
 *   - the sweep is idempotent (a re-run closes nothing already terminal);
 *   - the command, Services factory, migration and JobRouter topic are wired.
 *
 *   php app/Modules/Events/Services/tests/event_close_automation_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows) {}
        public function getRowArray() { return $this->rows[0] ?? null; }
        public function getResultArray() { return $this->rows; }
    }

    class QB
    {
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($s) { return $this; }
        public function where($k, $v = null, $escape = true) { $this->eq[trim((string) $k)] = $v; return $this; }
        public function whereIn($k, array $v) { return $this; }
        public function orderBy($k, $d = 'ASC', $e = true) { return $this; }
        public function limit($n) { return $this; }

        public function get($limit = null): RS { return new RS($this->rowsFor()); }

        public function countAllResults(): int { return count($this->rowsFor()); }

        public function update(array $data): bool
        {
            foreach ($this->db->rows[$this->t] ?? [] as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $data);
                }
            }

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) { return false; }
            }

            return true;
        }

        private function rowsFor(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }
    }
}

namespace WBS\Shared\Messaging {
    class OutboxService
    {
        /** @var list<array{type:string,id:string,topic:string,payload:array,org:?string}> */
        public array $staged = [];

        public function stage(string $aggregateType, string $aggregateId, string $topic, array $payload, ?string $organizationId = null, array $headers = []): string
        {
            $this->staged[] = ['type' => $aggregateType, 'id' => $aggregateId, 'topic' => $topic, 'payload' => $payload, 'org' => $organizationId];

            return 'outbox-' . count($this->staged);
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Events/Services/EventRosterPort.php';
require_once $root . '/app/Modules/Events/Services/EventConfigPort.php';
require_once $root . '/app/Modules/Events/Services/OrderRefundService.php';
require_once $root . '/app/Modules/Events/Services/EventService.php';
require_once $root . '/app/Modules/Events/Services/EventCloser.php';

use WBS\Events\Services\EventCloser;
use WBS\Events\Services\EventConfigPort;
use WBS\Events\Services\EventRosterPort;
use WBS\Events\Services\EventService;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

Clock::freeze(new DateTimeImmutable('2026-09-14 12:00:00', new DateTimeZone('UTC')));
$clock = new Clock();

// Roster port stub: dueForClose returns whatever we queue; orgRootGroup fixed.
$rosterPort = new class implements EventRosterPort {
    /** @var list<array<string,mixed>> */
    public array $due = [];
    public ?string $rootGroup = 'g-root';

    public function activeRegistrants(string $eventId, array $statuses = ['registered']): array { return []; }
    public function dueForReminder(?string $o, string $a, string $b, int $l): array { return []; }
    public function markReminded(string $eventId, string $at): void {}
    public function dueForClose(?string $organizationId, string $finishedByUtc, int $limit): array { return $this->due; }
    public function orgRootGroup(string $organizationId): ?string { return $this->rootGroup; }
};

$configOn  = new class implements EventConfigPort {
    public int $calls = 0;
    public function value(string $groupId, string $capability): mixed { $this->calls++; return true; }
};
$configOff = new class implements EventConfigPort {
    public function value(string $groupId, string $capability): mixed { return null; }
};

// Build a DB seeded with two finished published events (one attended, one not).
function seedDb(): \CodeIgniter\Database\BaseConnection
{
    $db = new \CodeIgniter\Database\BaseConnection();
    $db->rows['events'] = [
        ['id' => 'ev-att', 'organization_id' => 'org-1', 'group_id' => 'g-1', 'status' => 'published', 'starts_at' => '2026-09-10 09:00:00', 'ends_at' => '2026-09-10 11:00:00'],
        ['id' => 'ev-empty', 'organization_id' => 'org-1', 'group_id' => null, 'status' => 'published', 'starts_at' => '2026-09-11 09:00:00', 'ends_at' => '2026-09-11 11:00:00'],
    ];
    $db->rows['event_attendance'] = [
        ['event_id' => 'ev-att', 'status' => 'present'],
    ];

    return $db;
}

// ── 1. complete() domain event + audit stamp ─────────────────────────────────
echo "complete(): domain event + audit\n";
$db  = seedDb();
$ob  = new OutboxService();
$svc = new EventService($db, $clock, null, null, $ob);

$r = $svc->complete('ev-att');
chk('a published event completes', $r->ok && $r->data['status'] === 'completed');
$row = null; foreach ($db->rows['events'] as $e) { if ($e['id'] === 'ev-att') { $row = $e; } }
chk('completed_at is stamped', ($row['completed_at'] ?? '') === '2026-09-14 12:00:00');
chk('exactly one event.completed staged', count($ob->staged) === 1 && $ob->staged[0]['topic'] === 'event.completed');
chk('domain event carries status + attendance', ($ob->staged[0]['payload']['status'] ?? '') === 'completed' && ($ob->staged[0]['payload']['actual_attendance'] ?? -1) === 1);
chk('domain event aggregate is the event', $ob->staged[0]['type'] === 'event' && $ob->staged[0]['id'] === 'ev-att');

echo "complete(): idempotency + zero-attendance\n";
$r2 = $svc->complete('ev-att');
chk('re-completing is deduplicated', $r2->ok && ($r2->meta['deduplicated'] ?? false));
chk('re-completing stages NO second domain event', count($ob->staged) === 1);
$re = $svc->complete('ev-empty');
chk('zero-attendance → completed_no_attendance', $re->ok && $re->data['status'] === 'completed_no_attendance');
chk('a second (distinct) completion stages its own event', count($ob->staged) === 2 && $ob->staged[1]['payload']['status'] === 'completed_no_attendance');
chk('org-wide event carries null group_id', $ob->staged[1]['payload']['group_id'] === null);

// ── 2. EventCloser: config gate (default OFF) ────────────────────────────────
echo "EventCloser: gate default OFF\n";
$db = seedDb();
$svc = new EventService($db, $clock, null, null, new OutboxService());
$rosterPort->due = [
    ['id' => 'ev-att', 'organization_id' => 'org-1', 'group_id' => 'g-1'],
    ['id' => 'ev-empty', 'organization_id' => 'org-1', 'group_id' => null],
];
$closerOff = new EventCloser($clock, $svc, $rosterPort, $configOff);
$out = $closerOff->processDueClosures('org-1');
chk('gated-off: nothing is completed', $out['completed'] === 0);
chk('gated-off: events are counted as skipped', $out['skipped_gated'] === 2);
chk('gated-off: the events stay published', $db->rows['events'][0]['status'] === 'published' && $db->rows['events'][1]['status'] === 'published');

echo "EventCloser: no config port at all is OFF\n";
$closerNone = new EventCloser($clock, $svc, $rosterPort, null);
chk('no config port → all skipped', $closerNone->processDueClosures('org-1')['skipped_gated'] === 2);

// ── 3. EventCloser: gate ON closes through complete() ─────────────────────────
echo "EventCloser: gate ON\n";
$db  = seedDb();
$ob  = new OutboxService();
$svc = new EventService($db, $clock, null, null, $ob);
$configOn2 = new class implements EventConfigPort {
    public int $calls = 0;
    public function value(string $groupId, string $capability): mixed { $this->calls++; return true; }
};
$closerOn = new EventCloser($clock, $svc, $rosterPort, $configOn2);
$out = $closerOn->processDueClosures('org-1');
chk('gate ON: both finished events auto-complete', $out['completed'] === 2, 'got ' . $out['completed']);
chk('gate ON: scanned counts every due event', $out['scanned'] === 2);
chk('gate ON: no-attendance tally is correct', $out['no_attendance'] === 1);
chk('gate ON: events are now terminal', $db->rows['events'][0]['status'] === 'completed' && $db->rows['events'][1]['status'] === 'completed_no_attendance');
chk('gate ON: close routes through complete() → domain events staged', count($ob->staged) === 2);
chk('gate is resolved ONCE per event (not per recipient)', $configOn2->calls === 2, 'calls=' . $configOn2->calls);

echo "EventCloser: idempotent re-run\n";
$out2 = $closerOn->processDueClosures('org-1');
chk('a re-run completes nothing already terminal', $out2['completed'] === 0);
chk('a re-run stages no new domain events', count($ob->staged) === 2);

// ── 4. Grace window (uses the real dueForClose finish cutoff) ─────────────────
echo "grace window\n";
$captured = null;
$rosterCapture = new class($captured) implements EventRosterPort {
    public string $finishedBy = '';
    public function activeRegistrants(string $e, array $s = ['registered']): array { return []; }
    public function dueForReminder(?string $o, string $a, string $b, int $l): array { return []; }
    public function markReminded(string $e, string $at): void {}
    public function dueForClose(?string $o, string $finishedByUtc, int $limit): array { $this->finishedBy = $finishedByUtc; return []; }
    public function orgRootGroup(string $o): ?string { return 'g-root'; }
};
$svc = new EventService(seedDb(), $clock, null, null, new OutboxService());
(new EventCloser($clock, $svc, $rosterCapture, $configOff))->processDueClosures('org-1', 6);
chk('grace subtracts the window from now (12:00 - 6h = 06:00)', $rosterCapture->finishedBy === '2026-09-14 06:00:00', 'got ' . $rosterCapture->finishedBy);
(new EventCloser($clock, $svc, $rosterCapture, $configOff))->processDueClosures('org-1', 0);
chk('grace of 0 closes anything finished by now', $rosterCapture->finishedBy === '2026-09-14 12:00:00');

// ── 5. Wiring (source inspection) ────────────────────────────────────────────
echo "wiring\n";
$svcSrc = (string) file_get_contents($root . '/app/Modules/Events/Services/EventService.php');
chk('complete() stamps completed_at', (bool) preg_match("/function complete\\(.*?'completed_at'/s", $svcSrc));
chk('complete() stages event.completed', (bool) preg_match("/function complete\\(.*?'event\\.completed'/s", $svcSrc));
chk('EventService has an optional outbox seam', str_contains($svcSrc, '?OutboxService $outbox = null'));

$closerSrc = (string) file_get_contents($root . '/app/Modules/Events/Services/EventCloser.php');
chk('EventCloser gate capability is events.autoclose.enabled', str_contains($closerSrc, "events.autoclose.enabled"));
chk('EventCloser reuses complete() (no fork)', str_contains($closerSrc, '$this->events->complete('));

$portSrc = (string) file_get_contents($root . '/app/Modules/Events/Services/EventRosterDbAdapter.php');
chk('dueForClose is one indexed range read (COALESCE ends/starts)', str_contains($portSrc, 'COALESCE(ends_at, starts_at) <='));

$cmd = (string) file_get_contents($root . '/app/Modules/Events/Commands/EventCloseDueCommand.php');
chk('command is events:close-due', str_contains($cmd, "events:close-due"));
chk('command drives EventCloser', str_contains($cmd, 'eventCloser()') && str_contains($cmd, 'processDueClosures'));

$factory = (string) file_get_contents($root . '/app/Modules/Events/Config/Services.php');
chk('Services has an eventCloser() factory', str_contains($factory, 'function eventCloser('));
chk('events() factory injects the outbox', (bool) preg_match('/new EventService\(.*SharedServices::outbox\(false\)/s', $factory));

$mig = glob($root . '/app/Modules/Events/Database/Migrations/*AddEventCompletionAudit.php');
chk('completion-audit migration exists', $mig !== []);
$migSrc = $mig !== [] ? (string) file_get_contents($mig[0]) : '';
chk('migration adds completed_at', str_contains($migSrc, 'completed_at'));
chk('migration adds the close sweep index', str_contains($migSrc, 'ev_close_idx'));

$router = (string) file_get_contents($root . '/app/Modules/Shared/Messaging/JobRouter.php');
chk('JobRouter recognises event.completed (no unknown-topic warning)', str_contains($router, "'event.completed'"));
chk('JobRouter has an eventCompleted handler', str_contains($router, 'function eventCompleted('));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
