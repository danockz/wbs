<?php

declare(strict_types=1);

/**
 * IntegrationService test (FR-REF-3b) — the standalone dated decisions
 * (salvation, water baptism, Holy Spirit baptism, foundation course), their
 * self-declaration/confirmation/derivation paths, the checklist verdict and the
 * Journey gate, against an in-memory query builder (no real DB).
 *
 *   php app/Modules/Referrals/Services/tests/integration_service_test.php
 */

// ---- Framework stubs declared BEFORE the class under test ---------------------
namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public int $inserts = 0;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
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
        /** @var array<string,mixed> */
        private array $eq = [];

        /** @var array<string,list<mixed>> */
        private array $in = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($s) { return $this; }
        public function orderBy($k, $d = 'ASC', $e = true) { return $this; }
        public function limit($n) { return $this; }

        public function where($k, $v = null, $escape = true)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function whereIn($k, array $v)
        {
            $this->in[trim((string) $k)] = $v;

            return $this;
        }

        public function get($limit = null, $offset = 0): RS
        {
            return new RS($this->rowsFor());
        }

        public function countAllResults(bool $reset = true): int
        {
            return count($this->rowsFor());
        }

        public function insert(array $set, bool $escape = true): bool
        {
            $this->db->rows[$this->t][] = $set;
            $this->db->inserts++;

            return true;
        }

        public function update(?array $set = null, $where = null, $limit = null): bool
        {
            foreach ($this->db->rows[$this->t] ?? [] as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, (array) $set);
                }
            }

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $list) {
                if (! in_array($r[$k] ?? null, $list, true)) {
                    return false;
                }
            }

            return true;
        }

        private function rowsFor(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }
    }
}

// ---- Collaborator stubs (types IntegrationService hints, but not loaded) ------
namespace WBS\Admin\Services {
    class EffectiveConfigResolver
    {
        /** @var mixed what resolve() reports as the capability value */
        public mixed $value = null;

        public function resolve(string $groupId, string $capability): \WBS\Shared\Support\Result
        {
            return \WBS\Shared\Support\Result::ok([
                'value'            => $this->value,
                'source_group_id'  => $groupId,
                'version'          => 1,
                'inheritance_mode' => 'ancestor_default_child_override',
                'decision'         => 'child_override',
            ]);
        }
    }
}

namespace WBS\Shared\Support {
    class GroupScopeResolver
    {
        public function primaryMembershipGroup(string $organizationId, string $userId): ?string
        {
            return 'g-cell';
        }
    }
}

namespace WBS\Referrals\Services {
    class SponsorshipService
    {
        /** @var array<string,string> userId => sponsorId */
        public array $sponsorOf = [];

        public function activeSponsor(string $memberId): ?string
        {
            return $this->sponsorOf[$memberId] ?? null;
        }
    }
}

namespace WBS\Audit\Services {
    class AuditLogger
    {
        /** @var list<array{org:string,actor:string,action:string,target:string}> */
        public array $entries = [];

        public function record(string $org, string $actor, string $action, string $target = ''): void
        {
            $this->entries[] = ['org' => $org, 'actor' => $actor, 'action' => $action, 'target' => $target];
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Referrals/Support/IntegrationDecision.php';
require_once $root . '/app/Modules/Referrals/Support/IntegrationConfig.php';
require_once $root . '/app/Modules/Referrals/Services/IntegrationService.php';
require_once $root . '/app/Modules/Referrals/Services/ContactBookService.php';

use WBS\Admin\Services\EffectiveConfigResolver;
use WBS\Audit\Services\AuditLogger;
use WBS\Referrals\Services\ContactBookService;
use WBS\Referrals\Services\IntegrationService;
use WBS\Referrals\Services\SponsorshipService;
use WBS\Referrals\Support\IntegrationConfig;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

function cfgOn(array $over = []): array
{
    return array_merge(['enabled' => true], $over);
}

// ---- World --------------------------------------------------------------------
$db      = new \CodeIgniter\Database\BaseConnection();
$clock   = new Clock();
$config  = new EffectiveConfigResolver();
$scope   = new GroupScopeResolver();
$sponsor = new SponsorshipService();
$audit   = new AuditLogger();

$db->rows['prospects'] = [
    ['id' => 'c1', 'organization_id' => 'o1', 'owner_user_id' => 'mentor-1', 'assigned_group_id' => 'g-cell', 'linked_user_id' => 'u1', 'full_name' => 'Ama Mensah'],
    ['id' => 'c2', 'organization_id' => 'o1', 'owner_user_id' => 'mentor-1', 'assigned_group_id' => 'g-cell', 'linked_user_id' => null, 'full_name' => 'Kojo'],
    ['id' => 'c3', 'organization_id' => 'o1', 'owner_user_id' => 'mentor-2', 'assigned_group_id' => 'g-cell', 'linked_user_id' => null, 'full_name' => 'Esi'],
];
$sponsor->sponsorOf = ['u1' => 'sp-1'];

$svc = fn () => new IntegrationService($db, $clock, $config, $scope, $sponsor, $audit);
$decisions = fn () => $db->rows['prospect_decisions'] ?? [];

// ---- 1. Fail-closed config ----------------------------------------------------
echo "config: off means off\n";
$config->value = null;
$s = $svc();
chk('declareForContact refuses when disabled', $s->declareForContact('o1', 'c1', ['decision_type' => 'salvation', 'decision_date' => '2026-09-01', 'source' => 'landing'])->failed());
chk('declareSelf refuses when disabled', $s->declareSelf('o1', 'u1', ['decision_type' => 'salvation', 'decision_date' => '2026-09-01'])->failed());
chk('gate allows everything when disabled', $s->gate('o1', 'u1', 'established', 'g-cell') === null);
chk('isIntegrated is true when disabled', $s->isIntegrated('o1', 'u1', 'g-cell') === true);
chk('deriveFromEnrolment writes nothing when disabled', ($s->deriveFromEnrolment('o1', 'u1', 'course1', 'e1', '2026-09-01 10:00:00', 'foundation', 'g-cell') ?? true) === true && $decisions() === []);

// ---- 2. Self-declaration against a contact -------------------------------------
echo "\ndeclareForContact: pending until the mentor confirms\n";
$config->value = cfgOn();
$s = $svc();
$r = $s->declareForContact('o1', 'c1', ['decision_type' => 'salvation', 'decision_date' => '2026-09-01', 'note' => 'at the outreach', 'source' => 'landing']);
chk('declaration recorded', $r->ok, (string) $r->message);
chk('…born pending (maker-checker)', $r->data['status'] === 'pending');
chk('…with the landing source', $r->data['source'] === 'landing');
$row = $decisions()[0];
chk('row is contact-attached', ($row['prospect_id'] ?? null) === 'c1' && ($row['user_id'] ?? null) === null);
chk('row keeps the declared date', ($row['decision_date'] ?? '') === '2026-09-01');
chk('row has no staff recorder', ($row['recorded_by'] ?? null) === null);

chk('unknown type refused', $s->declareForContact('o1', 'c1', ['decision_type' => 'kumasi', 'decision_date' => '2026-09-01', 'source' => 'landing'])->failed());
chk('missing date refused', $s->declareForContact('o1', 'c1', ['decision_type' => 'salvation', 'decision_date' => '', 'source' => 'landing'])->failed());
chk('future date refused', $s->declareForContact('o1', 'c1', ['decision_type' => 'salvation', 'decision_date' => '2099-01-01', 'source' => 'landing'])->failed());
chk('unknown contact refused', $s->declareForContact('o1', 'nope', ['decision_type' => 'salvation', 'decision_date' => '2026-09-01', 'source' => 'landing'])->failed());

$config->value = cfgOn(['allow_self_declaration' => false]);
chk('self-declaration off → refused', $s->declareForContact('o1', 'c1', ['decision_type' => 'water_baptism', 'decision_date' => '2026-09-01', 'source' => 'landing'])->failed());

// confirmation not required → born confirmed
$config->value = cfgOn(['self_declaration_requires_confirmation' => false]);
chk('when confirmation is waived the row is born confirmed', $s->declareForContact('o1', 'c2', ['decision_type' => 'salvation', 'decision_date' => '2026-09-01', 'source' => 'landing'])->data['status'] === 'confirmed');

// ---- 3. Member self-service ----------------------------------------------------
echo "\ndeclareSelf: user-attached, sponsor confirms\n";
$config->value = cfgOn();
$s = $svc();
$db->rows['prospect_decisions'] = [];
$r = $s->declareSelf('o1', 'u1', ['decision_type' => 'water_baptism', 'decision_date' => '2026-09-05']);
chk('self declaration recorded', $r->ok);
$self = $decisions()[0];
chk('…attached to the USER', ($self['user_id'] ?? null) === 'u1' && ($self['prospect_id'] ?? null) === null);
chk('…pending + source self', ($self['status'] ?? '') === 'pending' && ($self['source'] ?? '') === 'self');

// ---- 4. Confirm / reject -------------------------------------------------------
echo "\nconfirm/reject: owner or sponsor, never anyone else\n";
$config->value = cfgOn();
$s = $svc();
$did = $s->declareForContact('o1', 'c1', ['decision_type' => 'holy_spirit_baptism', 'decision_date' => '2026-09-06', 'source' => 'landing'])->data['id'];
chk('non-owner cannot confirm', $s->confirm('o1', 'mentor-2', $did)->failed());
chk('owner confirms', $s->confirm('o1', 'mentor-1', $did)->ok);
$row = null;
foreach ($decisions() as $d) {
    if (($d['id'] ?? '') === $did) { $row = $d; }
}
chk('row now confirmed + decided_by owner', ($row['status'] ?? '') === 'confirmed' && ($row['decided_by'] ?? '') === 'mentor-1');
chk('confirming twice → not pending', $s->confirm('o1', 'mentor-1', $did)->failed());
chk('confirm is audited', count(array_filter($audit->entries, fn ($e) => $e['action'] === 'referrals.integration.decision_confirmed')) === 1);

$did2 = $s->declareForContact('o1', 'c1', ['decision_type' => 'salvation', 'decision_date' => '2026-09-07', 'source' => 'event_guest'])->data['id'];
chk('reject works', $s->reject('o1', 'mentor-1', $did2)->ok);
$row2 = null;
foreach ($decisions() as $d) {
    if (($d['id'] ?? '') === $did2) { $row2 = $d; }
}
chk('rejected row stays history', ($row2['status'] ?? '') === 'rejected');

// user-attached: the sponsor is the checker
$did3 = $s->declareSelf('o1', 'u1', ['decision_type' => 'salvation', 'decision_date' => '2026-09-08'])->data['id'];
chk('non-sponsor cannot confirm a user declaration', $s->confirm('o1', 'mentor-1', $did3)->failed());
chk('the sponsor confirms', $s->confirm('o1', 'sp-1', $did3)->ok);

// ---- 5. Checklist + verdict ----------------------------------------------------
echo "\nchecklist: pending/rejected never count\n";
$config->value = cfgOn();
$s = $svc();
$db->rows['prospect_decisions'] = [];

// A pending declaration alone satisfies nothing (it must be confirmed).
$s->declareForContact('o1', 'c1', ['decision_type' => 'holy_spirit_baptism', 'decision_date' => '2026-09-06', 'source' => 'landing']);
$cl = $s->checklistForContact('o1', 'c1');
chk('pending declaration satisfies nothing', $cl['integrated'] === false && $cl['outstanding'] === ['salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course']);

// Seed confirmed rows directly (assisted path writes them confirmed).
$db->rows['prospect_decisions'][] = ['id' => 'x1', 'organization_id' => 'o1', 'prospect_id' => 'c2', 'user_id' => null, 'decision_type' => 'salvation', 'decision_date' => '2026-08-01', 'status' => 'confirmed', 'source' => 'assisted', 'source_ref' => null];
$db->rows['prospect_decisions'][] = ['id' => 'x2', 'organization_id' => 'o1', 'prospect_id' => 'c2', 'user_id' => null, 'decision_type' => 'water_baptism', 'decision_date' => '2026-08-10', 'status' => 'confirmed', 'source' => 'assisted', 'source_ref' => null];
$db->rows['prospect_decisions'][] = ['id' => 'x3', 'organization_id' => 'o1', 'prospect_id' => 'c2', 'user_id' => null, 'decision_type' => 'holy_spirit_baptism', 'decision_date' => '2026-08-15', 'status' => 'confirmed', 'source' => 'assisted', 'source_ref' => null];
$db->rows['prospect_decisions'][] = ['id' => 'x4', 'organization_id' => 'o1', 'prospect_id' => 'c2', 'user_id' => null, 'decision_type' => 'foundation_course', 'decision_date' => '2026-08-20', 'status' => 'confirmed', 'source' => 'assisted', 'source_ref' => null];
$cl = $s->checklistForContact('o1', 'c2');
chk('all four standalone decisions → integrated', $cl['integrated'] === true && $cl['outstanding'] === []);
chk('byType carries the dates', ($cl['byType']['salvation'] ?? '') === '2026-08-01' && ($cl['byType']['foundation_course'] ?? '') === '2026-08-20');

// user view: u1 links to c1 (contact rows), plus own user rows.
$clU = $s->checklistForUser('o1', 'u1', 'g-cell');
chk('user checklist merges contact + own rows', is_array($clU['byType']));

// ---- 6. The Journey gate -------------------------------------------------------
echo "\ngate: gated stages refuse an unintegrated member\n";
$config->value = cfgOn();
$s = $svc();
$g = $s->gate('o1', 'u1', 'established', 'g-cell');
chk('unintegrated → blocked on a gated stage', is_array($g) && ($g['blocked'] ?? false) === true);
chk('…with the outstanding groups listed', is_array($g) && $g['outstanding'] !== []);
chk('non-gated stage passes', $s->gate('o1', 'u1', 'worker', 'g-cell') === null);
chk('gate disabled in config → passes', ($config->value = cfgOn(['gate_journey_advance' => false])) === cfgOn(['gate_journey_advance' => false]) && $s->gate('o1', 'u1', 'established', 'g-cell') === null);

$config->value = cfgOn();
// integrate u1 fully via derived + confirmed rows
$s->deriveFromEnrolment('o1', 'u1', 'course1', 'e1', '2026-09-10 09:00:00', 'foundation', 'g-cell');
foreach ([['u1', 'salvation'], ['u1', 'water_baptism'], ['u1', 'holy_spirit_baptism']] as [$uid, $type]) {
    $db->rows['prospect_decisions'][] = ['id' => 'y-' . $type, 'organization_id' => 'o1', 'prospect_id' => null, 'user_id' => $uid, 'decision_type' => $type, 'decision_date' => '2026-09-01', 'status' => 'confirmed', 'source' => 'assisted', 'source_ref' => null];
}
chk('integrated member passes the gate', $s->gate('o1', 'u1', 'established', 'g-cell') === null);

// ---- 7. Derivation -------------------------------------------------------------
echo "\nderivation: enrolment/completion write the foundation_course row once\n";
$config->value = cfgOn();
$s = $svc();
$db->rows['prospect_decisions'] = [];
$s->deriveFromEnrolment('o1', 'u1', 'course1', 'e1', '2026-09-10 09:00:00', 'foundation', 'g-cell');
chk('foundation enrolment derives a row', count($decisions()) === 1);
$der = $decisions()[0];
chk('…confirmed + derived_course + enr ref', ($der['status'] ?? '') === 'confirmed' && ($der['source'] ?? '') === 'derived_course' && ($der['source_ref'] ?? '') === 'enr:e1');
chk('…date is the enrolment date', ($der['decision_date'] ?? '') === '2026-09-10');
$s->deriveFromEnrolment('o1', 'u1', 'course1', 'e1', '2026-09-10 09:00:00', 'foundation', 'g-cell');
chk('re-derive is idempotent', count($decisions()) === 1);

$s->deriveFromEnrolment('o1', 'u1', 'course2', 'e2', '2026-09-11 09:00:00', 'leadership', 'g-cell');
chk('non-foundation category is ignored', count($decisions()) === 1);

$s->deriveFromEnrolment('o1', 'u2', 'course1', 'e3', '2026-09-11 09:00:00', 'foundation', null);
chk('no course group → fail closed (no row)', count($decisions()) === 1);

$config->value = cfgOn(['derive_from_enrolment' => false]);
$s->deriveFromEnrolment('o1', 'u1', 'course3', 'e4', '2026-09-12 09:00:00', 'foundation', 'g-cell');
chk('derive_from_enrolment off → no row', count($decisions()) === 1);

$config->value = cfgOn(['derive_from_completion' => true]);
$s->deriveFromCompletion('o1', 'u1', 'course3', 'e5', '2026-09-13 12:00:00', 'foundation', 'g-cell');
chk('completion derives when opted in', count($decisions()) === 2);
$der2 = $decisions()[1];
chk('…derived_completion + cmp ref', ($der2['source'] ?? '') === 'derived_completion' && ($der2['source_ref'] ?? '') === 'cmp:e5');

$config->value = cfgOn();
$s->deriveFromCompletion('o1', 'u1', 'course3', 'e6', '2026-09-14 12:00:00', 'foundation', 'g-cell');
chk('completion ignored by default', count($decisions()) === 2);

// ---- 8. Pending queue for the mentor ------------------------------------------
echo "\nqueue: the owning mentor sees exactly their own pending declarations\n";
$config->value = cfgOn();
$s = $svc();
$db->rows['prospect_decisions'] = [];
$s->declareForContact('o1', 'c1', ['decision_type' => 'salvation', 'decision_date' => '2026-09-01', 'source' => 'landing']);
$s->declareForContact('o1', 'c2', ['decision_type' => 'water_baptism', 'decision_date' => '2026-09-02', 'source' => 'event_guest']);
$s->declareForContact('o1', 'c3', ['decision_type' => 'salvation', 'decision_date' => '2026-09-03', 'source' => 'landing']);
$q = $s->pendingForOwner('o1', 'mentor-1');
chk('mentor-1 sees exactly their two', count($q) === 2);
chk('…with contact names attached', count(array_filter($q, fn ($r) => ($r['contact_name'] ?? '') !== '')) === 2);
chk('another mentor sees none', $s->pendingForOwner('o1', 'mentor-3') === []);

// ---- 9. Assisted path still enforces the catalog -------------------------------
echo "\nassisted path (ContactBookService): catalog + future-date guards\n";
$cb = new ContactBookService($db, $clock);
$r = $cb->recordDecision('c1', 'mentor-1', ['decision_type' => 'bogus_type', 'decision_date' => '2026-09-01']);
chk('assisted recording refuses unknown types', $r->failed() && $r->code === 'DECISION_TYPE_INVALID', (string) $r->code);
$r = $cb->recordDecision('c1', 'mentor-1', ['decision_type' => 'salvation', 'decision_date' => '2099-12-31']);
chk('assisted recording refuses future dates', $r->failed() && $r->code === 'DECISION_DATE_FUTURE', (string) $r->code);
$before = count($decisions());
$r = $cb->recordDecision('c1', 'mentor-1', ['decision_type' => 'salvation', 'decision_date' => '2026-09-01', 'note' => 'assisted']);
chk('assisted recording still works', $r->ok);
$last = $decisions()[count($decisions()) - 1];
chk('…born confirmed + assisted', ($last['status'] ?? '') === 'confirmed' && ($last['source'] ?? '') === 'assisted');

// ---- 10. Capture-form inputs (onboarding decision, 2026-09-24) ---------------
echo "\ncapture_inputs: hierarchical group config gates optional onboarding inputs\n";
$config->value = null;
$s = $svc();
chk('captureInputsFor empty when feature OFF', $s->captureInputsFor('g-cell') === []);
chk('captureInputAllowed false when OFF', $s->captureInputAllowed('g-cell', 'salvation') === false);
chk('captureInputGate refuses when OFF', ($s->captureInputGate('g-cell', 'salvation', '2026-09-01')?->failed()) === true);

$config->value = cfgOn(['capture_inputs' => ['salvation', 'bogus', 'water_baptism']]);
$s = $svc();
chk('captureInputsFor intersects with the catalog', $s->captureInputsFor('g-cell') === ['salvation', 'water_baptism'], implode(',', $s->captureInputsFor('g-cell')));
chk('unlisted type refused', $s->captureInputAllowed('g-cell', 'holy_spirit_baptism') === false);
chk('listed type allowed', $s->captureInputAllowed('g-cell', 'salvation') === true);
chk('gate validates the date too', ($s->captureInputGate('g-cell', 'salvation', '2099-01-01')?->failed()) === true);
chk('gate null when listed + valid date', $s->captureInputGate('g-cell', 'salvation', '2026-09-01') === null);

$db->rows['prospect_decisions'] = [];
$r = $s->recordAssistedForContact('o1', 'c1', 'mentor-1', ['decision_type' => 'salvation', 'decision_date' => '2026-09-01', 'decision_note' => 'at outreach']);
chk('assisted capture decision recorded', $r->ok, (string) $r->message);
$row = $decisions()[0] ?? [];
chk('…born confirmed + source assisted', ($row['status'] ?? '') === 'confirmed' && ($row['source'] ?? '') === 'assisted', json_encode([$row['status'] ?? null, $row['source'] ?? null]));
chk('…recorded_by the acting mentor', ($row['recorded_by'] ?? null) === 'mentor-1', (string) ($row['recorded_by'] ?? 'null'));
chk('…note carried', ($row['note'] ?? '') === 'at outreach', (string) ($row['note'] ?? ''));

$r = $s->recordAssistedForContact('o1', 'c1', 'mentor-1', ['decision_type' => 'holy_spirit_baptism', 'decision_date' => '2026-09-01']);
chk('unlisted type refused at capture', $r->failed() && $r->code === 'CAPTURE_INPUT_DISABLED', (string) $r->code);
chk('…writes nothing', count($decisions()) === 1);

$config->value = cfgOn(['capture_inputs' => []]);
$s = $svc();
$r = $s->recordAssistedForContact('o1', 'c1', 'mentor-1', ['decision_type' => 'salvation', 'decision_date' => '2026-09-01']);
chk('empty capture_inputs refuses everything (fail-closed)', $r->failed(), (string) $r->code);

$off = IntegrationConfig::normalize(null);
chk('OFF config yields no capture inputs', IntegrationConfig::captureInputsOf($off) === []);
$on = IntegrationConfig::normalize(['enabled' => true, 'capture_inputs' => ['salvation', 'join_group', 'nope']]);
chk('normalize keeps only catalog types', IntegrationConfig::captureInputsOf($on) === ['salvation', 'join_group'], implode(',', IntegrationConfig::captureInputsOf($on)));
chk('enabled without capture_inputs key -> none offered', IntegrationConfig::captureInputsOf(IntegrationConfig::normalize(['enabled' => true])) === []);

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);

}
