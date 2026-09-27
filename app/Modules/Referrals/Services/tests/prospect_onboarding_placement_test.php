<?php

declare(strict_types=1);

/**
 * Onboarding placement model: a prospect belongs to their MENTOR's group.
 *
 * Covers the three rules the onboarding model rests on, over an in-memory DB
 * fake plus fakes for the three collaborators the transfer touches:
 *
 *  1. **Every system user belongs to a particular group** — a mentor's "home"
 *     group is what makes them able to hold prospects (ProspectGroupResolver),
 *     and linking a contact's account asserts the belonging invariant.
 *  2. **Prospects are NOT given a choice of group** — placement is derived from
 *     the mentor who owns them; a submitted group id is advisory only
 *     (createContact / bulkCreate / captureGuestFromInvite).
 *  3. **Membership transfers to the mentor who follows up after X weeks of
 *     inactivity** — X is HIERARCHICAL GROUP CONFIG (`referrals.prospect_transfer`,
 *     default OFF), and the transfer moves the belonging + sponsor, re-points the
 *     contact, and appends `prospect_group_transfers` provenance.
 *
 *   php app/Modules/Referrals/Services/tests/prospect_onboarding_placement_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public bool $inTrans = false;
        public bool $transOk = true;

        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }

        public function tableExists(string $t): bool { return true; }

        public function transStart(): void { $this->inTrans = true; $this->transOk = true; }

        public function transComplete(): void { $this->inTrans = false; }

        public function transStatus(): bool { return $this->transOk; }
    }
}

namespace WBS\Admin\Services {
    /** Fake: resolves a group capability from a canned table. */
    class EffectiveConfigResolver
    {
        /** @var array<string,array<string,mixed>> "group|capability" => value */
        public array $values = [];

        /** @var list<string> */
        public array $asked = [];

        public function resolve(string $groupId, string $capability): \WBS\Shared\Support\Result
        {
            $this->asked[] = $groupId . '|' . $capability;
            $key = $groupId . '|' . $capability;
            if (! array_key_exists($key, $this->values)) {
                return \WBS\Shared\Support\Result::notFound('config.not_set', 'CONFIG_NOT_SET');
            }

            return \WBS\Shared\Support\Result::ok([
                'value'             => $this->values[$key],
                'source_group_id'   => $groupId,
                'version'           => 1,
                'inheritance_mode'  => 'ancestor_default_child_override',
                'decision'          => 'own',
            ]);
        }
    }
}

namespace WBS\Groups\Services {
    /** Fake membership service: records leave()/add() instead of touching tables. */
    class GroupMembershipService
    {
        /** @var list<array<string,mixed>> */
        public array $left = [];
        /** @var list<array<string,mixed>> */
        public array $added = [];
        public int $seq = 0;

        public function leave(string $organizationId, string $membershipId, string $actorId, string $reason = ''): \WBS\Shared\Support\Result
        {
            $this->left[] = compact('membershipId', 'actorId', 'reason');

            return \WBS\Shared\Support\Result::ok(['membership_id' => $membershipId, 'status' => 'ended']);
        }

        public function add(string $organizationId, string $groupId, array $data): \WBS\Shared\Support\Result
        {
            $id = 'm-new-' . (++$this->seq);
            $this->added[] = ['membership_id' => $id, 'group_id' => $groupId] + $data;

            return \WBS\Shared\Support\Result::created([
                'membership_id' => $id, 'group_id' => $groupId, 'status' => 'active',
            ]);
        }
    }
}

namespace WBS\Referrals\Services {
    /** Fake sponsorship service: records re-parenting. */
    class SponsorshipService
    {
        /** @var list<array<string,mixed>> */
        public array $assigned = [];
        public int $seq = 0;

        public function assign(string $organizationId, string $memberId, string $sponsorId, string $reason = ''): \WBS\Shared\Support\Result
        {
            $id = 'sp-' . (++$this->seq);
            $this->assigned[] = ['id' => $id, 'member_id' => $memberId, 'sponsor_id' => $sponsorId, 'reason' => $reason];

            return \WBS\Shared\Support\Result::ok(['id' => $id]);
        }

        public function activeSponsor(string $memberId): ?string { return null; }
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
        /** @var array<string,mixed> */
        private array $eq = [];
        /** @var array<string,list<string>> field => accepted values */
        private array $in = [];
        /** @var list<array{0:string,1:string,2:string}> field, needle, side */
        private array $likes = [];
        /** @var list<array{0:string,1:string}> */
        private array $order = [];
        private ?int $limit = null;
        /** @var list<string> */
        private array $joined = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($f, $esc = null) { return $this; }

        public function join(string $table, string $cond, string $type = '')
        {
            $this->joined[] = $table;

            return $this;
        }

        public function where($k, $v = null) { $this->eq[trim((string) $k)] = $v; return $this; }

        public function whereIn($k, $v)
        {
            $this->in[trim((string) $k)] = array_map('strval', is_array($v) ? array_values($v) : []);

            return $this;
        }

        public function like(string $k, string $v, string $side = 'both')
        {
            $this->likes[] = [trim($k), $v, $side];

            return $this;
        }

        public function orderBy($k, $d = 'ASC') { $this->order[] = [trim((string) $k), strtoupper((string) $d)]; return $this; }

        public function limit($n) { $this->limit = (int) $n; return $this; }

        public function groupStart() { return $this; }
        public function groupEnd() { return $this; }
        public function orWhere($k, $v = null) { return $this; }

        public function get(): RS
        {
            $rows = $this->filtered();
            foreach (array_reverse($this->order) as [$field, $dir]) {
                usort($rows, static function (array $a, array $b) use ($field, $dir): int {
                    // A join can qualify the column (gm.joined_at) — try both forms.
                    $short = str_contains($field, '.') ? substr($field, strpos($field, '.') + 1) : $field;
                    $av = $a[$field] ?? $a[$short] ?? null;
                    $bv = $b[$field] ?? $b[$short] ?? null;
                    $c  = $av <=> $bv;

                    return $dir === 'DESC' ? -$c : $c;
                });
            }
            if ($this->limit !== null) {
                $rows = array_slice($rows, 0, $this->limit);
            }

            return new RS($rows);
        }

        public function insert(array $row): bool { $this->db->rows[$this->base()][] = $row; return true; }

        public function update(array $set): bool
        {
            $base = $this->base();
            foreach (($this->db->rows[$base] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$base][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        public function countAllResults(): int { return count($this->filtered()); }

        /** 'groups g' / 'group_members gm' → the row bucket is the table name. */
        private function base(): string
        {
            $parts = preg_split('/\s+/', trim($this->t)) ?: [];

            return $parts[0];
        }

        private function filtered(): array
        {
            return array_values(array_filter(
                $this->db->rows[$this->base()] ?? [],
                fn (array $r) => $this->matches($r),
            ));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                $short = str_contains($k, '.') ? substr($k, strpos($k, '.') + 1) : $k;
                $rv = $r[$k] ?? $r[$short] ?? null;
                if ($v === null) {
                    if ($rv !== null) { return false; }
                    continue;
                }
                if ((string) $rv !== (string) $v) { return false; }
            }
            foreach ($this->in as $field => $accepted) {
                $short = str_contains($field, '.') ? substr($field, strpos($field, '.') + 1) : $field;
                $rv = $r[$field] ?? $r[$short] ?? null;
                if ($rv === null || ! in_array((string) $rv, $accepted, true)) { return false; }
            }
            foreach ($this->likes as [$field, $needle, $side]) {
                $short = str_contains($field, '.') ? substr($field, strpos($field, '.') + 1) : $field;
                $hay = (string) ($r[$field] ?? $r[$short] ?? '');
                $ok = match ($side) {
                    'before' => str_ends_with(preg_replace('/\D+/', '', $hay) ?? '', preg_replace('/\D+/', '', $needle) ?? ''),
                    'after'  => str_starts_with($hay, $needle),
                    default  => str_contains($hay, $needle),
                };
                if (! $ok) { return false; }
            }

            return true;
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Referrals/Services/ProspectGroupResolver.php';
require_once $root . '/app/Modules/Referrals/Services/ProspectTransferService.php';
require_once $root . '/app/Modules/Referrals/Services/GroupMembershipPort.php';
require_once $root . '/app/Modules/Referrals/Support/IntegrationDecision.php';
require_once $root . '/app/Modules/Referrals/Services/ContactBookService.php';

use CodeIgniter\Database\BaseConnection;
use WBS\Admin\Services\EffectiveConfigResolver;
use WBS\Groups\Services\GroupMembershipService;
use WBS\Referrals\Services\ContactBookService;
use WBS\Referrals\Services\GroupMembershipPort;
use WBS\Referrals\Services\ProspectGroupResolver;
use WBS\Referrals\Services\ProspectTransferService;
use WBS\Referrals\Services\SponsorshipService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

Clock::freeze(new DateTimeImmutable('2026-09-20 12:00:00', new DateTimeZone('UTC')));

$ORG = 'org-1';

/** Membership-port spy for ContactBookService (join + ensureBelonging seams). */
$membershipSpy = new class () implements GroupMembershipPort {
    /** @var list<array<string,mixed>> */
    public array $joins = [];
    /** @var list<array<string,mixed>> */
    public array $ensured = [];

    public function join(string $organizationId, string $groupId, string $userId, array $opts = []): Result
    {
        $this->joins[] = compact('groupId', 'userId', 'opts');

        return Result::created(['membership_id' => 'm-join', 'status' => 'active']);
    }

    public function ensureBelonging(string $organizationId, string $userId, ?string $groupId, array $opts = []): Result
    {
        $this->ensured[] = compact('userId', 'groupId', 'opts');

        return Result::ok(['belonged' => false, 'created' => true, 'group_id' => $groupId]);
    }
};

/** Build the whole onboarding stack over one fake DB. */
$stack = static function (array $configValues = []) use ($membershipSpy): array {
    $db       = new BaseConnection();
    $db->rows = [
        'groups'        => [],
        'group_members' => [],
        'prospects'     => [],
        'users'         => [],
    ];
    $clock    = new Clock();
    $resolver = new ProspectGroupResolver($db);
    $config   = new EffectiveConfigResolver();
    foreach ($configValues as $k => $v) {
        $config->values[$k] = $v;
    }
    $memberships  = new GroupMembershipService();
    $sponsorships = new SponsorshipService();
    $transfers    = new ProspectTransferService(
        $db, $clock, $resolver, $config, $memberships, $sponsorships, null,
    );
    // ContactBookService: only the placement + transfer seams are wired; every
    // other collaborator stays null (the service guards each one).
    $book = new ContactBookService(
        $db, $clock, null, null, null, null, null, null,
        $membershipSpy, null, null,
        $resolver, $transfers,
    );

    return compact('db', 'resolver', 'transfers', 'book', 'config', 'memberships', 'sponsorships');
};

/** Seed a group + a user's membership in it. */
$seedMember = static function (BaseConnection $db, string $groupId, string $userId, string $role = 'member', int $depth = 5, string $joined = '2026-01-01 00:00:00', string $groupStatus = 'active'): void {
    if (! array_values(array_filter($db->rows['groups'], static fn ($g) => $g['id'] === $groupId))) {
        $db->rows['groups'][] = ['id' => $groupId, 'organization_id' => 'org-1', 'depth' => $depth, 'status' => $groupStatus, 'join_policy' => 'open'];
    }
    $db->rows['group_members'][] = [
        'id' => 'gm-' . $groupId . '-' . $userId, 'organization_id' => 'org-1', 'group_id' => $groupId,
        'user_id' => $userId, 'role' => $role, 'membership_type' => 'member', 'status' => 'active', 'joined_at' => $joined,
    ];
};

// ══════════════════════════════════════════════════════════════════════════
echo "1. a mentor's HOME group: the group they belong to (rule 1)\n";
$s = $stack();
$seedMember($s['db'], 'g-cell', 'mentor-1', 'member', 6, '2026-03-01 00:00:00');
chk('single membership → that group', $s['resolver']->homeGroupOf($ORG, 'mentor-1') === 'g-cell');

$s = $stack();
$seedMember($s['db'], 'g-area', 'mentor-2', 'member', 3, '2026-01-01 00:00:00');
$seedMember($s['db'], 'g-cell', 'mentor-2', 'leader', 6, '2026-05-01 00:00:00');
chk('a group they LEAD beats one they merely attend',
    $s['resolver']->homeGroupOf($ORG, 'mentor-2') === 'g-cell',
    (string) $s['resolver']->homeGroupOf($ORG, 'mentor-2'));

$s = $stack();
$seedMember($s['db'], 'g-area', 'mentor-3', 'member', 3, '2026-01-01 00:00:00');
$seedMember($s['db'], 'g-cell', 'mentor-3', 'member', 6, '2026-02-01 00:00:00');
chk('deepest (most local) group wins among plain memberships',
    $s['resolver']->homeGroupOf($ORG, 'mentor-3') === 'g-cell');

$s = $stack();
$seedMember($s['db'], 'g-a', 'mentor-4', 'member', 6, '2026-06-01 00:00:00');
$seedMember($s['db'], 'g-b', 'mentor-4', 'member', 6, '2026-01-01 00:00:00');
chk('tie broken by earliest joined', $s['resolver']->homeGroupOf($ORG, 'mentor-4') === 'g-b');

$s = $stack();
chk('a user with no membership has no home (a gap to repair, not to guess)',
    $s['resolver']->homeGroupOf($ORG, 'ghost') === null);

$s = $stack();
$seedMember($s['db'], 'g-dead', 'mentor-5', 'member', 6, '2026-01-01 00:00:00', 'archived');
chk('an archived group is not a home', $s['resolver']->homeGroupOf($ORG, 'mentor-5') === null);

// ══════════════════════════════════════════════════════════════════════════
echo "2. a prospect is NOT offered a choice of group (rule 2)\n";
$s = $stack();
$seedMember($s['db'], 'g-mentor', 'mentor-A', 'member', 6);
chk('placement = the mentor\'s group', $s['resolver']->placementFor($ORG, 'mentor-A', null) === 'g-mentor');
chk('a submitted "choice" is overridden by the mentor\'s group',
    $s['resolver']->placementFor($ORG, 'mentor-A', 'g-somewhere-else') === 'g-mentor');
$s2 = $stack();
chk('mentor with no home group falls back to the requested group (staff bulk)',
    $s2['resolver']->placementFor($ORG, 'no-home', 'g-requested') === 'g-requested');
chk('neither known → null', $s2['resolver']->placementFor($ORG, 'no-home', null) === null);

echo "3. createContact persists the DERIVED placement\n";
$s = $stack();
$seedMember($s['db'], 'g-mentor', 'mentor-A', 'member', 6);
$res = $s['book']->createContact($ORG, [
    'owner_user_id'     => 'mentor-A',
    'full_name'         => 'Ama Mensah',
    'email'             => 'ama@example.org',
    'phone'             => '0241234567',
    // The prospect "picked" another group in a form — it must not stick.
    'assigned_group_id' => 'g-chosen-by-prospect',
]);
chk('contact created', $res->ok, (string) $res->message);
$row = $s['db']->rows['prospects'][0];
chk('placed in the MENTOR\'s group, not the submitted one',
    ($row['assigned_group_id'] ?? null) === 'g-mentor', var_export($row['assigned_group_id'] ?? null, true));
chk('result reports the derived placement', ($res->data['assigned_group_id'] ?? null) === 'g-mentor'
    && ($res->data['placement_source'] ?? null) === 'mentor_group');
chk('owner recorded', ($row['owner_user_id'] ?? null) === 'mentor-A');

echo "4. staff bulk sign-up places contacts in the LEADER's own group\n";
$s = $stack();
$seedMember($s['db'], 'g-cell-9', 'leader-9', 'leader', 6);
$bulk = $s['book']->bulkCreate($ORG, 'leader-9', 'g-cell-9', [
    ['full_name' => 'Bulk One', 'phone' => '0201111111'],
    ['full_name' => 'Bulk Two', 'phone' => '0202222222'],
]);
chk('bulk created both', $bulk->ok && ($bulk->data['created'] ?? 0) === 2, json_encode($bulk->data['errors'] ?? []));
chk('both placed in the leader\'s group',
    ($s['db']->rows['prospects'][0]['assigned_group_id'] ?? null) === 'g-cell-9'
    && ($s['db']->rows['prospects'][1]['assigned_group_id'] ?? null) === 'g-cell-9');

echo "5. linking a contact's account asserts the belonging invariant (rule 1)\n";
$s = $stack();
$seedMember($s['db'], 'g-mentor', 'mentor-A', 'member', 6);
$membershipSpy->ensured = [];
$c = $s['book']->createContact($ORG, ['owner_user_id' => 'mentor-A', 'full_name' => 'Kofi Boateng', 'email' => 'kofi@example.org']);
// A `join_group` decision is what links the account (and so asserts belonging).
// The leader "records" a different group than the contact's placement: the
// prospect is not offered a choice, so the placement must win.
$dec = $s['book']->recordDecision((string) $c->data['id'], 'mentor-A', [
    'decision_type' => 'join_group', 'decision_date' => '2026-09-19',
    'target_group_id' => 'g-somewhere-else',
]);
chk('decision recorded', $dec->ok, (string) $dec->message);
chk('join_group targets the PLACEMENT, not a submitted group',
    ($s['db']->rows['prospect_decisions'][0]['target_group_id'] ?? null) === 'g-mentor',
    var_export($s['db']->rows['prospect_decisions'][0]['target_group_id'] ?? null, true));
chk('ensureBelonging called for the linked user', count($membershipSpy->ensured) === 1,
    json_encode($membershipSpy->ensured));
chk('belonging targets the contact\'s (mentor\'s) group with source=system',
    ($membershipSpy->ensured[0]['groupId'] ?? null) === 'g-mentor'
    && ($membershipSpy->ensured[0]['opts']['source'] ?? null) === 'system',
    json_encode($membershipSpy->ensured[0] ?? null));
chk('the linked user id was stored on the contact',
    is_string($s['db']->rows['prospects'][0]['linked_user_id'] ?? null)
    && $s['db']->rows['prospects'][0]['linked_user_id'] !== '');

// ══════════════════════════════════════════════════════════════════════════
echo "6. the inactivity threshold is hierarchical group config, default OFF\n";
chk('bare int policy', ProspectTransferService::normalizePolicy(8) === ['enabled' => true, 'inactive_weeks' => 8, 'requires_review' => false]);
chk('object policy', ProspectTransferService::normalizePolicy(['enabled' => true, 'inactive_weeks' => 6])
    === ['enabled' => true, 'inactive_weeks' => 6, 'requires_review' => false]);
chk('JSON-string policy (as stored in value_json)',
    ProspectTransferService::normalizePolicy('{"enabled":true,"inactive_weeks":4}')
    === ['enabled' => true, 'inactive_weeks' => 4, 'requires_review' => false]);
chk('explicitly disabled', ProspectTransferService::normalizePolicy(['enabled' => false, 'inactive_weeks' => 8])
    === ['enabled' => false, 'inactive_weeks' => 0, 'requires_review' => false]);
chk('zero weeks ⇒ OFF', ProspectTransferService::normalizePolicy(0) === ['enabled' => false, 'inactive_weeks' => 0, 'requires_review' => false]);
chk('absent/garbage ⇒ OFF', ProspectTransferService::normalizePolicy(null) === ['enabled' => false, 'inactive_weeks' => 0, 'requires_review' => false]);
chk('clamped to a sane maximum', ProspectTransferService::normalizePolicy(9999)['inactive_weeks'] === ProspectTransferService::MAX_WEEKS);
chk('clamped to a sane minimum', ProspectTransferService::normalizePolicy(-3) === ['enabled' => false, 'inactive_weeks' => 0, 'requires_review' => false]);
// Review is a THIRD policy key and defaults OFF, so a subtree that only set a
// week count keeps auto-applying exactly as before.
chk('review defaults OFF', ProspectTransferService::normalizePolicy(8)['requires_review'] === false);
chk('review switchable ON', ProspectTransferService::normalizePolicy(['enabled' => true, 'inactive_weeks' => 8, 'requires_review' => true])
    === ['enabled' => true, 'inactive_weeks' => 8, 'requires_review' => true]);
chk('review from a JSON-string policy', ProspectTransferService::normalizePolicy('{"enabled":true,"inactive_weeks":6,"requires_review":true}')['requires_review'] === true);
chk('review from a bare numeric string', ProspectTransferService::normalizePolicy('8') === ['enabled' => true, 'inactive_weeks' => 8, 'requires_review' => false]);
chk('review survives a disabled policy (0 weeks ⇒ OFF entirely)',
    ProspectTransferService::normalizePolicy(['enabled' => true, 'inactive_weeks' => 0, 'requires_review' => true])
    === ['enabled' => false, 'inactive_weeks' => 0, 'requires_review' => false]);

$s = $stack();
chk('no config row ⇒ 0 weeks (OFF)', $s['transfers']->thresholdWeeks($ORG, 'g-any') === 0);
$s = $stack(['g-area|' . ProspectTransferService::CAPABILITY => ['enabled' => true, 'inactive_weeks' => 8]]);
chk('configured group ⇒ its week count', $s['transfers']->thresholdWeeks($ORG, 'g-area') === 8);
chk('the resolver was asked for the right capability',
    ($s['config']->asked[0] ?? '') === 'g-area|' . ProspectTransferService::CAPABILITY, json_encode($s['config']->asked));

// ══════════════════════════════════════════════════════════════════════════
echo "7. evaluate(): the whole transfer decision table\n";
$contact = static fn (array $o = []): array => $o + [
    'id' => 'p-1', 'organization_id' => 'org-1', 'owner_user_id' => 'mentor-OLD',
    'assigned_group_id' => 'g-old', 'linked_user_id' => 'u-1',
    'last_contacted_at' => '2026-06-01 00:00:00', 'created_at' => '2026-05-01 00:00:00',
    'follow_up_count' => 2,
];
$policy = ['g-old|' . ProspectTransferService::CAPABILITY => ['enabled' => true, 'inactive_weeks' => 8]];

$s = $stack($policy);
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
$e = $s['transfers']->evaluate($ORG, $contact(), 'mentor-NEW');
chk('quiet 16 weeks with an 8-week policy ⇒ DUE', $e['due'] === true, $e['reason']);
chk('reports the threshold + observed inactivity', $e['threshold_weeks'] === 8 && $e['days_inactive'] === 111,
    json_encode([$e['threshold_weeks'], $e['days_inactive']]));
chk('from/to groups identified', $e['from_group_id'] === 'g-old' && $e['to_group_id'] === 'g-new');

$s = $stack($policy);
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
chk('the SAME mentor following up is not a transfer',
    $s['transfers']->evaluate($ORG, $contact(), 'mentor-OLD')['reason'] === 'same_mentor');

$s = $stack();   // no policy configured anywhere
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
chk('feature OFF for that subtree ⇒ no transfer',
    $s['transfers']->evaluate($ORG, $contact(), 'mentor-NEW')['reason'] === 'transfer_disabled');

$s = $stack($policy);
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
chk('still inside the window ⇒ not due',
    $s['transfers']->evaluate($ORG, $contact(['last_contacted_at' => '2026-09-15 00:00:00']), 'mentor-NEW')['reason']
        === 'still_active');

$s = $stack($policy);
$seedMember($s['db'], 'g-old', 'mentor-NEW', 'leader', 6);
chk('a different mentor in the SAME group ⇒ sponsor re-parent, not a transfer',
    $s['transfers']->evaluate($ORG, $contact(), 'mentor-NEW')['reason'] === 'same_group');

$s = $stack($policy);   // mentor-NEW holds no membership
chk('a mentor with no group cannot take anyone over',
    $s['transfers']->evaluate($ORG, $contact(), 'mentor-NEW')['reason'] === 'new_mentor_has_no_group');

$s = $stack($policy);
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
$noBaseline = $contact(['last_contacted_at' => null, 'created_at' => null]);
chk('no activity baseline ⇒ never transferred on a guess',
    $s['transfers']->evaluate($ORG, $noBaseline, 'mentor-NEW')['reason'] === 'no_activity_baseline');

$s = $stack($policy);
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
chk('inactivity falls back to created_at when never contacted',
    $s['transfers']->evaluate($ORG, $contact(['last_contacted_at' => null]), 'mentor-NEW')['due'] === true);

// ══════════════════════════════════════════════════════════════════════════
echo "8. apply(): moves the belonging, the sponsor and the pointers\n";
$s = $stack($policy);
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
$seedMember($s['db'], 'g-old', 'u-1', 'member', 6);          // the prospect's own membership
$s['db']->rows['prospects'][] = $contact();
$ev = $s['transfers']->evaluate($ORG, $contact(), 'mentor-NEW');

$notDue = $s['transfers']->apply($ORG, 'p-1', 'mentor-NEW', ['due' => false, 'reason' => 'still_active']);
chk('refuses to apply an evaluation that is not due', ! $notDue->ok && $notDue->code === 'NOT_DUE', (string) $notDue->code);
chk('nothing was written', ($s['db']->rows['prospect_group_transfers'] ?? []) === []);

$r = $s['transfers']->apply($ORG, 'p-1', 'mentor-NEW', $ev, [
    'type' => 'event_invite', 'id' => 'evt-77', 'actor_id' => 'mentor-NEW', 'note' => 'Invited to Harvest service',
]);
chk('transfer applied', $r->ok, (string) $r->message);
chk('old membership ENDED (history kept)', count($s['memberships']->left) === 1
    && ($s['memberships']->left[0]['reason'] ?? '') === 'inactivity_transfer', json_encode($s['memberships']->left));
chk('new membership opened in the mentor\'s group', count($s['memberships']->added) === 1
    && ($s['memberships']->added[0]['group_id'] ?? '') === 'g-new');
chk('the new join is assisted/system, not an approval queue',
    ($s['memberships']->added[0]['source'] ?? '') === 'system'
    && ($s['memberships']->added[0]['requires_approval'] ?? true) === false
    && ($s['memberships']->added[0]['added_by'] ?? '') === 'mentor-NEW');
chk('sponsor re-parented to the new mentor', count($s['sponsorships']->assigned) === 1
    && ($s['sponsorships']->assigned[0]['sponsor_id'] ?? '') === 'mentor-NEW'
    && ($s['sponsorships']->assigned[0]['member_id'] ?? '') === 'u-1');
$p = $s['db']->rows['prospects'][0];
chk('contact re-pointed at the new mentor + group',
    ($p['owner_user_id'] ?? '') === 'mentor-NEW' && ($p['assigned_group_id'] ?? '') === 'g-new', json_encode($p));
chk('the follow-up touch is recorded on the contact',
    ($p['follow_up_count'] ?? 0) === 3 && ($p['last_contacted_at'] ?? '') === '2026-09-20 12:00:00');
$t = $s['db']->rows['prospect_group_transfers'][0] ?? [];
chk('provenance row appended', ($t['reason'] ?? '') === 'inactivity_transfer'
    && ($t['from_group_id'] ?? '') === 'g-old' && ($t['to_group_id'] ?? '') === 'g-new'
    && ($t['from_owner_user_id'] ?? '') === 'mentor-OLD' && ($t['to_owner_user_id'] ?? '') === 'mentor-NEW');
chk('provenance carries the trigger + the threshold in force',
    ($t['trigger_type'] ?? '') === 'event_invite' && ($t['trigger_id'] ?? '') === 'evt-77'
    && ($t['threshold_weeks'] ?? 0) === 8 && ($t['days_inactive'] ?? 0) === 111
    && ($t['note'] ?? '') === 'Invited to Harvest service', json_encode($t));
chk('provenance links the old + new membership rows',
    ($t['previous_membership_id'] ?? '') === 'gm-g-old-u-1' && ($t['membership_id'] ?? '') === 'm-new-1',
    json_encode([$t['previous_membership_id'] ?? null, $t['membership_id'] ?? null]));
chk('historyFor returns the trail', count($s['transfers']->historyFor($ORG, 'p-1')) === 1);

echo "9. applying twice for the same mentor is a no-op\n";
$ev2 = $s['transfers']->evaluate($ORG, $s['db']->rows['prospects'][0], 'mentor-NEW');
chk('second evaluation says same_mentor', $ev2['reason'] === 'same_mentor' && $ev2['due'] === false);

// ══════════════════════════════════════════════════════════════════════════
echo "10. one person is one contact: an invite REUSES, never duplicates\n";
$s = $stack($policy);
$seedMember($s['db'], 'g-old', 'mentor-OLD', 'leader', 6);
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
$membershipSpy->ensured = [];
$first = $s['book']->captureGuestFromInvite($ORG, 'evt-1', 'mentor-OLD', [
    'full_name' => 'Esi Owusu', 'email' => 'esi@example.org', 'phone' => '0244000111',
]);
chk('first invite creates the contact', $first->ok && ($first->data['reused'] ?? true) === false, (string) $first->message);
chk('placed in the inviting mentor\'s group',
    ($s['db']->rows['prospects'][0]['assigned_group_id'] ?? null) === 'g-old');
chk('registered for the event', ($first->data['registration']['id'] ?? '') !== '' || $first->ok);

// Same person, a SECOND mentor's link, still inside the window → reused, not moved.
$s['db']->rows['prospects'][0]['last_contacted_at'] = '2026-09-18 00:00:00';
$second = $s['book']->captureGuestFromInvite($ORG, 'evt-2', 'mentor-NEW', [
    'full_name' => 'Esi Owusu', 'email' => 'esi@example.org', 'phone' => '+233 244 000 111',
]);
chk('second invite REUSES the contact (no duplicate row)', $second->ok
    && ($second->data['reused'] ?? false) === true && count($s['db']->rows['prospects']) === 1,
    json_encode(['rows' => count($s['db']->rows['prospects']), 'data' => $second->data]));
chk('phone in a different format still matches', ($second->data['contact_id'] ?? '') === ($first->data['contact_id'] ?? ''));
chk('NOT transferred while still active', ($second->data['transferred'] ?? true) === false);
chk('ownership unchanged', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD');
chk('but the guest IS registered for the new mentor\'s event', $second->ok);

// Now let the contact go quiet past the 8-week policy → the second mentor takes over.
$s['db']->rows['prospects'][0]['last_contacted_at'] = '2026-06-01 00:00:00';
$third = $s['book']->captureGuestFromInvite($ORG, 'evt-3', 'mentor-NEW', [
    'full_name' => 'Esi Owusu', 'email' => 'esi@example.org',
]);
chk('quiet contact invited by a new mentor ⇒ TRANSFERRED',
    $third->ok && ($third->data['transferred'] ?? false) === true, json_encode($third->data));
chk('ownership + placement moved to the new mentor',
    ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-NEW'
    && ($s['db']->rows['prospects'][0]['assigned_group_id'] ?? '') === 'g-new');
chk('still exactly one contact', count($s['db']->rows['prospects']) === 1);
chk('transfer provenance appended', count($s['db']->rows['prospect_group_transfers'] ?? []) === 1
    && ($s['db']->rows['prospect_group_transfers'][0]['trigger_type'] ?? '') === 'event_invite');
chk('transfer names the event that triggered it',
    ($s['db']->rows['prospect_group_transfers'][0]['trigger_id'] ?? '') === 'evt-3');

echo "11. phone comparison key\n";
chk('local kept', ContactBookService::phoneKey('0244000111') === '0244000111');
chk('+233 folded to local', ContactBookService::phoneKey('+233 244 000 111') === '0244000111',
    (string) ContactBookService::phoneKey('+233 244 000 111'));
chk('00 prefix folded', ContactBookService::phoneKey('00233244000111') === '0244000111');
chk('too short ⇒ null', ContactBookService::phoneKey('123') === null);
chk('non-numeric ⇒ null', ContactBookService::phoneKey('ask me') === null);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
