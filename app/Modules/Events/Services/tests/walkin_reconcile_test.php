<?php

declare(strict_types=1);

/**
 * L6 — WALK-IN RECONCILE (CheckinService walk_in flag + reconcile()).
 *
 * A `manual` / `streaming` check-in with NO active registration is a walk-in.
 * Previously it was indistinguishable from a matched check-in, so attendance and
 * registration silently drifted. L6:
 *   - recordAttendance() STAMPS a `walk_in` flag (true iff there was no
 *     `registered` row at check-in time) — a flag, NOT a synthesized fake
 *     registration (capacity/waitlist accounting and the audit trail stay clean);
 *   - reconcile(eventId) is an aggregate-only, read-only, non-destructive report
 *     splitting present attendance into matched vs walk-in, counting no-shows
 *     (registered, never present) and paid-but-absent (paid an order, never
 *     present), with a `reconciled` boolean.
 *
 * Over an in-memory DB fake (table/where/whereIn/countAllResults/insert with a
 * UNIQUE active_key + a query() shim for the two anti-joins and the DISTINCT
 * payer counts) this proves the live behaviour, then source/route/migration/i18n
 * /view wiring by inspection + render.
 *
 *   php app/Modules/Events/Services/tests/walkin_reconcile_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public bool $txOk = true;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function transStart(): void {}

        public function transComplete(): void {}

        public function transStatus(): bool
        {
            return $this->txOk;
        }

        /** SQL shim for reconcile()'s anti-joins + DISTINCT-payer counts. */
        public function query(string $sql, array $binds = []): \Fake\RS
        {
            $eventId = (string) ($binds[0] ?? '');
            $att     = $this->rows['event_attendance'] ?? [];
            $present = static fn (string $uid): bool => (bool) array_filter($att, static fn ($a) => (string) $a['event_id'] === (string) $GLOBALS['__evt'] && (string) $a['user_id'] === $uid && (string) $a['status'] === 'present');
            $GLOBALS['__evt'] = $eventId;

            // no-show: registered rows with no present attendance
            if (str_contains($sql, 'FROM event_registrations r')) {
                $c = 0;
                foreach ($this->rows['event_registrations'] ?? [] as $r) {
                    if ((string) $r['event_id'] === $eventId && (string) $r['status'] === 'registered' && ! $present((string) $r['user_id'])) {
                        $c++;
                    }
                }

                return new \Fake\RS([['c' => $c]]);
            }
            // distinct paid payers (with or without the NOT EXISTS clause)
            if (str_contains($sql, 'FROM event_orders')) {
                $absentOnly = str_contains($sql, 'NOT EXISTS');
                $users = [];
                foreach ($this->rows['event_orders'] ?? [] as $o) {
                    if ((string) $o['event_id'] === $eventId && (string) $o['status'] === 'paid') {
                        $u = (string) $o['user_id'];
                        if ($absentOnly && $present($u)) {
                            continue;
                        }
                        $users[$u] = true;
                    }
                }

                return new \Fake\RS([['c' => count($users)]]);
            }

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
        /** @var array<int,array{k:string,v:mixed}> */
        private array $eq = [];
        /** @var array{k:string,v:list<mixed>}|null */
        private ?array $in = null;
        private ?string $selCol = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($f)
        {
            $this->selCol = trim((string) $f);

            return $this;
        }

        public function where($k, $v = null, $e = true)
        {
            $this->eq[] = ['k' => trim((string) $k), 'v' => $v];

            return $this;
        }

        public function whereIn($k, array $v)
        {
            $this->in = ['k' => trim((string) $k), 'v' => $v];

            return $this;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $c) {
                if ((string) ($r[$c['k']] ?? null) !== (string) $c['v']) {
                    return false;
                }
            }
            if ($this->in !== null && ! in_array((string) ($r[$this->in['k']] ?? null), array_map('strval', $this->in['v']), true)) {
                return false;
            }

            return true;
        }

        private function filtered(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function get(): RS
        {
            return new RS($this->filtered());
        }

        public function countAllResults(): int
        {
            return count($this->filtered());
        }

        public function insert(array $row): bool
        {
            // Enforce UNIQUE active_key on event_attendance.
            if ($this->t === 'event_attendance' && ($row['active_key'] ?? null) !== null) {
                foreach ($this->db->rows['event_attendance'] ?? [] as $r) {
                    if ((string) ($r['active_key'] ?? '') === (string) $row['active_key']) {
                        throw new \RuntimeException('duplicate active_key');
                    }
                }
            }
            $this->db->rows[$this->t][] = $row;

            return true;
        }

        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Events\Services\CheckinService;
    use WBS\Shared\Support\Clock;

    $root    = dirname(__DIR__, 5);
    $langDir = $root . '/app/Modules/Events/Language';
    $viewDir = $root . '/app/Modules/Events/Views';

    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Events/Services/CheckinService.php';

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

    Clock::freeze(new DateTimeImmutable('2026-09-18 18:00:00', new DateTimeZone('UTC')));
    $ORG = 'org-1';
    $EV  = 'ev-1';

    // ── 1. walk_in flag on manual check-in ───────────────────────────────────────
    echo "walk_in flag stamping\n";
    $db = new BaseConnection();
    $db->rows['events'] = [['id' => $EV, 'organization_id' => $ORG, 'title' => 'Rally', 'status' => 'completed', 'group_id' => null]];
    $db->rows['event_registrations'] = [
        ['id' => 'r1', 'organization_id' => $ORG, 'event_id' => $EV, 'user_id' => 'u-reg', 'status' => 'registered'],
    ];
    $db->rows['event_attendance'] = [];
    $svc = new CheckinService($db, new Clock());

    // Registered person → matched (walk_in = 0).
    $r = $svc->checkInManual($ORG, $EV, 'u-reg', 'staff-1', 'at the door', []);
    $chk('registered manual check-in ok', $r->ok);
    $regRow = array_values(array_filter($db->rows['event_attendance'], static fn ($a) => $a['user_id'] === 'u-reg'))[0] ?? [];
    $chk('registered check-in flagged walk_in=0', ($regRow['walk_in'] ?? null) === 0);

    // Unregistered person → walk-in (walk_in = 1).
    $r = $svc->checkInManual($ORG, $EV, 'u-walk', 'staff-1', 'walk up', []);
    $chk('walk-in manual check-in ok', $r->ok);
    $walkRow = array_values(array_filter($db->rows['event_attendance'], static fn ($a) => $a['user_id'] === 'u-walk'))[0] ?? [];
    $chk('unregistered check-in flagged walk_in=1', ($walkRow['walk_in'] ?? null) === 1);
    $chk('walk-in did NOT synthesize a registration row', count($db->rows['event_registrations']) === 1);

    // Re-check-in of the walk-in dedupes (UNIQUE active_key) — idempotent.
    $r = $svc->checkInManual($ORG, $EV, 'u-walk', 'staff-1', 'again', []);
    $chk('re-check-in dedupes idempotently', $r->ok && ($r->meta['deduplicated'] ?? false) === true);
    $chk('dedup did not add a second attendance', count(array_filter($db->rows['event_attendance'], static fn ($a) => $a['user_id'] === 'u-walk')) === 1);

    // ── 2. reconcile() buckets ───────────────────────────────────────────────────
    echo "reconcile() buckets\n";
    $db = new BaseConnection();
    $db->rows['events'] = [['id' => $EV, 'organization_id' => $ORG, 'title' => 'Convention', 'status' => 'completed', 'group_id' => null]];
    // 4 registered: u-a & u-b attend, u-c & u-d are no-shows.
    $db->rows['event_registrations'] = [
        ['event_id' => $EV, 'user_id' => 'u-a', 'status' => 'registered'],
        ['event_id' => $EV, 'user_id' => 'u-b', 'status' => 'registered'],
        ['event_id' => $EV, 'user_id' => 'u-c', 'status' => 'registered'],
        ['event_id' => $EV, 'user_id' => 'u-d', 'status' => 'registered'],
        ['event_id' => $EV, 'user_id' => 'u-x', 'status' => 'cancelled'], // ignored
    ];
    // attendance: u-a(qr matched), u-b(manual matched), u-w1(manual walk-in),
    //             u-w2(streaming walk-in), plus a VOIDED row that must be ignored.
    $db->rows['event_attendance'] = [
        ['event_id' => $EV, 'user_id' => 'u-a', 'status' => 'present', 'method' => 'qr', 'walk_in' => 0],
        ['event_id' => $EV, 'user_id' => 'u-b', 'status' => 'present', 'method' => 'manual', 'walk_in' => 0],
        ['event_id' => $EV, 'user_id' => 'u-w1', 'status' => 'present', 'method' => 'manual', 'walk_in' => 1],
        ['event_id' => $EV, 'user_id' => 'u-w2', 'status' => 'present', 'method' => 'streaming', 'walk_in' => 1],
        ['event_id' => $EV, 'user_id' => 'u-v', 'status' => 'voided', 'method' => 'manual', 'walk_in' => 1],
    ];
    // paid orders: u-a (attended), u-p (paid but absent), u-p twice (distinct=1).
    $db->rows['event_orders'] = [
        ['event_id' => $EV, 'user_id' => 'u-a', 'status' => 'paid'],
        ['event_id' => $EV, 'user_id' => 'u-p', 'status' => 'paid'],
        ['event_id' => $EV, 'user_id' => 'u-p', 'status' => 'paid'],
        ['event_id' => $EV, 'user_id' => 'u-q', 'status' => 'pending'], // ignored
    ];
    $svc = new CheckinService($db, new Clock());
    $res = $svc->reconcile($EV);
    $d   = $res->data;
    $chk('reconcile ok', $res->ok);
    $chk('present excludes voided (=4)', ($d['present'] ?? null) === 4);
    $chk('matched = 2', ($d['matched'] ?? null) === 2);
    $chk('walk_in = 2', ($d['walk_in'] ?? null) === 2);
    $chk('by_method qr=1', ($d['by_method']['qr'] ?? null) === 1);
    $chk('by_method manual=2', ($d['by_method']['manual'] ?? null) === 2);
    $chk('by_method streaming=1', ($d['by_method']['streaming'] ?? null) === 1);
    $chk('registered excludes cancelled (=4)', ($d['registered'] ?? null) === 4);
    $chk('no_show = 2 (u-c, u-d)', ($d['no_show'] ?? null) === 2);
    $chk('paid distinct = 2 (u-a, u-p)', ($d['paid'] ?? null) === 2);
    $chk('paid_absent = 1 (u-p only)', ($d['paid_absent'] ?? null) === 1);
    $chk('not reconciled (walk-ins/no-shows/paid-absent present)', ($d['reconciled'] ?? null) === false);

    // Clean event: everyone registered attended, no walk-ins, no paid-absent.
    $db2 = new BaseConnection();
    $db2->rows['events'] = [['id' => $EV, 'title' => 'Clean', 'status' => 'completed', 'group_id' => null]];
    $db2->rows['event_registrations'] = [['event_id' => $EV, 'user_id' => 'u1', 'status' => 'registered']];
    $db2->rows['event_attendance'] = [['event_id' => $EV, 'user_id' => 'u1', 'status' => 'present', 'method' => 'qr', 'walk_in' => 0]];
    $db2->rows['event_orders'] = [['event_id' => $EV, 'user_id' => 'u1', 'status' => 'paid']];
    $clean = (new CheckinService($db2, new Clock()))->reconcile($EV);
    $chk('clean event reconciled = true', ($clean->data['reconciled'] ?? null) === true);
    $chk('clean event no walk-ins / no-shows / paid-absent', ($clean->data['walk_in'] ?? 1) === 0 && ($clean->data['no_show'] ?? 1) === 0 && ($clean->data['paid_absent'] ?? 1) === 0);

    // Unknown event → 404.
    $nf = (new CheckinService(new BaseConnection(), new Clock()))->reconcile('nope');
    $chk('unknown event → 404', ! $nf->ok && $nf->status === 404);

    // ── 3. source: flag + reconcile + wiring ─────────────────────────────────────
    echo "source: flag + reconcile + wiring\n";
    $cs = (string) file_get_contents($root . '/app/Modules/Events/Services/CheckinService.php');
    $chk('recordAttendance derives walk_in from no registration', (bool) preg_match('/\$walkIn = \$reg === null;/', $cs));
    $chk('insert stamps walk_in column', (bool) preg_match("/'walk_in'\s*=>\s*\\\$walkIn \? 1 : 0/", $cs));
    $chk('reconcile() method exists', (bool) preg_match('/public function reconcile\(string \$eventId\)/', $cs));
    $chk('reconcile 404s unknown event', (bool) preg_match('/function reconcile\(.*?notFound\(/s', $cs));
    $chk('reconcile computes walk_in split', (bool) preg_match("/function reconcile\(.*?where\('walk_in', 1\)/s", $cs));
    $chk('reconcile excludes voided (status present)', (bool) preg_match("/function reconcile\(.*?where\('status', 'present'\)/s", $cs));
    $chk('reconcile uses NOT EXISTS anti-joins', substr_count($cs, 'NOT EXISTS') >= 2);
    $chk('reconcile counts DISTINCT payers', str_contains($cs, 'COUNT(DISTINCT'));
    $chk('reconcile reports a reconciled boolean', (bool) preg_match("/'reconciled'\s*=>/", $cs));

    $ctrl = (string) file_get_contents($root . '/app/Modules/Events/Controllers/CheckinController.php');
    $chk('controller reconcile() action exists', (bool) preg_match('/function reconcile\(string \$eventId/', $ctrl));
    $chk('controller renders attendance_reconcile view', str_contains($ctrl, 'WBS\\Events\\Views\\attendance_reconcile'));
    $chk('controller serves JSON for API clients', (bool) preg_match('/function reconcile\(.*?wantsJson\(\)/s', $ctrl));

    $routes = (string) file_get_contents($root . '/app/Config/Routes.php');
    $chk('GET (:segment)/attendance/reconcile → reconcile, gated', (bool) preg_match("#get\('\(:segment\)/attendance/reconcile'.*?CheckinController::reconcile.*?authorize:attendance\.check_in,any#s", $routes));

    // ── 4. migration ─────────────────────────────────────────────────────────────
    echo "migration: walk_in column\n";
    $migs = glob($root . '/app/Modules/Events/Database/Migrations/*AddAttendanceWalkIn.php');
    $chk('AddAttendanceWalkIn migration exists', $migs !== []);
    $mig = $migs !== [] ? (string) file_get_contents($migs[0]) : '';
    $chk('adds walk_in TINYINT default 0', (bool) preg_match('/ADD COLUMN walk_in TINYINT\(1\) NOT NULL DEFAULT 0/', $mig));
    $chk('adds ea_walkin_idx (event_id, walk_in)', (bool) preg_match('/KEY ea_walkin_idx \(event_id, walk_in\)/', $mig));
    $chk('reversible down()', str_contains($mig, 'DROP COLUMN walk_in') && str_contains($mig, 'DROP KEY ea_walkin_idx'));

    // ── 5. i18n parity: attReconcile.* across 6 locales ──────────────────────────
    echo "i18n parity (attReconcile, all locales)\n";
    $en   = require $langDir . '/en/Events.php';
    $enR  = $flat($en['attReconcile'] ?? []);
    $chk('en has an attReconcile block', $enR !== []);
    $chk('en has method sub-keys', in_array('method.qr', $enR, true) && in_array('method.manual', $enR, true) && in_array('method.streaming', $enR, true));
    foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $arr = require $langDir . "/$loc/Events.php";
        $keys = $flat($arr['attReconcile'] ?? []);
        $chk("$loc mirrors attReconcile keys", array_diff($enR, $keys) === [] && array_diff($keys, $enR) === [],
            'missing: ' . implode(',', array_diff($enR, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enR)));
    }

    // ── 6. view smoke ────────────────────────────────────────────────────────────
    echo "view smoke (attendance_reconcile.php)\n";
    // Reuse the shared self-contained-view renderer: it stubs esc()/service()/
    // config()/lang() so _locale.php resolves the locale under test (incl. RTL).
    require __DIR__ . '/../../Views/tests/view_test_helpers.php';
    $renderWith = wbs_events_renderer($langDir);
    // Bridge our local lang(key) helper (used in chk assertions) to the locale
    // the renderer last loaded, so expected strings match the rendered output.
    $lg = static function (string $key): string {
        $p = explode('.', $key);
        array_shift($p);
        $v = $GLOBALS['__evLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }

        return (string) $v;
    };
    $view = "$viewDir/attendance_reconcile.php";

    $recon = ['event_id' => $EV, 'title' => 'Convention', 'status' => 'completed', 'present' => 4, 'matched' => 2, 'walk_in' => 2,
        'by_method' => ['qr' => 1, 'manual' => 2, 'streaming' => 1], 'registered' => 4, 'no_show' => 2, 'paid' => 2, 'paid_absent' => 1, 'reconciled' => false];
    $h = $renderWith($view, ['recon' => $recon], 'en');
    $chk('view shows the event title verbatim', str_contains($h, 'Convention'));
    $chk('view shows the not-reconciled banner', str_contains($h, esc($lg('Events.attReconcile.reconciledNo'))) && str_contains($h, 'banner warn'));
    $chk('view shows matched + walk-in figures', str_contains($h, '>2<'));
    $chk('view shows method labels', str_contains($h, esc($lg('Events.attReconcile.method.manual'))));

    $reconOk = ['title' => 'Clean', 'present' => 1, 'matched' => 1, 'walk_in' => 0, 'by_method' => ['qr' => 1, 'manual' => 0, 'streaming' => 0], 'registered' => 1, 'no_show' => 0, 'paid' => 1, 'paid_absent' => 0, 'reconciled' => true];
    $h2 = $renderWith($view, ['recon' => $reconOk], 'en');
    $chk('reconciled event shows the green banner', str_contains($h2, 'banner ok') && str_contains($h2, esc($lg('Events.attReconcile.reconciledYes'))));

    $hnf = $renderWith($view, ['recon' => null], 'en');
    $chk('not-found renders the not-found panel', str_contains($hnf, esc($lg('Events.attReconcile.notFound'))));

    $har = $renderWith($view, ['recon' => $recon], 'ar');
    $chk('arabic renders RTL', str_contains($har, 'dir="rtl"'));
    $chk('arabic uses localized heading', str_contains($har, esc($lg('Events.attReconcile.heading'))));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
