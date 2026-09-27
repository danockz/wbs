<?php

declare(strict_types=1);

/**
 * MeetingService::onEventCancelled test (Theme B consumer — MT5).
 *
 * A cancelled event's linked video room must not stay enterable. Proves over an
 * in-memory DB fake:
 *   - scheduled + live meetings linked to the event flip to 'canceled';
 *   - every UNEXPIRED join token on those meetings is expired (stamped to now);
 *   - an already-expired token is left as-is (no double count);
 *   - meetings for OTHER events, and already ended/canceled meetings, untouched;
 *   - idempotent re-run cancels 0 / expires 0;
 *   - empty org/event -> bad-input fail.
 *
 *   php app/Modules/Meetings/Services/tests/meeting_event_cancel_test.php
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

    class QB
    {
        /** @var array<string,mixed> */
        private array $eq = [];
        /** @var array<string,list<string>> */
        private array $in = [];
        /** @var list<array{k:string,v:string}> */
        private array $gt = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null)
        {
            $k = trim((string) $k);
            if (str_ends_with($k, '>')) {
                $this->gt[] = ['k' => trim(rtrim($k, '>')), 'v' => (string) $v];

                return $this;
            }
            $this->eq[$k] = $v;

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        /** @return list<array<string,mixed>> */
        private function filtered(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
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
            foreach ($this->eq as $k => $v) {
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }
            foreach ($this->gt as $c) {
                $rv = $r[$c['k']] ?? null;
                if ($rv === null || (string) $rv <= $c['v']) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Meetings\Services\MeetingService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Meetings/Services/MeetingService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $FUTURE = '2999-01-01 00:00:00.000000';
    $PAST   = '2000-01-01 00:00:00.000000';

    $seed = static function () use ($ORG, $FUTURE, $PAST): BaseConnection {
        $db = new BaseConnection();
        $db->rows['meetings'] = [
            ['id' => 'm-sched', 'organization_id' => $ORG, 'event_id' => 'ev-dead', 'status' => 'scheduled'],
            ['id' => 'm-live', 'organization_id' => $ORG, 'event_id' => 'ev-dead', 'status' => 'live'],
            ['id' => 'm-ended', 'organization_id' => $ORG, 'event_id' => 'ev-dead', 'status' => 'ended'], // terminal
            ['id' => 'm-other', 'organization_id' => $ORG, 'event_id' => 'ev-live', 'status' => 'scheduled'], // other event
        ];
        $db->rows['meeting_participants'] = [
            ['id' => 'p1', 'meeting_id' => 'm-sched', 'user_id' => 'u1', 'token_expires_at' => $FUTURE],
            ['id' => 'p2', 'meeting_id' => 'm-sched', 'user_id' => 'u2', 'token_expires_at' => $PAST], // already expired
            ['id' => 'p3', 'meeting_id' => 'm-live', 'user_id' => 'u3', 'token_expires_at' => $FUTURE],
            ['id' => 'p4', 'meeting_id' => 'm-other', 'user_id' => 'u4', 'token_expires_at' => $FUTURE], // bystander
        ];

        return $db;
    };
    $statusOf = static function (BaseConnection $db, string $id): string {
        foreach ($db->rows['meetings'] as $r) {
            if ($r['id'] === $id) {
                return (string) $r['status'];
            }
        }

        return '';
    };
    $tokenOf = static function (BaseConnection $db, string $pid): string {
        foreach ($db->rows['meeting_participants'] as $r) {
            if ($r['id'] === $pid) {
                return (string) $r['token_expires_at'];
            }
        }

        return '';
    };

    // ---- cancel + expire ----------------------------------------------------
    $db = $seed();
    $svc = new MeetingService($db, new Clock());
    $res = $svc->onEventCancelled($ORG, 'ev-dead', 'event.cancelled');
    $chk('ok', $res->ok === true, (string) ($res->code ?? ''));
    $chk('cancels 2 meetings (sched + live)', ($res->data['meetings_canceled'] ?? -1) === 2, json_encode($res->data));
    $chk('expires 2 tokens (the unexpired ones)', ($res->data['tokens_expired'] ?? -1) === 2, json_encode($res->data));
    $chk('m-sched canceled', $statusOf($db, 'm-sched') === 'canceled');
    $chk('m-live canceled', $statusOf($db, 'm-live') === 'canceled');
    $chk('m-ended untouched (terminal)', $statusOf($db, 'm-ended') === 'ended');
    $chk('m-other untouched (other event)', $statusOf($db, 'm-other') === 'scheduled');
    $chk('p1 token expired (moved off future)', $tokenOf($db, 'p1') !== $FUTURE);
    $chk('p3 token expired (moved off future)', $tokenOf($db, 'p3') !== $FUTURE);
    $chk('p2 already-expired token untouched', $tokenOf($db, 'p2') === $PAST);
    $chk('p4 bystander token untouched', $tokenOf($db, 'p4') === $FUTURE);

    // ---- idempotent re-run --------------------------------------------------
    $res2 = $svc->onEventCancelled($ORG, 'ev-dead', 'event.cancelled');
    $chk('re-run cancels 0', ($res2->data['meetings_canceled'] ?? -1) === 0);
    $chk('re-run expires 0', ($res2->data['tokens_expired'] ?? -1) === 0);

    // ---- bad input ----------------------------------------------------------
    $chk('empty org fails', $svc->onEventCancelled('', 'ev-dead', 'x')->ok === false);
    $chk('empty event fails', $svc->onEventCancelled($ORG, '', 'x')->ok === false);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
