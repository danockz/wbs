<?php

declare(strict_types=1);

/**
 * EVENT-SCOPED invitation counts (gap G4).
 *
 * `expectedAttendance()` and `ReportService::mobilization()` used to report
 * "invitations" as the count of ALL prospects in the organization — so every
 * event's funnel was inflated with the whole address book, regardless of the
 * event. G5 gave events a real invitation linkage (`event_invitations` +
 * `event_invite_links`); this test proves G4 now counts EVENT-SCOPED figures:
 * direct invitations issued for THIS event (excluding revoked) + sign-ups
 * redeemed through its shareable link, and that a different event's invitations
 * do NOT bleed in.
 *
 *   php app/Modules/Events/Services/tests/event_invitation_counts_test.php
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
        private array $ne = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($s) { return $this; }

        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if (str_ends_with($k, '!=')) {
                $this->ne[trim(rtrim($k, '!='))] = $v;
            } else {
                $this->eq[$k] = $v;
            }

            return $this;
        }

        public function get($limit = null): RS { return new RS($this->rowsFor()); }
        public function countAllResults(): int { return count($this->rowsFor()); }

        private function rowsFor(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], function ($r) {
                foreach ($this->eq as $k => $v) {
                    if (($r[$k] ?? null) !== $v) { return false; }
                }
                foreach ($this->ne as $k => $v) {
                    if (($r[$k] ?? null) === $v) { return false; }
                }

                return true;
            }));
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Events/Services/InvitationService.php';

use WBS\Events\Services\InvitationService;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

Clock::freeze(new DateTimeImmutable('2026-09-14 00:00:00', new DateTimeZone('UTC')));
$db  = new \CodeIgniter\Database\BaseConnection();
$svc = new InvitationService($db, new Clock());

// Two events; the org address book is large and irrelevant to either.
$db->rows['prospects'] = array_fill(0, 500, ['organization_id' => 'org-1']);
$db->rows['event_invitations'] = [
    ['event_id' => 'ev-A', 'status' => 'pending'],
    ['event_id' => 'ev-A', 'status' => 'accepted'],
    ['event_id' => 'ev-A', 'status' => 'revoked'],   // excluded
    ['event_id' => 'ev-B', 'status' => 'pending'],   // other event — must not bleed in
];
$db->rows['event_invite_links'] = [
    ['event_id' => 'ev-A', 'redeemed_count' => 7],
    ['event_id' => 'ev-B', 'redeemed_count' => 99],
];

echo "event-scoped counts\n";
$a = $svc->countInvitationsFor('ev-A');
chk('direct invitations exclude revoked', $a['direct'] === 2, 'got ' . $a['direct']);
chk('link redemptions counted', $a['redemptions'] === 7, 'got ' . $a['redemptions']);
chk('total = direct + redemptions', $a['total'] === 9, 'got ' . $a['total']);
chk('the 500 org prospects do NOT inflate the count', $a['total'] === 9);
chk("another event's invitations do NOT bleed in", $a['direct'] === 2 && $a['redemptions'] === 7);

echo "event with no invitation activity\n";
$c = $svc->countInvitationsFor('ev-empty');
chk('no direct invites → 0', $c['direct'] === 0);
chk('no link → 0 redemptions', $c['redemptions'] === 0);
chk('total 0 (not the org prospect count)', $c['total'] === 0);

echo "call-site wiring (source)\n";
$es = (string) file_get_contents($root . '/app/Modules/Events/Services/EventService.php');
chk('expectedAttendance uses event-scoped counts', str_contains($es, '->countInvitationsFor($eventId)'));
chk('expectedAttendance no longer counts ALL org prospects',
    ! (bool) preg_match("/invitations.*?table\('prospects'\).*?organization_id.*?countAllResults/s", $es));
chk('expectedAttendance surfaces the direct/link breakdown',
    str_contains($es, "'invitations_direct'") && str_contains($es, "'invitations_link'"));

$rs = (string) file_get_contents($root . '/app/Modules/Events/Services/ReportService.php');
chk('mobilization uses event-scoped counts', str_contains($rs, '->countInvitationsFor($eventId)'));
chk('mobilization no longer counts ALL org prospects for invitations',
    ! (bool) preg_match("/invitations.*?table\('prospects'\).*?organization_id.*?countAllResults/s", $rs));
chk('mobilization converted_prospects is event-scoped (contact source_ref)',
    str_contains($rs, "like('source_ref', 'contact:'"));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
