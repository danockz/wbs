<?php

declare(strict_types=1);

/**
 * L4 — EVENT SOFT-ARCHIVE (EventService::archive / unarchive + list filters).
 *
 * A settled event (draft / cancelled / completed / completed_no_attendance) can
 * be filed away so it drops out of the active index, calendar feed and analytics
 * WITHOUT being deleted — attendance, orders, certificates and the audit trail
 * all survive. Archival is a soft FLAG (archived_at / archived_by) orthogonal to
 * `status`, so the terminal status is preserved and unarchive is a clean revert.
 *
 * Over an in-memory DB fake (table/where/orderBy/limit/get/update, with support
 * for the raw `archived_at IS [NOT] NULL` predicate the service uses), this proves
 * the live behaviour:
 *   - archive() flags a settled event (records archived_at + archived_by, keeps
 *     status), and is idempotent (deduplicated) on an already-archived event;
 *   - archive() refuses a PUBLISHED (live) event → 409 BAD_STATE errArchiveBadState;
 *   - archive() 404s an unknown event;
 *   - unarchive() clears the flag (reversible), keeps status, is idempotent on a
 *     live event, and 404s an unknown event;
 *   - listForOrg() defaults to ACTIVE (hides archived), and the 'archived' / 'all'
 *     filters switch the view.
 *
 * Then, by source inspection + i18n parity + view smoke, proves the wiring:
 *   - listInRange()/feedInRange()/AnalyticsService/RegistrationService all exclude
 *     archived rows;
 *   - controller archive()/unarchive() + index() ?archived filter;
 *   - guarded + webcsrf routes;
 *   - migration adds nullable columns + index and is reversible;
 *   - Events.lifecycle.archived* + Events.archive.* parity across all 6 locales;
 *   - show.php archive/restore control + index.php filter tabs / archived badge.
 *
 *   php app/Modules/Events/Services/tests/event_archival_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function affectedRows(): int
        {
            return $this->affected;
        }

        public function transStart(): void {}

        public function transComplete(): void {}

        public function transStatus(): bool
        {
            return true;
        }

        public function query(string $sql, array $binds = []): \Fake\RS
        {
            return new \Fake\RS([]);
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

    class QB
    {
        /** @var array<int,array{k:string,op:string,v:mixed}> */
        private array $conds = [];
        private ?string $orderKey = null;
        private ?int $limit = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($f)
        {
            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            // Raw predicates used by the service: "archived_at IS NULL" / "IS NOT NULL".
            if (stripos($k, 'IS NOT NULL') !== false) {
                $this->conds[] = ['k' => trim(str_ireplace('IS NOT NULL', '', $k)), 'op' => 'notnull', 'v' => null];
            } elseif (stripos($k, 'IS NULL') !== false) {
                $this->conds[] = ['k' => trim(str_ireplace('IS NULL', '', $k)), 'op' => 'null', 'v' => null];
            } else {
                $this->conds[] = ['k' => $k, 'op' => '=', 'v' => $v];
            }

            return $this;
        }

        public function orderBy($k, $dir = 'ASC')
        {
            $this->orderKey = trim((string) $k);

            return $this;
        }

        public function limit($n)
        {
            $this->limit = (int) $n;

            return $this;
        }

        private function filtered(): array
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($this->orderKey !== null) {
                usort($rows, fn ($a, $b) => ($a[$this->orderKey] ?? '') <=> ($b[$this->orderKey] ?? ''));
            }
            if ($this->limit !== null) {
                $rows = array_slice($rows, 0, $this->limit);
            }

            return $rows;
        }

        public function get(): RS
        {
            return new RS($this->filtered());
        }

        public function update(array $set): bool
        {
            $n = 0;
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                    $n++;
                }
            }
            $this->db->affected = $n;

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $rv = $r[$c['k']] ?? null;
                $ok = match ($c['op']) {
                    'null'    => $rv === null,
                    'notnull' => $rv !== null,
                    default   => (string) $rv === (string) $c['v'],
                };
                if (! $ok) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Events\Services\EventService;
    use WBS\Shared\Support\Clock;

    $root    = dirname(__DIR__, 5);
    $langDir = $root . '/app/Modules/Events/Language';
    $viewDir = $root . '/app/Modules/Events/Views';

    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Events/Services/EventValidator.php';
    require_once $root . '/app/Modules/Events/Services/EventReadiness.php';
    require_once $root . '/app/Modules/Events/Services/EventService.php';

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

    Clock::freeze(new DateTimeImmutable('2026-09-18 12:00:00', new DateTimeZone('UTC')));
    $NOW = '2026-09-18 12:00:00';
    $ORG = 'org-1';

    $seed = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        $db->rows['events'] = [
            ['id' => 'ev-draft', 'organization_id' => $ORG, 'title' => 'Draft', 'status' => 'draft', 'starts_at' => '2026-10-01 10:00:00', 'archived_at' => null, 'archived_by' => null],
            ['id' => 'ev-pub', 'organization_id' => $ORG, 'title' => 'Live', 'status' => 'published', 'starts_at' => '2026-10-02 10:00:00', 'archived_at' => null, 'archived_by' => null],
            ['id' => 'ev-canc', 'organization_id' => $ORG, 'title' => 'Cancelled', 'status' => 'cancelled', 'starts_at' => '2026-10-03 10:00:00', 'archived_at' => null, 'archived_by' => null],
            ['id' => 'ev-done', 'organization_id' => $ORG, 'title' => 'Done', 'status' => 'completed', 'starts_at' => '2026-08-01 10:00:00', 'archived_at' => null, 'archived_by' => null],
            ['id' => 'ev-old', 'organization_id' => $ORG, 'title' => 'Old archived', 'status' => 'completed', 'starts_at' => '2026-07-01 10:00:00', 'archived_at' => '2026-09-10 00:00:00', 'archived_by' => 'u-x'],
        ];

        return $db;
    };
    $rowById = static function (BaseConnection $db, string $id): ?array {
        foreach ($db->rows['events'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    };

    // ── 1. archive() — behaviour ────────────────────────────────────────────────
    echo "archive() behaviour\n";
    $db  = $seed();
    $svc = new EventService($db, new Clock());

    $r = $svc->archive('ev-done', 'actor-1');
    $chk('archives a completed event (ok)', $r->ok && ($r->data['archived'] ?? null) === true);
    $done = $rowById($db, 'ev-done');
    $chk('records archived_at', ($done['archived_at'] ?? null) === $NOW);
    $chk('records archived_by (actor)', ($done['archived_by'] ?? null) === 'actor-1');
    $chk('preserves terminal status (still completed)', ($done['status'] ?? '') === 'completed');

    $r2 = $svc->archive('ev-done', 'actor-2');
    $chk('re-archiving is idempotent (deduplicated)', $r2->ok && ($r2->meta['deduplicated'] ?? false) === true);
    $chk('idempotent archive does NOT overwrite archived_by', ($rowById($db, 'ev-done')['archived_by'] ?? '') === 'actor-1');

    $rc = $svc->archive('ev-canc', 'actor-1');
    $chk('archives a cancelled event', $rc->ok && ($rowById($db, 'ev-canc')['archived_at'] ?? null) === $NOW);
    $rd = $svc->archive('ev-draft', 'actor-1');
    $chk('archives a draft event', $rd->ok && ($rowById($db, 'ev-draft')['archived_at'] ?? null) === $NOW);

    // Published = LIVE → conflict.
    $rp = $svc->archive('ev-pub', 'actor-1');
    $chk('refuses a published event (not ok)', ! $rp->ok);
    $chk('published refusal is 409', $rp->status === 409);
    $chk('published refusal is BAD_STATE', $rp->code === 'BAD_STATE');
    $chk('published refusal message key', $rp->message === 'Events.lifecycle.errArchiveBadState');
    $chk('published event NOT flagged after refusal', ($rowById($db, 'ev-pub')['archived_at'] ?? null) === null);

    $rn = $svc->archive('nope', 'actor-1');
    $chk('unknown event archive → 404', ! $rn->ok && $rn->status === 404);

    // ── 2. unarchive() — behaviour ──────────────────────────────────────────────
    echo "unarchive() behaviour\n";
    $db  = $seed();
    $svc = new EventService($db, new Clock());

    $u = $svc->unarchive('ev-old');
    $chk('restores an archived event (ok)', $u->ok && ($u->data['archived'] ?? null) === false);
    $old = $rowById($db, 'ev-old');
    $chk('clears archived_at', array_key_exists('archived_at', $old) && $old['archived_at'] === null);
    $chk('clears archived_by', array_key_exists('archived_by', $old) && $old['archived_by'] === null);
    $chk('unarchive preserves status (still completed)', ($old['status'] ?? '') === 'completed');

    $u2 = $svc->unarchive('ev-old');
    $chk('re-unarchiving a live event is idempotent (deduplicated)', $u2->ok && ($u2->meta['deduplicated'] ?? false) === true);

    $un = $svc->unarchive('missing');
    $chk('unknown event unarchive → 404', ! $un->ok && $un->status === 404);

    // ── 3. listForOrg() — filter ────────────────────────────────────────────────
    echo "listForOrg() active/archived/all filter\n";
    $db  = $seed();
    $svc = new EventService($db, new Clock());
    $ids = static fn (array $rows): array => array_map(static fn ($r) => $r['id'], $rows);

    $active = $ids($svc->listForOrg($ORG));
    $chk('default hides archived events', ! in_array('ev-old', $active, true));
    $chk('default shows the 4 live events', count($active) === 4 && in_array('ev-draft', $active, true));

    $arch = $ids($svc->listForOrg($ORG, 50, 'archived'));
    $chk('archived filter shows ONLY archived', $arch === ['ev-old']);

    $all = $ids($svc->listForOrg($ORG, 50, 'all'));
    $chk('all filter shows everything', count($all) === 5 && in_array('ev-old', $all, true));

    // ── 4. source: sibling reads exclude archived + method shapes ────────────────
    echo "source: archived exclusion across reads\n";
    $es  = (string) file_get_contents($root . '/app/Modules/Events/Services/EventService.php');
    $chk('ARCHIVABLE_STATUSES excludes published', (bool) preg_match("/ARCHIVABLE_STATUSES\s*=\s*\[[^\]]*'draft'[^\]]*'cancelled'[^\]]*'completed'[^\]]*'completed_no_attendance'[^\]]*\]/", $es) && ! preg_match("/ARCHIVABLE_STATUSES\s*=\s*\[[^\]]*'published'/", $es));
    $chk('archive() guards on ARCHIVABLE_STATUSES', (bool) preg_match('/function archive\(.*?ARCHIVABLE_STATUSES/s', $es));
    $chk('archive() is idempotent (deduplicated)', (bool) preg_match('/function archive\(.*?deduplicated/s', $es));
    $chk('listInRange() excludes archived', (bool) preg_match("/function listInRange\(.*?archived_at IS NULL/s", $es));
    $chk('feedInRange() excludes archived', (bool) preg_match("/function feedInRange\(.*?archived_at IS NULL/s", $es));

    $an = (string) file_get_contents($root . '/app/Modules/Events/Services/AnalyticsService.php');
    $chk('AnalyticsService excludes archived', str_contains($an, 'archived_at IS NULL'));
    $rg = (string) file_get_contents($root . '/app/Modules/Events/Services/RegistrationService.php');
    $chk('RegistrationService (upcoming badge) excludes archived', str_contains($rg, 'archived_at IS NULL'));

    // ── 5. controller + routes wiring ────────────────────────────────────────────
    echo "controller + routes wiring\n";
    $ctrl = (string) file_get_contents($root . '/app/Modules/Events/Controllers/EventController.php');
    $chk('archive() action exists', (bool) preg_match('/function archive\(string \$eventId/', $ctrl));
    $chk('archive() passes the actor id', (bool) preg_match('/function archive\(.*?events\(\)->archive\(.*?actorId\(\)/s', $ctrl));
    $chk('archive() flows through respondLifecycle archivedFlash', (bool) preg_match("/function archive\(.*?respondLifecycle\(.*?'archivedFlash'/s", $ctrl));
    $chk('unarchive() action exists', (bool) preg_match('/function unarchive\(string \$eventId/', $ctrl));
    $chk('unarchive() flows through respondLifecycle unarchivedFlash', (bool) preg_match("/function unarchive\(.*?respondLifecycle\(.*?'unarchivedFlash'/s", $ctrl));
    $chk('index() reads + validates the ?archived filter', (bool) preg_match("/function index\(.*?field\('archived'.*?in_array\(\\\$archived, \['active', 'archived', 'all'\]/s", $ctrl));
    $chk('index() passes archived to the view', (bool) preg_match("/function index\(.*?'archived'\s*=>\s*\\\$archived/s", $ctrl));

    $routes = (string) file_get_contents($root . '/app/Config/Routes.php');
    $chk('POST (:segment)/archive → EventController::archive, gated + webcsrf', (bool) preg_match("#post\('\(:segment\)/archive'.*?EventController::archive.*?authorize:event\.create,any.*?webcsrf#s", $routes));
    $chk('POST (:segment)/unarchive → EventController::unarchive, gated + webcsrf', (bool) preg_match("#post\('\(:segment\)/unarchive'.*?EventController::unarchive.*?authorize:event\.create,any.*?webcsrf#s", $routes));

    // ── 6. migration ─────────────────────────────────────────────────────────────
    echo "migration: archival columns\n";
    $migs = glob($root . '/app/Modules/Events/Database/Migrations/*AddEventArchival.php');
    $chk('AddEventArchival migration exists', $migs !== []);
    $mig = $migs !== [] ? (string) file_get_contents($migs[0]) : '';
    $chk('adds archived_at (nullable)', (bool) preg_match('/ADD COLUMN archived_at DATETIME NULL/', $mig));
    $chk('adds archived_by (nullable)', (bool) preg_match('/ADD COLUMN archived_by CHAR\(36\) NULL/', $mig));
    $chk('adds ev_active_idx over (org, archived_at, starts_at)', (bool) preg_match('/KEY ev_active_idx \(organization_id, archived_at, starts_at\)/', $mig));
    $chk('has a reversible down()', str_contains($mig, 'DROP COLUMN archived_at') && str_contains($mig, 'DROP KEY ev_active_idx'));

    // ── 7. i18n parity: lifecycle archived* + archive.* across 6 locales ─────────
    echo "i18n parity (all locales)\n";
    $en   = require $langDir . '/en/Events.php';
    $enL  = $flat($en['lifecycle'] ?? []);
    $enA  = $flat($en['archive'] ?? []);
    $chk('en has an archive.* block', $enA !== []);
    $chk('en lifecycle has archived/unarchived flashes + err', in_array('archivedFlash', $enL, true) && in_array('unarchivedFlash', $enL, true) && in_array('errArchiveBadState', $enL, true));
    $chk('en archive.* has the expected control keys', array_diff(['heading', 'hint', 'archiveBtn', 'confirm', 'archivedHeading', 'archivedHint', 'unarchiveBtn', 'filterActive', 'filterArchived', 'filterAll', 'badge', 'emptyArchive'], $enA) === []);
    foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $arr = require $langDir . "/$loc/Events.php";
        $chk("$loc mirrors lifecycle keys", array_diff($enL, $flat($arr['lifecycle'] ?? [])) === [] && array_diff($flat($arr['lifecycle'] ?? []), $enL) === []);
        $chk("$loc mirrors archive.* keys", array_diff($enA, $flat($arr['archive'] ?? [])) === [] && array_diff($flat($arr['archive'] ?? []), $enA) === []);
    }

    // ── 8. view smoke: show.php control + index.php tabs/badge ────────────────────
    echo "view smoke (show.php + index.php)\n";
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
        $renderer = new class {
            public function extend($x) { return ''; }
            public function section($x) { return ''; }
            public function endSection() { return ''; }
            public function render(string $file, array $data): string
            {
                extract($data);
                ob_start();
                include $file;

                return (string) ob_get_clean();
            }
        };

        return $renderer->render($file, $data);
    };
    $show = "$viewDir/show.php";

    // Completed (settled, not archived) → archive control offered.
    $h = $render($show, [
        'result'    => ['id' => 'ev-9', 'title' => 'Done', 'status' => 'completed', 'archived_at' => null],
        'csrf'      => 'TKN',
        'readiness' => null,
    ]);
    $chk('completed shows the archive form', str_contains($h, 'action="/events/ev-9/archive"'));
    $chk('archive form has confirm()', str_contains($h, 'onsubmit="return confirm('));
    $chk('completed does NOT show unarchive', ! str_contains($h, '/events/ev-9/unarchive'));

    // Archived → restore control offered instead.
    $h2 = $render($show, [
        'result'    => ['id' => 'ev-9', 'title' => 'Done', 'status' => 'completed', 'archived_at' => '2026-09-10 00:00:00'],
        'csrf'      => 'TKN',
        'readiness' => null,
    ]);
    $chk('archived shows the unarchive form', str_contains($h2, 'action="/events/ev-9/unarchive"'));
    $chk('archived does NOT show archive form', ! str_contains($h2, 'action="/events/ev-9/archive"'));

    // Published (live) → no archive control (must cancel/complete first).
    $h3 = $render($show, [
        'result'    => ['id' => 'ev-9', 'title' => 'Live', 'status' => 'published', 'archived_at' => null],
        'csrf'      => 'TKN',
        'readiness' => null,
    ]);
    $chk('published shows no archive control', ! str_contains($h3, '/events/ev-9/archive'));

    // index.php — filter tabs + archived badge/dimming.
    $index = "$viewDir/index.php";
    $hi = $render($index, [
        'result'   => ['events' => [
            ['id' => 'a', 'title' => 'Live one', 'status' => 'completed', 'starts_at' => '2026-10-01 10:00:00', 'archived_at' => null],
            ['id' => 'b', 'title' => 'Filed one', 'status' => 'completed', 'starts_at' => '2026-07-01 10:00:00', 'archived_at' => '2026-09-10 00:00:00'],
        ]],
        'archived' => 'all',
    ]);
    $chk('index renders the archived filter tab', str_contains($hi, '/events?archived=archived'));
    $chk('index renders the active filter tab', str_contains($hi, '/events?archived=active'));
    $chk('index shows the Archived badge on a filed row', str_contains($hi, esc(lang('Events.archive.badge'))));
    $chk('index dims the archived row', str_contains($hi, 'opacity:0.65'));

    // Empty archive view → archive-specific empty message.
    $he = $render($index, ['result' => ['events' => []], 'archived' => 'archived']);
    $chk('empty archive shows the archive empty message', str_contains($he, esc(lang('Events.archive.emptyArchive'))));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
