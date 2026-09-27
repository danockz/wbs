<?php

declare(strict_types=1);

/**
 * L5 — SUBTREE ROLL-UP (ReportService::groupRollup with a resolved subtree).
 *
 * The platform's attribution rule is "contributions accumulate to EVERY
 * ancestor", so an ancestor group's event roll-up should include its DESCENDANT
 * groups' events — not just events snapshotted against the ancestor node itself.
 * groupRollup() now takes a `scope`:
 *   - 'self'    → the legacy single-group sum (this group's own snapshots);
 *   - 'subtree' → sum across the resolved subtree (self + descendants) via
 *                 GroupScopeResolver, aggregate-only, consistent with every other
 *                 group-scoped surface (and pruning terminal/archived descendants).
 *
 * Over an in-memory DB fake (a `query()` shim that runs the latest-snapshot-per-
 * event JOIN against seeded `event_report_snapshots`, honouring the `group_id IN
 * (...)` set) plus a tiny GroupScopeResolver stub, this proves:
 *   - self scope sums only the group's own events (legacy behaviour preserved);
 *   - subtree scope sums self + every descendant group's events;
 *   - it uses the LATEST snapshot per event (supersedes stale ones), never
 *     double-counts, and an event owned by one group is counted once;
 *   - groups_counted reflects the resolved set; an invalid scope falls back;
 *   - with no resolver wired, subtree degrades safely to single-group.
 *
 * Then, by source inspection + i18n parity + view smoke, proves the wiring:
 *   - the resolver seam + factory wiring;
 *   - the controller `?scope` filter (default subtree) passed through to the view;
 *   - Events.rollup.scope-keys + groupsCounted parity across all 6 locales;
 *   - report_rollup.php renders the self/subtree toggle + groups-counted note.
 *
 *   php app/Modules/Events/Services/tests/subtree_rollup_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var list<array<string,mixed>> event_report_snapshots rows */
        public array $snaps = [];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB();
        }

        /**
         * Shim for the latest-snapshot-per-event roll-up query. Binds are the
         * group-id set twice ([...$ids, ...$ids]); we take the first half as the
         * set (both halves are identical). Returns {metrics} for the latest
         * (MAX created_at) snapshot per event whose group is in the set.
         */
        public function query(string $sql, array $binds = []): \Fake\RS
        {
            if (! str_contains($sql, 'event_report_snapshots')) {
                return new \Fake\RS([]);
            }
            // The IN list is repeated twice; the distinct set is the unique binds.
            $set = array_values(array_unique(array_map('strval', $binds)));

            // Rows for events owned by a group in the set.
            $rows = array_values(array_filter($this->snaps, static fn ($r) => in_array((string) $r['group_id'], $set, true)));

            // Latest snapshot per event (MAX created_at).
            $latest = [];
            foreach ($rows as $r) {
                $ev = (string) $r['event_id'];
                if (! isset($latest[$ev]) || (string) $r['created_at'] > (string) $latest[$ev]['created_at']) {
                    $latest[$ev] = $r;
                }
            }

            return new \Fake\RS(array_map(static fn ($r) => ['metrics' => $r['metrics']], array_values($latest)));
        }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows) {}

        public function getRowArray()
        {
            return $this->rows[0] ?? null;
        }

        public function getResultArray()
        {
            return $this->rows;
        }
    }

    // Minimal query-builder stub — groupRollup() never uses table() directly for
    // the roll-up (that path goes through query()); present only so the ctor and
    // any incidental calls don't fatal.
    class QB
    {
        public function select($f)
        {
            return $this;
        }

        public function where($k, $v = null, $e = true)
        {
            return $this;
        }

        public function get(): RS
        {
            return new RS([]);
        }
    }
}

namespace WBS\Shared\Support {
    // Stub GroupScopeResolver exposing descendants() from a seeded map. The real
    // one reads group_closure; the service only calls descendants($groupId).
    class GroupScopeResolver
    {
        /** @param array<string,list<string>> $tree groupId => descendant ids */
        public function __construct(private array $tree = []) {}

        public function descendants(string $groupId): array
        {
            return $this->tree[$groupId] ?? [];
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Events\Services\ReportService;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\GroupScopeResolver;

    $root    = dirname(__DIR__, 5);
    $langDir = $root . '/app/Modules/Events/Language';
    $viewDir = $root . '/app/Modules/Events/Views';

    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Events/Services/ReportService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };
    $flat = static function (array $a, string $p = '') use (&$flat): array {
        $o = [];
        foreach ($a as $k => $v) {
            $key = $p === '' ? (string) $k : $p . '.' . $k;
            is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
        }
        sort($o);

        return $o;
    };

    // A metrics blob with the seven summed figures.
    $metrics = static fn (int $inv, int $resp, int $exp, int $act, int $qs, int $cc, int $cm): string => (string) json_encode([
        'mobilization' => ['invitations' => $inv, 'responses' => $resp],
        'attendance'   => ['expected' => $exp, 'actual' => $act],
        'streaming'    => ['qualified' => $qs],
        'contributions' => ['count' => $cc, 'amount_minor' => $cm],
    ]);

    // Hierarchy: region (r) → area a1, a2; a1 → local l1.
    $tree = [
        'r'  => ['a1', 'a2', 'l1'], // region's full descendant set
        'a1' => ['l1'],
        'a2' => [],
        'l1' => [],
    ];
    $resolver = new GroupScopeResolver($tree);

    $seed = static function () use ($metrics): BaseConnection {
        $db = new BaseConnection();
        $db->snaps = [
            // region's own event
            ['event_id' => 'ev-r', 'group_id' => 'r', 'created_at' => '2026-09-01 10:00:00.000000', 'metrics' => $metrics(100, 80, 70, 60, 5, 10, 100000)],
            // area a1 event, with a STALE + LATEST snapshot (latest must win)
            ['event_id' => 'ev-a1', 'group_id' => 'a1', 'created_at' => '2026-09-01 09:00:00.000000', 'metrics' => $metrics(1, 1, 1, 1, 1, 1, 1)],
            ['event_id' => 'ev-a1', 'group_id' => 'a1', 'created_at' => '2026-09-02 09:00:00.000000', 'metrics' => $metrics(50, 40, 35, 30, 2, 5, 50000)],
            // area a2 event
            ['event_id' => 'ev-a2', 'group_id' => 'a2', 'created_at' => '2026-09-01 11:00:00.000000', 'metrics' => $metrics(20, 15, 12, 10, 1, 2, 20000)],
            // local l1 event
            ['event_id' => 'ev-l1', 'group_id' => 'l1', 'created_at' => '2026-09-01 12:00:00.000000', 'metrics' => $metrics(7, 6, 5, 4, 0, 1, 7000)],
        ];

        return $db;
    };

    // ── 1. self scope — single group (legacy) ────────────────────────────────────
    echo "self scope (single group)\n";
    $svc = new ReportService($seed(), new Clock(), $resolver);
    $r   = $svc->groupRollup('r', 'self');
    $chk('self is ok', $r->ok);
    $chk('self counts only the region\'s own event', ($r->data['rollup']['events'] ?? null) === 1);
    $chk('self invitations = region only', ($r->data['rollup']['invitations'] ?? null) === 100);
    $chk('self scope echoed', ($r->data['scope'] ?? null) === 'self');
    $chk('self groups_counted = 1', ($r->data['groups_counted'] ?? null) === 1);

    // ── 2. subtree scope — self + descendants ────────────────────────────────────
    echo "subtree scope (self + descendants)\n";
    $svc = new ReportService($seed(), new Clock(), $resolver);
    $r   = $svc->groupRollup('r', 'subtree');
    $agg = $r->data['rollup'];
    // events: ev-r, ev-a1, ev-a2, ev-l1 = 4 (each counted once, latest snapshot)
    $chk('subtree counts every subtree event once', ($agg['events'] ?? null) === 4);
    $chk('subtree groups_counted = self + 3 descendants', ($r->data['groups_counted'] ?? null) === 4);
    $chk('subtree scope echoed', ($r->data['scope'] ?? null) === 'subtree');
    // invitations = 100 + 50(latest a1) + 20 + 7 = 177  (NOT the stale 1)
    $chk('subtree sums invitations across subtree (latest a1)', ($agg['invitations'] ?? null) === 177);
    $chk('subtree responses summed', ($agg['responses'] ?? null) === 80 + 40 + 15 + 6);
    $chk('subtree expected summed', ($agg['expected_attendance'] ?? null) === 70 + 35 + 12 + 5);
    $chk('subtree actual summed', ($agg['actual_attendance'] ?? null) === 60 + 30 + 10 + 4);
    $chk('subtree qualified streaming summed', ($agg['qualified_streaming'] ?? null) === 5 + 2 + 1 + 0);
    $chk('subtree contributions count summed', ($agg['contributions_count'] ?? null) === 10 + 5 + 2 + 1);
    $chk('subtree contributions minor summed', ($agg['contributions_minor'] ?? null) === 100000 + 50000 + 20000 + 7000);
    $chk('subtree does NOT use the stale a1 snapshot', ($agg['invitations'] ?? 0) !== 100 + 1 + 20 + 7);

    // ── 3. mid-tier ancestor a1 → self + l1 ──────────────────────────────────────
    echo "mid-tier ancestor (a1 + l1)\n";
    $svc = new ReportService($seed(), new Clock(), $resolver);
    $r   = $svc->groupRollup('a1', 'subtree');
    $chk('a1 subtree = its own event + l1', ($r->data['rollup']['events'] ?? null) === 2);
    $chk('a1 subtree groups_counted = 2', ($r->data['groups_counted'] ?? null) === 2);
    $chk('a1 subtree invitations = 50 + 7', ($r->data['rollup']['invitations'] ?? null) === 57);

    // leaf group a2 subtree == just itself
    $svc = new ReportService($seed(), new Clock(), $resolver);
    $r   = $svc->groupRollup('a2', 'subtree');
    $chk('leaf a2 subtree = 1 group / 1 event', ($r->data['groups_counted'] ?? null) === 1 && ($r->data['rollup']['events'] ?? null) === 1);

    // ── 4. invalid scope falls back to self ──────────────────────────────────────
    echo "invalid scope + no-resolver degrade\n";
    $svc = new ReportService($seed(), new Clock(), $resolver);
    $r   = $svc->groupRollup('r', 'bogus');
    $chk('invalid scope falls back to self', ($r->data['scope'] ?? null) === 'self' && ($r->data['rollup']['events'] ?? null) === 1);

    // No resolver wired → subtree degrades to single group.
    $svc = new ReportService($seed(), new Clock(), null);
    $r   = $svc->groupRollup('r', 'subtree');
    $chk('no resolver → subtree degrades to single group', ($r->data['groups_counted'] ?? null) === 1 && ($r->data['rollup']['events'] ?? null) === 1);
    $chk('degraded subtree still reports scope=subtree', ($r->data['scope'] ?? null) === 'subtree');

    // ── 5. source: seam + factory + controller wiring ────────────────────────────
    echo "source: seam + factory + controller\n";
    $rs = (string) file_get_contents($root . '/app/Modules/Events/Services/ReportService.php');
    $chk('ReportService takes an optional GroupScopeResolver seam', (bool) preg_match('/__construct\(.*?\?GroupScopeResolver \$scope = null/s', $rs));
    $chk('groupRollup() takes a scope (default subtree)', (bool) preg_match("/function groupRollup\(string \\\$groupId, string \\\$scope = 'subtree'\)/", $rs));
    $chk('subtree scope expands via descendants()', (bool) preg_match("/scope === 'subtree'.*?scope->descendants\(/s", $rs));
    $chk('groupRollup uses a group_id IN (...) set', str_contains($rs, 'group_id IN ('));
    $chk('rollup payload reports groups_counted', str_contains($rs, "'groups_counted'"));

    $sf = (string) file_get_contents($root . '/app/Modules/Events/Config/Services.php');
    $chk('factory wires groupScope() into reports()', (bool) preg_match('/new ReportService\(Database::connect\(\), SharedServices::clock\(\), SharedServices::groupScope\(\)\)/', $sf));

    $ct = (string) file_get_contents($root . '/app/Modules/Events/Controllers/ReportController.php');
    $chk('controller reads a ?scope filter', (bool) preg_match("/field\('scope', 'subtree'\)/", $ct));
    $chk('controller validates scope to self|subtree', (bool) preg_match("/in_array\(\\\$scope, \['self', 'subtree'\]/", $ct));
    $chk('controller passes scope to groupRollup', (bool) preg_match('/groupRollup\(\$groupId, \$scope\)/', $ct));
    $chk('controller passes scope + groupsCounted to the view', str_contains($ct, "'scope'") && str_contains($ct, "'groupsCounted'"));

    // ── 6. i18n parity: rollup.scope-keys + groupsCounted across 6 locales ─────────────
    echo "i18n parity (rollup scope keys)\n";
    $en    = require $langDir . '/en/Events.php';
    $enR   = $flat($en['rollup'] ?? []);
    $need  = ['rollup.scopeSelf', 'rollup.scopeSubtree', 'rollup.scopeSelfHint', 'rollup.scopeSubtreeHint', 'rollup.groupsCounted'];
    $enRp  = array_map(static fn ($k) => 'rollup.' . $k, $enR);
    $chk('en has the new scope keys', array_diff($need, $enRp) === []);
    foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $arr = require $langDir . "/$loc/Events.php";
        $keys = $flat($arr['rollup'] ?? []);
        $chk("$loc mirrors rollup keys", array_diff($enR, $keys) === [] && array_diff($keys, $enR) === [],
            'missing: ' . implode(',', array_diff($enR, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enR)));
    }
    // groupsCounted must carry the :count placeholder in every locale.
    foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $arr = require $langDir . "/$loc/Events.php";
        $chk("$loc groupsCounted has :count placeholder", str_contains((string) ($arr['rollup']['groupsCounted'] ?? ''), ':count'));
    }

    // ── 7. view smoke: toggle + groups-counted note ──────────────────────────────
    echo "view smoke (report_rollup.php)\n";
    if (! function_exists('lang')) {
        function lang(string $key)
        {
            $p = explode('.', $key);
            if (array_shift($p) !== 'Events') {
                return $key;
            }
            $v = $GLOBALS['__lgLang'];
            foreach ($p as $seg) {
                if (! is_array($v) || ! array_key_exists($seg, $v)) {
                    return $key;
                }
                $v = $v[$seg];
            }

            return $v;
        }
    }
    if (! function_exists('esc')) {
        function esc($s, $c = 'html')
        {
            return htmlspecialchars((string) $s, ENT_QUOTES);
        }
    }
    $GLOBALS['__lgLang'] = require $langDir . '/en/Events.php';
    $render = static function (string $file, array $data): string {
        $r = new class {
            public function render(string $file, array $data): string
            {
                extract($data);
                ob_start();
                include $file;

                return (string) ob_get_clean();
            }
        };

        return $r->render($file, $data);
    };
    $view = "$viewDir/report_rollup.php";
    $roll = ['events' => 4, 'invitations' => 177, 'responses' => 141, 'expected_attendance' => 122, 'actual_attendance' => 104, 'qualified_streaming' => 8, 'contributions_count' => 18, 'contributions_minor' => 177000];

    $h = $render($view, ['rollup' => $roll, 'groupId' => 'r', 'scope' => 'subtree', 'groupsCounted' => 4]);
    $chk('subtree view marks the subtree tab active', (bool) preg_match('/class="tab on"[^>]*href="\?scope=subtree"/', $h));
    $chk('subtree view links the self tab', str_contains($h, 'href="?scope=self"'));
    $chk('subtree view shows the descendants hint', str_contains($h, esc(lang('Events.rollup.scopeSubtreeHint'))));
    $chk('subtree view interpolates groups counted', str_contains($h, '4') && ! str_contains($h, ':count'));
    $chk('subtree view still shows aggregate figures', str_contains($h, '177'));

    $h2 = $render($view, ['rollup' => $roll, 'groupId' => 'r', 'scope' => 'self', 'groupsCounted' => 1]);
    $chk('self view marks the self tab active', (bool) preg_match('/class="tab on"[^>]*href="\?scope=self"/', $h2));
    $chk('self view shows the single-group hint', str_contains($h2, esc(lang('Events.rollup.scopeSelfHint'))));
    $chk('self view omits the groups-counted note', ! str_contains($h2, esc(lang('Events.rollup.scopeSubtreeHint'))));

    // Back-compat: no scope/groupsCounted vars (existing report_views_test path).
    $h3 = $render($view, ['rollup' => $roll, 'groupId' => 'r']);
    $chk('defaults to subtree when no scope var supplied', (bool) preg_match('/class="tab on"[^>]*href="\?scope=subtree"/', $h3));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
