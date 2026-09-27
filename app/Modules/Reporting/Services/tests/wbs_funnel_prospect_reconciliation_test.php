<?php

declare(strict_types=1);

/**
 * M11 — reconcile the three "prospect" notions in the WBS funnel.
 *
 * `users.status='prospect'` (account lifecycle), `journey_stages.code='prospect'`
 * (discipleship stage) and `prospects.state` (Referrals outreach lead) are three
 * distinct notions that share a word. The WBS funnel used to count EVERY
 * `prospects` row as `win.prospects` while ALSO counting every user in
 * `win.members_unique` — so a lead that had already CONVERTED (and therefore has
 * a linked platform user) was counted twice: once as a prospect, once as a
 * member.
 *
 * The canonical reconciliation adopted here:
 *   • prospects.state='captured'  → OPEN lead  → win.prospects
 *   • prospects.state='converted' → became a person → represented by its linked
 *                                    user in members_unique, NOT re-counted
 *   • prospects.state='rejected'  → dead lead   → counted nowhere
 *   • users.status='prospect' / journey stage 'prospect' are OTHER axes, not
 *     conflated with the outreach lead count.
 *
 * This test proves win.prospects now counts OPEN contacts only, that converted /
 * rejected leads are excluded (removing the double-count), that group scoping via
 * assigned_group_id works, and that a schema without a `state` column degrades to
 * counting all rows rather than erroring.
 *
 *   php app/Modules/Reporting/Services/tests/wbs_funnel_prospect_reconciliation_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        /** @var array<string,list<string>> */
        public array $fields = [];
        /** @var list<array{sql:string,binds:array,ret:array}> */
        public array $rawReturns = [];

        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }

        public function getFieldNames(string $t): array { return $this->fields[$t] ?? []; }

        public function query(string $sql, array $binds = []): \Fake\RS
        {
            // Raw queries in DashboardService only SUM contributions / points.
            if (str_contains($sql, 'contributions')) {
                return new \Fake\RS([['total' => 0]]);
            }
            if (str_contains($sql, 'point_ledger')) {
                return new \Fake\RS([['total' => 0]]);
            }

            return new \Fake\RS([[]]);
        }
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
        private bool $distinct = false;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($s) { return $this; }
        public function distinct() { $this->distinct = true; return $this; }

        public function where($k, $v = null, $escape = true)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function whereIn($k, $vals) { return $this; }

        public function countAllResults(): int { return count($this->rowsFor()); }

        private function rowsFor(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], function ($r) {
                foreach ($this->eq as $k => $v) {
                    if (($r[$k] ?? null) !== $v) { return false; }
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
require_once $root . '/app/Modules/Reporting/Services/DashboardService.php';

use WBS\Reporting\Services\DashboardService;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

Clock::freeze(new DateTimeImmutable('2026-09-18 00:00:00', new DateTimeZone('UTC')));

/** Build a DB with a full prospects schema. */
$makeDb = static function (): \CodeIgniter\Database\BaseConnection {
    $db = new \CodeIgniter\Database\BaseConnection();
    $db->fields['prospects'] = ['organization_id', 'state', 'assigned_group_id'];
    $db->fields['referral_attributions'] = ['organization_id', 'group_id'];
    $db->fields['enrollments'] = ['organization_id', 'group_id'];
    $db->fields['course_completions'] = ['organization_id'];
    $db->fields['events'] = ['organization_id', 'group_id'];
    $db->fields['event_attendance'] = ['organization_id', 'user_id'];
    $db->fields['users'] = ['organization_id', 'status'];

    return $db;
};

echo "1. converted + rejected leads excluded from win.prospects\n";
$db = $makeDb();
$db->rows['prospects'] = [
    ['organization_id' => 'org-1', 'state' => 'captured',  'assigned_group_id' => 'g1'],
    ['organization_id' => 'org-1', 'state' => 'captured',  'assigned_group_id' => 'g1'],
    ['organization_id' => 'org-1', 'state' => 'captured',  'assigned_group_id' => 'g2'],
    ['organization_id' => 'org-1', 'state' => 'converted', 'assigned_group_id' => 'g1'], // now a member, excluded
    ['organization_id' => 'org-1', 'state' => 'rejected',  'assigned_group_id' => 'g1'], // dead lead, excluded
    ['organization_id' => 'org-2', 'state' => 'captured',  'assigned_group_id' => 'g1'], // other org
];
// members_unique reads all users; include the converted lead's linked user among them
$db->rows['users'] = array_fill(0, 8, ['organization_id' => 'org-1', 'status' => 'active']);

$svc = new DashboardService($db, new Clock());
$res = $svc->wbsFunnel('org-1');
$win = $res->data['funnel']['win'];
// 3 open captured leads in org-1 → below suppression threshold (5) so it is suppressed to '<5'
chk('win.prospects counts only OPEN captured (3 → suppressed <5)', $win['prospects'] === '<5', var_export($win['prospects'], true));

echo "2. large open cohort shows the true open count (no double count)\n";
$db2 = $makeDb();
$open = array_fill(0, 10, ['organization_id' => 'org-1', 'state' => 'captured', 'assigned_group_id' => 'g1']);
$conv = array_fill(0, 6,  ['organization_id' => 'org-1', 'state' => 'converted', 'assigned_group_id' => 'g1']);
$db2->rows['prospects'] = array_merge($open, $conv);
$db2->rows['users'] = array_fill(0, 20, ['organization_id' => 'org-1', 'status' => 'active']);
$res2 = (new DashboardService($db2, new Clock()))->wbsFunnel('org-1');
chk('win.prospects = 10 open (6 converted NOT added)', $res2->data['funnel']['win']['prospects'] === 10, var_export($res2->data['funnel']['win']['prospects'], true));
chk('members_unique = 20 (all users, incl. converted leads once)', $res2->data['funnel']['win']['members_unique'] === 20, var_export($res2->data['funnel']['win']['members_unique'], true));

echo "3. group scoping via assigned_group_id\n";
$db3 = $makeDb();
$db3->rows['prospects'] = array_merge(
    array_fill(0, 7, ['organization_id' => 'org-1', 'state' => 'captured', 'assigned_group_id' => 'g1']),
    array_fill(0, 9, ['organization_id' => 'org-1', 'state' => 'captured', 'assigned_group_id' => 'g2']),
);
$db3->rows['users'] = array_fill(0, 30, ['organization_id' => 'org-1', 'status' => 'active']);
$res3 = (new DashboardService($db3, new Clock()))->wbsFunnel('org-1', ['group_id' => 'g1']);
chk('group-scoped win.prospects = 7 (g1 only)', $res3->data['funnel']['win']['prospects'] === 7, var_export($res3->data['funnel']['win']['prospects'], true));

echo "4. converted-only cohort → zero open prospects (was double-counted before)\n";
$db4 = $makeDb();
$db4->rows['prospects'] = array_fill(0, 12, ['organization_id' => 'org-1', 'state' => 'converted', 'assigned_group_id' => 'g1']);
$db4->rows['users'] = array_fill(0, 12, ['organization_id' => 'org-1', 'status' => 'active']);
$res4 = (new DashboardService($db4, new Clock()))->wbsFunnel('org-1');
chk('all-converted → win.prospects = 0', $res4->data['funnel']['win']['prospects'] === 0, var_export($res4->data['funnel']['win']['prospects'], true));

echo "5. legacy schema without `state` column → counts all rows (no error)\n";
$db5 = new \CodeIgniter\Database\BaseConnection();
$db5->fields['prospects'] = ['organization_id']; // no state, no assigned_group_id
$db5->fields['users'] = ['organization_id'];
$db5->rows['prospects'] = array_fill(0, 11, ['organization_id' => 'org-1']);
$db5->rows['users'] = array_fill(0, 5, ['organization_id' => 'org-1']);
$res5 = (new DashboardService($db5, new Clock()))->wbsFunnel('org-1');
chk('no-state schema counts all 11 rows', $res5->data['funnel']['win']['prospects'] === 11, var_export($res5->data['funnel']['win']['prospects'], true));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
