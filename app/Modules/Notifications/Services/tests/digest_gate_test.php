<?php

declare(strict_types=1);

/**
 * RetentionPolicyGate digest decision test (Theme C — N4).
 *
 * The gate now returns a distinct `digest` action for a verified, opted-in,
 * non-essential recipient whose preference is `daily`/`weekly`, with a
 * defer_until at the next window boundary (computed in the user's timezone).
 * Over an in-memory DB fake, proves:
 *   - digest_frequency=daily  -> action 'digest', reason 'digest_daily', a
 *     defer_until at the next LOCAL midnight (in the future);
 *   - digest_frequency=weekly -> action 'digest', reason 'digest_weekly', a
 *     defer_until at the next LOCAL Monday 00:00;
 *   - digest_frequency=instant -> action 'send' (unchanged);
 *   - digest_frequency=off -> action 'suppress' reason 'digest_off' (unchanged);
 *   - an ESSENTIAL category ignores digest and sends;
 *   - digest supersedes quiet-hours (held for the window regardless).
 *
 *   php app/Modules/Notifications/Services/tests/digest_gate_test.php
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

    class QB
    {
        private array $eq = [];
        private array $in = [];
        private array $notNull = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if ($escape === false && str_contains($k, 'IS NOT NULL')) {
                $this->notNull[] = trim(str_replace('IS NOT NULL', '', $k));

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

        public function get($limit = null): RS
        {
            return new RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
        }

        public function countAllResults(): int
        {
            return count(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }
            foreach ($this->notNull as $f) {
                if (($r[$f] ?? null) === null || (string) $r[$f] === '') {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Notifications\Services\RetentionPolicyGate;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Notifications/Services/RetentionPolicyGate.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    // Freeze at a known instant: Wed 2026-09-16 12:00 UTC.
    Clock::freeze(new \DateTimeImmutable('2026-09-16 12:00:00.000000', new \DateTimeZone('UTC')));

    $ORG = 'org-1';
    $U   = 'u1';

    // Seed a verified channel + a preference row with a given digest frequency.
    $seed = static function (string $freq, ?string $quietStart = null, ?string $quietEnd = null): BaseConnection {
        $db = new BaseConnection();
        $db->rows['notification_suppressions'] = [];
        $db->rows['notification_channels'] = [
            ['organization_id' => 'org-1', 'user_id' => 'u1', 'channel' => 'email', 'verified_at' => '2026-01-01 00:00:00'],
        ];
        $db->rows['notification_preferences'] = [
            ['organization_id' => 'org-1', 'user_id' => 'u1', 'channel' => 'email', 'category' => 'journey',
                'opted_in' => 1, 'stopped' => 0, 'digest_frequency' => $freq,
                'quiet_start' => $quietStart, 'quiet_end' => $quietEnd, 'timezone' => 'UTC'],
        ];
        $db->rows['notification_deliveries'] = [];

        return $db;
    };

    $gate = static fn (BaseConnection $db): RetentionPolicyGate => new RetentionPolicyGate($db, new Clock());

    // ---- daily -------------------------------------------------------------
    $d = $gate($seed('daily'))->evaluate($ORG, $U, 'email', 'journey');
    $chk('daily -> action digest', $d['action'] === 'digest', json_encode($d));
    $chk('daily -> reason digest_daily', $d['reason'] === 'digest_daily');
    // Next local midnight after Wed 12:00 UTC = Thu 2026-09-17 00:00 UTC.
    $chk('daily defer_until = next midnight', str_starts_with((string) $d['defer_until'], '2026-09-17 00:00:00'), (string) $d['defer_until']);

    // ---- weekly ------------------------------------------------------------
    $w = $gate($seed('weekly'))->evaluate($ORG, $U, 'email', 'journey');
    $chk('weekly -> action digest', $w['action'] === 'digest', json_encode($w));
    $chk('weekly -> reason digest_weekly', $w['reason'] === 'digest_weekly');
    // Next Monday after Wed 2026-09-16 = Mon 2026-09-21 00:00 UTC.
    $chk('weekly defer_until = next Monday 00:00', str_starts_with((string) $w['defer_until'], '2026-09-21 00:00:00'), (string) $w['defer_until']);

    // ---- instant (unchanged) ----------------------------------------------
    $i = $gate($seed('instant'))->evaluate($ORG, $U, 'email', 'journey');
    $chk('instant -> action send', $i['action'] === 'send', json_encode($i));

    // ---- off (unchanged) ---------------------------------------------------
    $o = $gate($seed('off'))->evaluate($ORG, $U, 'email', 'journey');
    $chk('off -> suppress digest_off', $o['action'] === 'suppress' && $o['reason'] === 'digest_off', json_encode($o));

    // ---- essential ignores digest -----------------------------------------
    // Preference is daily but category 'security' is essential -> sends now.
    $db = $seed('daily');
    $db->rows['notification_preferences'][0]['category'] = 'security';
    $e = $gate($db)->evaluate($ORG, $U, 'email', 'security');
    $chk('essential category sends despite daily pref', $e['action'] === 'send', json_encode($e));

    // ---- digest supersedes quiet-hours ------------------------------------
    // Within a quiet window AND daily digest -> still digest (held for window).
    $q = $gate($seed('daily', '00:00:00', '23:59:00'))->evaluate($ORG, $U, 'email', 'journey');
    $chk('daily digest supersedes quiet-hours defer', $q['action'] === 'digest', json_encode($q));

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
