<?php

declare(strict_types=1);

/**
 * EVENT ROSTER-NOTIFICATIONS wiring + behaviour test (gap G3).
 *
 * G3 completes G7's notify side and adds pre-event reminders, reusing the
 * Notifications module (idempotent send + outbox) — nothing forked. This test:
 *
 *   1. Exercises EventNotifier directly with in-memory ports (roster/sender/config)
 *      — proving: DEFAULT-OFF feature gate; cancel + material-update fan-out to
 *      registered+waitlisted; cosmetic edits stay silent; reminder sweep respects
 *      the watermark + confirmed-only audience; dedupe keys are stable/distinct;
 *      the gate is resolved via the org-root group for org-wide events.
 *   2. Source-inspects EventService cancel()/update() wiring + the factory + the
 *      spark command + the migration.
 *   3. Asserts i18n parity for the new Events.notify.* keys across all 6 locales.
 *
 *   php app/Modules/Events/Services/tests/event_notifications_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';

require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Events/Services/EventRosterPort.php';
require_once $root . '/app/Modules/Events/Services/EventConfigPort.php';
require_once $root . '/app/Modules/Events/Services/EventNotifierPort.php';
require_once $root . '/app/Modules/Events/Services/NotificationSenderPort.php';
require_once $root . '/app/Modules/Events/Services/EventNotifier.php';

use WBS\Events\Services\EventConfigPort;
use WBS\Events\Services\EventNotifier;
use WBS\Events\Services\EventRosterPort;
use WBS\Events\Services\NotificationSenderPort;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};

// ── In-memory ports ──────────────────────────────────────────────────────────
$rosterPort = new class implements EventRosterPort {
    /** @var array<string,list<array{user_id:string}>> */
    public array $rosters = [];
    /** @var list<array<string,mixed>> */
    public array $due = [];
    /** @var list<array{event_id:string,at:string}> */
    public array $reminded = [];
    public ?string $rootGroup = null;

    public function activeRegistrants(string $eventId, array $statuses = ['registered']): array
    {
        $out = [];
        foreach ($this->rosters[$eventId] ?? [] as $r) {
            if (in_array($r['status'] ?? 'registered', $statuses, true)) {
                $out[] = ['user_id' => $r['user_id'], 'group_attribution' => null];
            }
        }

        return $out;
    }

    public function dueForReminder(?string $organizationId, string $notBeforeUtc, string $notAfterUtc, int $limit): array
    {
        return $this->due;
    }

    public function markReminded(string $eventId, string $at): void
    {
        $this->reminded[] = ['event_id' => $eventId, 'at' => $at];
    }

    public function dueForClose(?string $organizationId, string $finishedByUtc, int $limit): array
    {
        return [];
    }

    public function orgRootGroup(string $organizationId): ?string
    {
        return $this->rootGroup;
    }
};

$senderSpy = new class implements NotificationSenderPort {
    /** @var list<array{org:string,user:string,channel:string,category:string,opts:array<string,mixed>}> */
    public array $sent = [];

    public function send(string $organizationId, string $userId, string $channel, string $category, array $opts = []): void
    {
        $this->sent[] = ['org' => $organizationId, 'user' => $userId, 'channel' => $channel, 'category' => $category, 'opts' => $opts];
    }
};

$configOn  = new class implements EventConfigPort {
    public function value(string $groupId, string $capability): mixed { return true; }
};
$configOff = new class implements EventConfigPort {
    public function value(string $groupId, string $capability): mixed { return null; }
};

Clock::freeze(new DateTimeImmutable('2026-09-14 00:00:00', new DateTimeZone('UTC')));
$clock = new Clock();

$mkNotifier = static function ($roster, $sender, $config) use ($clock): EventNotifier {
    return new EventNotifier($clock, $roster, $sender, $config);
};

$event = [
    'id' => 'ev-1', 'organization_id' => 'org-1', 'group_id' => 'grp-1',
    'title' => 'Conf', 'starts_at' => '2026-10-01 10:00:00', 'timezone' => 'UTC', 'mode' => 'physical',
];

// ── 1. Feature gate DEFAULT OFF ──────────────────────────────────────────────
echo "feature gate (default off)\n";
$rosterPort->rosters['ev-1'] = [['user_id' => 'u1', 'status' => 'registered'], ['user_id' => 'u2', 'status' => 'waitlisted']];

$senderSpy->sent = [];
$n = $mkNotifier($rosterPort, $senderSpy, $configOff);
$count = $n->notifyCancelled($event, '2026-09-14 00:00:00');
chk('cancel is a NO-OP when the gate is off', $count === 0 && $senderSpy->sent === []);

$senderSpy->sent = [];
$n2 = new EventNotifier($clock, $rosterPort, null, $configOn); // no sender wired
chk('no-op when the sender is not wired', $n2->notifyCancelled($event, '2026-09-14 00:00:00') === 0);

$n3 = new EventNotifier($clock, $rosterPort, $senderSpy, null); // no config wired
chk('no-op when config is not wired', $n3->notifyCancelled($event, '2026-09-14 00:00:00') === 0);

// ── 2. Cancel fan-out (gate on) ──────────────────────────────────────────────
echo "cancel fan-out (gate on)\n";
$senderSpy->sent = [];
$on   = $mkNotifier($rosterPort, $senderSpy, $configOn);
$ev   = $event + ['cancellation_reason' => 'Venue lost'];
$sent = $on->notifyCancelled($ev, '2026-09-14 00:00:00');
chk('cancel notifies registered + waitlisted (2)', $sent === 2 && count($senderSpy->sent) === 2);
chk('cancel uses the event_cancelled category', $senderSpy->sent[0]['category'] === 'event_cancelled');
chk('cancel is high priority', ($senderSpy->sent[0]['opts']['priority'] ?? '') === 'high');
chk('cancel context carries the reason (not raw ids)', ($senderSpy->sent[0]['opts']['context']['reason'] ?? '') === 'Venue lost');
chk('cancel dedupe key is per (event,user,instant)', ($senderSpy->sent[0]['opts']['dedupe_key'] ?? '') === 'event_cancelled:ev-1:u1:2026-09-14 00:00:00');
chk('each recipient gets a distinct dedupe key', $senderSpy->sent[0]['opts']['dedupe_key'] !== $senderSpy->sent[1]['opts']['dedupe_key']);

// ── 3. Update fan-out: material vs cosmetic ──────────────────────────────────
echo "update fan-out (material vs cosmetic)\n";
$senderSpy->sent = [];
$m = $on->notifyUpdated($event, ['title', 'description'], '2026-09-14 00:00:00');
chk('a cosmetic-only edit (title/description) is SILENT', $m === 0 && $senderSpy->sent === []);

$senderSpy->sent = [];
$m2 = $on->notifyUpdated($event, ['starts_at'], '2026-09-14 00:00:00');
chk('a start-time change notifies the roster (2)', $m2 === 2 && count($senderSpy->sent) === 2);
chk('update uses the event_updated category', $senderSpy->sent[0]['category'] === 'event_updated');
chk('update context lists what changed', ($senderSpy->sent[0]['opts']['context']['changed'] ?? '') === 'starts_at');

$senderSpy->sent = [];
$m3 = $on->notifyUpdated($event, ['venue_id', 'access_url', 'mode'], '2026-09-14 00:00:00');
chk('venue/access/mode changes are material', $m3 === 2);

// distinct edits (different stamp) produce different dedupe keys
$senderSpy->sent = [];
$on->notifyUpdated($event, ['starts_at'], '2026-09-14 00:00:00');
$k1 = $senderSpy->sent[0]['opts']['dedupe_key'];
$senderSpy->sent = [];
$on->notifyUpdated($event, ['starts_at'], '2026-09-15 09:00:00');
$k2 = $senderSpy->sent[0]['opts']['dedupe_key'];
chk('a later distinct edit gets a fresh dedupe key', $k1 !== $k2);

// ── 3b. G8 promotion fan-out: only the promoted users, gated + deduped ────────
echo "promotion fan-out (G8)\n";
$senderSpy->sent = [];
$p0 = $on->notifyPromoted($event, [], '2026-09-14 00:00:00');
chk('no promoted users → silent', $p0 === 0 && $senderSpy->sent === []);

$senderSpy->sent = [];
$pOff = $mkNotifier($rosterPort, $senderSpy, $configOff)->notifyPromoted($event, ['u2'], '2026-09-14 00:00:00');
chk('promotion is a NO-OP when the gate is off', $pOff === 0 && $senderSpy->sent === []);

$senderSpy->sent = [];
$p = $on->notifyPromoted($event, ['u2', 'u5'], '2026-09-14 00:00:00');
chk('promotion notifies exactly the named users (2)', $p === 2 && count($senderSpy->sent) === 2);
chk('promotion uses the event_promoted category', $senderSpy->sent[0]['category'] === 'event_promoted');
chk('promotion is high priority', ($senderSpy->sent[0]['opts']['priority'] ?? '') === 'high');
chk('promotion targets the promoted user, not the whole roster', $senderSpy->sent[0]['user'] === 'u2' && $senderSpy->sent[1]['user'] === 'u5');
chk('promotion dedupe key is per (event,user,instant)', ($senderSpy->sent[0]['opts']['dedupe_key'] ?? '') === 'event_promoted:ev-1:u2:2026-09-14 00:00:00');
chk('promotion dedupes duplicate user ids', $on->notifyPromoted($event, ['u2', 'u2'], '2026-09-14 12:00:00') === 1);

$senderSpy->sent = [];
$pNoSender = (new EventNotifier($clock, $rosterPort, null, $configOn))->notifyPromoted($event, ['u2'], '2026-09-14 00:00:00');
chk('promotion no-op when sender unwired', $pNoSender === 0);

// ── 4. Reminder sweep: window, watermark, confirmed-only ─────────────────────
echo "reminder sweep\n";
$rosterPort->due = [
    ['id' => 'ev-1', 'organization_id' => 'org-1', 'group_id' => 'grp-1', 'title' => 'Conf', 'starts_at' => '2026-09-14 12:00:00', 'timezone' => 'UTC', 'mode' => 'physical'],
];
$rosterPort->reminded = [];
$senderSpy->sent      = [];
$r = $on->processDueReminders('org-1', 24);
chk('sweep processes the due event', $r['events'] === 1);
chk('sweep reminds ONLY confirmed registrants (1 of 2)', $r['notifications'] === 1 && count($senderSpy->sent) === 1);
chk('reminder uses the event_reminder category', $senderSpy->sent[0]['category'] === 'event_reminder');
chk('sweep advances the watermark (idempotency)', count($rosterPort->reminded) === 1 && $rosterPort->reminded[0]['event_id'] === 'ev-1');
chk('reminder dedupe key keyed on event start', ($senderSpy->sent[0]['opts']['dedupe_key'] ?? '') === 'event_reminder:ev-1:u1:2026-09-14 12:00:00');

// gated-off org still advances the watermark but stages nothing
$rosterPort->reminded = [];
$senderSpy->sent      = [];
$off = $mkNotifier($rosterPort, $senderSpy, $configOff);
$r2  = $off->processDueReminders('org-1', 24);
chk('gated-off sweep stages no notifications', $r2['notifications'] === 0 && $senderSpy->sent === []);
chk('gated-off sweep STILL advances the watermark (no rescans)', count($rosterPort->reminded) === 1);

// ── 5. Org-wide event resolves gate against org-root group ───────────────────
echo "org-wide event → org-root group gate\n";
$rosterPort->rootGroup       = 'root-grp';
$rosterPort->rosters['ev-2'] = [['user_id' => 'u9', 'status' => 'registered']];
$senderSpy->sent             = [];
$orgWide = ['id' => 'ev-2', 'organization_id' => 'org-1', 'group_id' => null, 'title' => 'All', 'starts_at' => '2026-10-01 10:00:00', 'timezone' => 'UTC', 'mode' => 'physical'];
$cnt = $on->notifyCancelled($orgWide, '2026-09-14 00:00:00');
chk('org-wide (no group) event resolves gate via org-root group and notifies', $cnt === 1);

$rosterPort->rootGroup = null;
$senderSpy->sent       = [];
chk('org-wide event with NO org-root group stays off', $on->notifyCancelled($orgWide, '2026-09-14 00:00:00') === 0);

// ── 6. EventService source wiring ────────────────────────────────────────────
echo "EventService wiring (cancel/update fan-out)\n";
$svc = (string) file_get_contents($root . '/app/Modules/Events/Services/EventService.php');
chk('ctor takes an optional EventNotifierPort', (bool) preg_match('/__construct\(.*?\?EventNotifierPort \$notifier = null/s', $svc));
chk('cancel() only fans out when the event WAS published', (bool) preg_match('/function cancel\(.*?\$wasPublished.*?notifyCancelled\(/s', $svc));
chk('cancel() passes the reason into the notifier', (bool) preg_match("/notifyCancelled\(.*?'cancellation_reason' => \\\$reason/s", $svc));
chk('update() fans out only for a published event', (bool) preg_match("/function update\(.*?status'\] === 'published'.*?notifyUpdated\(/s", $svc));
chk('update() passes the changed field list', (bool) preg_match('/\$changedFields = array_keys\(\$changes\)/', $svc));
chk('notifier is optional/null-safe (no notify when unwired)', (bool) preg_match('/\$this->notifier !== null/', $svc));
// G8 — capacity-raise promotion wiring.
chk('ctor takes an optional RegistrationService seam', (bool) preg_match('/__construct\(.*?\?RegistrationService \$registrations = null/s', $svc));
chk('update() detects a capacity RAISE (higher finite or lifted to unlimited)', (bool) preg_match('/\$capacityRaised/s', $svc));
chk('update() promotes the waitlist via RegistrationService', (bool) preg_match('/promoteWaitlistToCapacity\(/', $svc));
chk('update() only promotes on a published event with the seam wired', (bool) preg_match("/\\\$capacityRaised && \\\$this->registrations !== null && \\(string\\) \\\$event\\['status'\\] === 'published'/s", $svc));
chk('update() notifies exactly the promoted users', (bool) preg_match('/notifyPromoted\(\$eventRow, \$promoted, \$now\)/', $svc));
$rs = (string) file_get_contents($root . '/app/Modules/Events/Services/RegistrationService.php');
chk('RegistrationService has promoteWaitlistToCapacity()', (bool) preg_match('/function promoteWaitlistToCapacity\(/', $rs));
chk('promotion reuses the waitlisted→registered transition', (bool) preg_match("/status' => 'promoted'.*?status' => 'registered'/s", $rs));
chk('promotion is guarded on the source status (idempotent/race-safe)', (bool) preg_match("/where\('status', 'waitlisted'\)/", $rs));

// ── 7. Factory + command + migration ─────────────────────────────────────────
echo "factory + command + migration\n";
$fac = (string) file_get_contents($root . '/app/Modules/Events/Config/Services.php');
chk('events() factory injects the notifier', (bool) preg_match('/new EventService\(.*?static::eventNotifier\(\)/s', $fac));
chk('events() factory injects the registration seam (G8)', (bool) preg_match('/new EventService\(.*?static::eventRegistrations\(\)/s', $fac));
chk('eventNotifier() reuses NotificationServices::notifications', str_contains($fac, 'NotificationServices::notifications(false)'));
chk('eventNotifier() gate uses AdminServices::effectiveConfig', str_contains($fac, 'AdminServices::effectiveConfig()'));
chk('eventNotifier() reads roster via the DB adapter', str_contains($fac, 'new EventRosterDbAdapter($db)'));

$cmds = glob($root . '/app/Modules/Events/Commands/*EventRemindersDueCommand.php');
chk('reminder sweep command exists', $cmds !== []);
$cmd = $cmds !== [] ? (string) file_get_contents($cmds[0]) : '';
chk('command is a WBS spark command', str_contains($cmd, "protected \$group       = 'WBS'") && str_contains($cmd, "events:reminders-due"));
chk('command drives processDueReminders', str_contains($cmd, 'processDueReminders('));
chk('command supports --org and --all', str_contains($cmd, "'--org'") && str_contains($cmd, "'--all'"));

$migs = glob($root . '/app/Modules/Events/Database/Migrations/*AddEventReminderWatermark.php');
chk('reminder watermark migration exists', $migs !== []);
$mig = $migs !== [] ? (string) file_get_contents($migs[0]) : '';
chk('adds last_reminded_at column', str_contains($mig, 'last_reminded_at'));
chk('has a reversible down()', str_contains($mig, 'DROP COLUMN last_reminded_at'));

// ── 8. i18n parity: Events.notify.* across 6 locales ─────────────────────────
echo "i18n parity (notify block, all locales)\n";
$en   = require $langDir . '/en/Events.php';
$enN  = $flat($en['notify'] ?? []);
chk('en has a notify block', $enN !== []);
chk('en notify has the 4 categories x subject/body', count($enN) === 8
    && in_array('cancelledSubject', $enN, true) && in_array('reminderBody', $enN, true)
    && in_array('updatedSubject', $enN, true)
    && in_array('promotedSubject', $enN, true) && in_array('promotedBody', $enN, true));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr  = require $langDir . "/$loc/Events.php";
    $keys = $flat($arr['notify'] ?? []);
    chk("$loc mirrors notify keys", array_diff($enN, $keys) === [] && array_diff($keys, $enN) === [],
        'missing: ' . implode(',', array_diff($enN, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enN)));
}
// placeholder-style discipline: named :placeholders used by the copy
chk('notify copy uses :name placeholders (registry supports both styles)',
    str_contains((string) ($en['notify']['cancelledBody'] ?? ''), ':title')
    && str_contains((string) ($en['notify']['reminderBody'] ?? ''), ':starts_at'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
