<?php

declare(strict_types=1);

/**
 * AccountService::register() opens a membership journey (finding M4).
 *
 * The "one open seam": a brand-new account previously had NO journey row until
 * the member did something tracked, so first-stage pipeline counts under-reported
 * new members. register() now emits `journey.signal.member.registered` (through a
 * JourneySignalPort seam — Identity stays decoupled from Journey) so a membership
 * entry rule opens the journey. This pins:
 *   - a successful registration emits ONE signal for the new user id, action
 *     member.registered, org-wide context (group_id null), evidence registration;
 *   - a group-scoped registration tags source=group + the group id;
 *   - the emit is best-effort: a throwing port never fails the registration;
 *   - no port wired ⇒ registration still succeeds (signal optional);
 *   - a FAILED registration (bad email) emits nothing.
 *
 *   php app/Modules/Identity/Services/tests/register_journey_signal_test.php
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

        public function transStart(): void
        {
        }

        public function transComplete(): void
        {
        }

        public function transStatus(): bool
        {
            return true;
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

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function select($f, $e = null)
        {
            return $this;
        }

        public function get(): RS
        {
            return new RS($this->matching());
        }

        public function countAllResults(): int
        {
            return count($this->matching());
        }

        public function insert(array $row): bool
        {
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

        private function matching(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

// Stub IdentityPolicyService under its exact FQCN (real file never loaded).
namespace WBS\Identity\Services {
    final class IdentityPolicyService
    {
        public function resolve(string $organizationId, ?string $countryCode = null): array
        {
            return [
                'min_age'              => 13,
                'allow_minor'         => false,
                'require_email_unique' => true,
                'require_phone_unique' => false,
                'phone_default_region' => 'US',
            ];
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Identity\Security\PasswordHasher;
    use WBS\Identity\Security\PhoneNormalizer;
    use WBS\Identity\Services\AccountService;
    use WBS\Identity\Services\IdentityPolicyService;
    use WBS\Identity\Services\JourneySignalPort;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\Result;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Identity/Security/PasswordHasher.php';
    require_once $root . '/app/Modules/Identity/Security/PhoneNormalizer.php';
    require_once $root . '/app/Modules/Identity/Services/JourneySignalPort.php';
    require_once $root . '/app/Modules/Identity/Services/AccountService.php';

    $spy = new class implements JourneySignalPort {
        /** @var list<array<string,mixed>> */
        public array $calls = [];
        public bool $throw = false;

        public function ingest(string $organizationId, array $data): Result
        {
            $this->calls[] = ['org' => $organizationId] + $data;
            if ($this->throw) {
                throw new \RuntimeException('journey boom');
            }

            return Result::ok(['matched' => 1]);
        }
    };

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $mk = static function (?JourneySignalPort $port) use ($ORG): array {
        $db = new BaseConnection();
        $db->rows['users'] = [];
        $db->rows['account_state_transitions'] = [];
        $db->rows['user_consents'] = [];
        $svc = new AccountService(
            $db,
            new Clock(),
            new PasswordHasher(),
            new PhoneNormalizer(),
            new IdentityPolicyService(),
            null, // breachChecker
            null, // sponsorResolver — not wired (no sponsor linking)
            null, // sponsorships
            $port,
        );
        return [$db, $svc];
    };

    // ---- 1) plain registration emits member.registered --------------------
    [$db, $svc] = $mk($spy);
    $spy->calls = [];
    $r = $svc->register($ORG, ['email' => 'newbie@example.com', 'display_name' => 'New Bie']);
    $chk('register ok', $r->ok, json_encode($r->errors ?? []));
    $uid = (string) ($r->data['user_id'] ?? '');
    $chk('one journey signal emitted', count($spy->calls) === 1, (string) count($spy->calls));
    $c = $spy->calls[0] ?? [];
    $chk('action = member.registered', ($c['action'] ?? '') === 'journey.signal.member.registered');
    $chk('signal targets the new user', ($c['user_id'] ?? '') === $uid && $uid !== '');
    $chk('org-wide context (group_id null)', array_key_exists('group_id', $c) && $c['group_id'] === null);
    $chk('evidence type = registration', ($c['evidence_type'] ?? '') === 'registration');
    $chk('evidence ref points at user', ($c['evidence_ref'] ?? '') === 'user:' . $uid);
    $chk('source = direct (no group)', ($c['attributes']['source'] ?? '') === 'direct');

    // ---- 2) group-scoped registration tags the group ----------------------
    [$db, $svc] = $mk($spy);
    $spy->calls = [];
    $r = $svc->register($ORG, ['email' => 'grp@example.com', 'group_id' => 'g-42', 'actor_id' => 'leader-9']);
    $chk('group register ok', $r->ok);
    $c = $spy->calls[0] ?? [];
    $chk('source = group', ($c['attributes']['source'] ?? '') === 'group');
    $chk('group id tagged', ($c['attributes']['group_id'] ?? '') === 'g-42');
    $chk('actor threaded', ($c['actor_id'] ?? '') === 'leader-9');

    // ---- 3) throwing port never fails the registration --------------------
    [$db, $svc] = $mk($spy);
    $spy->calls = [];
    $spy->throw = true;
    $r = $svc->register($ORG, ['email' => 'robust@example.com']);
    $chk('throwing port: registration still ok', $r->ok);
    $chk('throwing port: the user row exists', count(array_filter($db->rows['users'], fn ($u) => $u['email'] === 'robust@example.com')) === 1);
    $chk('throwing port: emit was attempted', count($spy->calls) === 1);
    $spy->throw = false;

    // ---- 4) no port wired: registration still works -----------------------
    [$db, $svc] = $mk(null);
    $r = $svc->register($ORG, ['email' => 'noport@example.com']);
    $chk('no port: registration ok', $r->ok && count($db->rows['users']) === 1);

    // ---- 5) a FAILED registration emits nothing ---------------------------
    [$db, $svc] = $mk($spy);
    $spy->calls = [];
    $r = $svc->register($ORG, ['email' => 'not-an-email']);
    $chk('bad email rejected', ! $r->ok && $r->code === 'INVALID_EMAIL');
    $chk('failed registration emits no journey signal', count($spy->calls) === 0);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
