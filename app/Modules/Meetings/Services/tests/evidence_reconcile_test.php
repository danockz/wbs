<?php

declare(strict_types=1);

/**
 * MeetingService::reconcileEvidence test (Theme C, MT2).
 *
 * The `applied` flag on meeting_attendance_evidence was never flipped — provider
 * presence never became attendance. Proves over an in-memory DB fake:
 *   - a streaming-policy event's evidence records attendance via the port AND
 *     stamps applied=1;
 *   - a checkin/manual-policy event's evidence is marked advisory (applied=1) but
 *     records NO attendance;
 *   - evidence with no linked user / no linked event stays UNAPPLIED (unresolved);
 *   - a port failure leaves the row unapplied for retry;
 *   - org scoping + idempotent re-run (nothing left with applied=0);
 *   - group attribution from the event's group is forwarded.
 *
 *   php app/Modules/Meetings/Services/tests/evidence_reconcile_test.php
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

    /**
     * A tiny join-aware query builder. It knows the three tables in play
     * (meeting_attendance_evidence aliased mae, meetings m, events e) and
     * resolves the select projection used by reconcileEvidence().
     */
    class QB
    {
        /** @var array<string,mixed> */
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($f)
        {
            return $this;
        }

        public function join($t, $cond, $type = '')
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function orderBy($a, $b = null)
        {
            return $this;
        }

        private function baseTable(): string
        {
            // strip an alias like "meeting_attendance_evidence mae"
            $parts = explode(' ', trim($this->t));

            return $parts[0];
        }

        public function get($limit = null): RS
        {
            $table = $this->baseTable();
            if ($table === 'meeting_attendance_evidence') {
                return new RS($this->joinedEvidence($limit));
            }

            // simple filtered read (not used by reconcile, but kept general)
            $rows = array_values(array_filter($this->db->rows[$table] ?? [], fn ($r) => $this->matches($r, '')));

            return new RS($rows);
        }

        public function update(array $set): bool
        {
            $table = $this->baseTable();
            foreach (($this->db->rows[$table] ?? []) as $i => $r) {
                if ($this->matches($r, '')) {
                    $this->db->rows[$table][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        /** Build the mae⋈m⋈e projection the service selects. */
        private function joinedEvidence(?int $limit): array
        {
            $meetings = [];
            foreach ($this->db->rows['meetings'] ?? [] as $m) {
                $meetings[(string) $m['id']] = $m;
            }
            $events = [];
            foreach ($this->db->rows['events'] ?? [] as $e) {
                $events[(string) $e['id']] = $e;
            }

            $out = [];
            foreach ($this->db->rows['meeting_attendance_evidence'] ?? [] as $mae) {
                if ((int) ($mae['applied'] ?? 0) !== (int) ($this->eq['mae.applied'] ?? 0)) {
                    continue;
                }
                $m       = $meetings[(string) $mae['meeting_id']] ?? [];
                $eventId = $m['event_id'] ?? null;
                $e       = $eventId !== null ? ($events[(string) $eventId] ?? []) : [];

                // org filter (from e.organization_id) when set
                if (isset($this->eq['e.organization_id'])
                    && (string) ($e['organization_id'] ?? '') !== (string) $this->eq['e.organization_id']) {
                    continue;
                }

                $out[] = [
                    'evidence_id'       => $mae['id'],
                    'user_id'           => $mae['user_id'] ?? null,
                    'meeting_id'        => $mae['meeting_id'],
                    'event_id'          => $eventId,
                    'organization_id'   => $e['organization_id'] ?? null,
                    'attendance_policy' => $e['attendance_policy'] ?? null,
                    'group_id'          => $e['group_id'] ?? null,
                ];
            }
            if ($limit !== null) {
                $out = array_slice($out, 0, $limit);
            }

            return $out;
        }

        private function matches(array $r, string $prefix): bool
        {
            foreach ($this->eq as $k => $v) {
                $key = str_contains($k, '.') ? explode('.', $k)[1] : $k;
                if ((string) ($r[$key] ?? '') !== (string) $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace WBS\Meetings\Services {
    // Minimal stand-ins for the ctor collaborators unused by reconcileEvidence.
    if (! interface_exists(EventAttendancePort::class)) {
        require dirname(__DIR__) . '/EventAttendancePort.php';
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Meetings\Services\EventAttendancePort;
    use WBS\Meetings\Services\MeetingService;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\Result;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Meetings/Services/EventAttendancePort.php';
    require_once $root . '/app/Modules/Meetings/Services/MeetingService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    // Spy attendance port.
    $makePort = static fn (bool $failAll = false): object => new class ($failAll) implements EventAttendancePort {
        public array $calls = [];

        public function __construct(private bool $failAll)
        {
        }

        public function recordFromMeetingEvidence(string $org, string $event, string $user, array $groups = []): Result
        {
            $this->calls[] = ['org' => $org, 'event' => $event, 'user' => $user, 'groups' => $groups];

            return $this->failAll ? Result::fail('BOOM', 'boom', 500) : Result::created(['attendance_id' => 'a-1']);
        }
    };

    $ORG = 'org-1';
    $seed = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        $db->rows['events'] = [
            ['id' => 'ev-stream', 'organization_id' => $ORG, 'attendance_policy' => 'streaming', 'group_id' => 'g-1'],
            ['id' => 'ev-checkin', 'organization_id' => $ORG, 'attendance_policy' => 'checkin', 'group_id' => null],
            ['id' => 'ev-other', 'organization_id' => 'org-2', 'attendance_policy' => 'streaming', 'group_id' => null],
        ];
        $db->rows['meetings'] = [
            ['id' => 'm-stream', 'event_id' => 'ev-stream'],
            ['id' => 'm-checkin', 'event_id' => 'ev-checkin'],
            ['id' => 'm-standalone', 'event_id' => null],
            ['id' => 'm-other', 'event_id' => 'ev-other'],
        ];
        $db->rows['meeting_attendance_evidence'] = [
            ['id' => 'e-stream', 'meeting_id' => 'm-stream', 'user_id' => 'u1', 'applied' => 0],
            ['id' => 'e-checkin', 'meeting_id' => 'm-checkin', 'user_id' => 'u2', 'applied' => 0],
            ['id' => 'e-nouser', 'meeting_id' => 'm-stream', 'user_id' => null, 'applied' => 0],
            ['id' => 'e-standalone', 'meeting_id' => 'm-standalone', 'user_id' => 'u3', 'applied' => 0],
            ['id' => 'e-done', 'meeting_id' => 'm-stream', 'user_id' => 'u4', 'applied' => 1], // already applied
            ['id' => 'e-other', 'meeting_id' => 'm-other', 'user_id' => 'u5', 'applied' => 0], // other org
        ];

        return $db;
    };
    $evOf = static function (BaseConnection $db, string $id): array {
        foreach ($db->rows['meeting_attendance_evidence'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };

    // ---- happy path, scoped to org-1 ---------------------------------------
    $db = $seed();
    $port = $makePort();
    $svc = new MeetingService($db, new Clock(), null, null, $port);
    $res = $svc->reconcileEvidence($ORG, 500);
    $chk('ok', $res->ok === true, (string) ($res->code ?? ''));
    $chk('applies 1 streaming attendance', ($res->data['applied_attendance'] ?? -1) === 1, json_encode($res->data));
    $chk('marks 1 advisory (checkin policy)', ($res->data['marked_advisory'] ?? -1) === 1, json_encode($res->data));
    // Under org scope the standalone (no linked event -> null org) is filtered
    // out by the WHERE e.organization_id, so only the no-user row is unresolved.
    $chk('skips 1 unresolved under org scope (no user)', ($res->data['skipped_unresolved'] ?? -1) === 1, json_encode($res->data));
    $chk('port called once', count($port->calls) === 1);
    $chk('port got event + user', ($port->calls[0]['event'] ?? '') === 'ev-stream' && ($port->calls[0]['user'] ?? '') === 'u1');
    $chk('port got group attribution from event group', ($port->calls[0]['groups'] ?? []) === ['g-1']);
    $chk('streaming evidence now applied', (int) $evOf($db, 'e-stream')['applied'] === 1);
    $chk('checkin evidence marked applied (advisory)', (int) $evOf($db, 'e-checkin')['applied'] === 1);
    $chk('no-user evidence stays unapplied', (int) $evOf($db, 'e-nouser')['applied'] === 0);
    $chk('standalone-meeting evidence stays unapplied', (int) $evOf($db, 'e-standalone')['applied'] === 0);
    $chk('other-org evidence untouched under org scope', (int) $evOf($db, 'e-other')['applied'] === 0);

    // ---- idempotent re-run --------------------------------------------------
    $res2 = $svc->reconcileEvidence($ORG, 500);
    $chk('re-run applies 0', ($res2->data['applied_attendance'] ?? -1) === 0);
    $chk('re-run advisory 0', ($res2->data['marked_advisory'] ?? -1) === 0);

    // ---- all-orgs pass picks up org-2 --------------------------------------
    $db3 = $seed();
    $port3 = $makePort();
    $res3 = (new MeetingService($db3, new Clock(), null, null, $port3))->reconcileEvidence(null, 500);
    $chk('all-orgs applies 2 streaming (org-1 + org-2)', ($res3->data['applied_attendance'] ?? -1) === 2, json_encode($res3->data));

    // ---- port failure leaves the row unapplied for retry -------------------
    $db4 = $seed();
    $portF = $makePort(true);
    $res4 = (new MeetingService($db4, new Clock(), null, null, $portF))->reconcileEvidence($ORG, 500);
    $chk('port failure applies 0', ($res4->data['applied_attendance'] ?? -1) === 0);
    $chk('failed streaming evidence stays unapplied', (int) $evOf($db4, 'e-stream')['applied'] === 0);
    $chk('failure counted as unresolved', ($res4->data['skipped_unresolved'] ?? -1) >= 1);

    // ---- no port wired -> streaming stays unapplied, checkin still advisory --
    $db5 = $seed();
    $res5 = (new MeetingService($db5, new Clock()))->reconcileEvidence($ORG, 500);
    $chk('no port: streaming NOT applied', (int) $evOf($db5, 'e-stream')['applied'] === 0);
    $chk('no port: checkin still marked advisory', (int) $evOf($db5, 'e-checkin')['applied'] === 1);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
