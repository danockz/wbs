<?php

declare(strict_types=1);

/**
 * Prospect-transfer MAKER-CHECKER queue (FR-REF-7 review path).
 *
 * Where a subtree sets `referrals.prospect_transfer.requires_review`, a due
 * inactivity transfer is QUEUED for a second leader instead of being applied on
 * the spot. This test pins the whole contract over an in-memory DB fake plus
 * fakes for the collaborators the transfer touches:
 *
 *  1. the gate itself — requires_review is hierarchical group config, DEFAULT OFF,
 *     so an unconfigured subtree keeps auto-applying exactly as before;
 *  2. queuing — the invite path stores a pending request and moves NOTHING;
 *     a second invite from the same mentor dedupes onto the open request;
 *  3. segregation of duties — the maker cannot approve their own request;
 *  4. approval RE-CHECKS eligibility — a contact followed up while the request
 *     sat in the queue is refused (and marked blocked), and only an approval
 *     delegates to ProspectTransferService::apply(), stamping request_id on the
 *     provenance row;
 *  5. reject / cancel / expire close the request and never move the person;
 *     a closed request cannot be decided twice;
 *  6. the append-only review trail + audit entries record every step;
 *  7. a manual proposal is judged by the SAME policy — no side door.
 *
 *   php app/Modules/Referrals/Services/tests/prospect_transfer_review_test.php
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
        /** @var array<string,list<mixed>> field => OR-ed values (groupStart/orWhere) */
        private array $ors = [];
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

        /**
         * A real OR branch. The fake treats `where(k,a) ... orWhere(k,b)` as
         * `(row[k] == a OR row[k] == b)`, which is what the production queries in
         * this module use it for (nominated-approver + unassigned).
         */
        public function orWhere($k, $v = null) { $this->ors[trim((string) $k)][] = $v; return $this; }

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

        /** Loose scalar comparison matching the fake's string-normalized `where`. */
        private static function same(mixed $rowValue, mixed $want): bool
        {
            if ($want === null) {
                return $rowValue === null;
            }

            return $rowValue !== null && (string) $rowValue === (string) $want;
        }

        private function matches(array $r): bool
        {
            foreach ($this->ors as $k => $values) {
                $short = str_contains($k, '.') ? substr($k, strpos($k, '.') + 1) : $k;
                $rv    = $r[$k] ?? $r[$short] ?? null;
                $hit   = array_key_exists($k, $this->eq) && self::same($rv, $this->eq[$k]);
                foreach ($values as $v) {
                    if (self::same($rv, $v)) {
                        $hit = true;
                        break;
                    }
                }
                if (! $hit) { return false; }
            }
            foreach ($this->eq as $k => $v) {
                // A key with an OR branch was already evaluated as a group above.
                if (isset($this->ors[$k])) { continue; }
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

namespace WBS\Audit\Services {
    /** Fake: captures the hash-chained audit calls instead of writing rows. */
    class AuditLogger
    {
        /** @var list<array<string,mixed>> */
        public array $entries = [];

        public function record(string $organizationId, array $data): \WBS\Shared\Support\Result
        {
            $this->entries[] = ['organization_id' => $organizationId] + $data;

            return \WBS\Shared\Support\Result::ok(['seq' => count($this->entries)]);
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
require_once $root . '/app/Modules/Referrals/Services/ProspectTransferReviewService.php';
require_once $root . '/app/Modules/Referrals/Services/GroupMembershipPort.php';
require_once $root . '/app/Modules/Referrals/Services/ContactBookService.php';

use CodeIgniter\Database\BaseConnection;
use WBS\Admin\Services\EffectiveConfigResolver;
use WBS\Audit\Services\AuditLogger;
use WBS\Groups\Services\GroupMembershipService;
use WBS\Referrals\Services\ContactBookService;
use WBS\Referrals\Services\GroupMembershipPort;
use WBS\Referrals\Services\ProspectGroupResolver;
use WBS\Referrals\Services\ProspectTransferReviewService;
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
$CAP = ProspectTransferService::CAPABILITY;

/** Membership-port spy for ContactBookService's join/ensureBelonging seams. */
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

/**
 * Build the whole stack over one fake DB.
 *
 * @param array<string,array<string,mixed>> $configValues "group|capability" => value
 * @return array{db:BaseConnection,transfers:ProspectTransferService,reviews:ProspectTransferReviewService,
 *               book:ContactBookService,config:EffectiveConfigResolver,audit:AuditLogger,
 *               memberships:GroupMembershipService,sponsorships:SponsorshipService}
 */
$stack = static function (array $configValues = []) use ($membershipSpy): array {
    $db       = new BaseConnection();
    $db->rows = [
        'groups'                     => [],
        'group_members'              => [],
        'prospects'                  => [],
        'users'                      => [],
        'prospect_group_transfers'   => [],
        'prospect_transfer_requests' => [],
        'prospect_transfer_reviews'  => [],
    ];
    $clock    = new Clock();
    $resolver = new ProspectGroupResolver($db);
    $config   = new EffectiveConfigResolver();
    foreach ($configValues as $k => $v) {
        $config->values[$k] = $v;
    }
    $memberships  = new GroupMembershipService();
    $sponsorships = new SponsorshipService();
    $audit        = new AuditLogger();
    $transfers    = new ProspectTransferService(
        $db, $clock, $resolver, $config, $memberships, $sponsorships, $audit,
    );
    $reviews = new ProspectTransferReviewService($db, $clock, $transfers, $audit);
    // ContactBookService: only the placement/transfer/review seams are wired;
    // every other collaborator stays null (the service guards each one).
    $book = new ContactBookService(
        $db, $clock, null, null, null, null, null, null,
        $membershipSpy, null, null,
        $resolver, $transfers, $reviews,
    );

    return compact('db', 'resolver', 'transfers', 'reviews', 'book', 'config', 'audit', 'memberships', 'sponsorships');
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

/** A contact that has been quiet since $since, owned by mentor-OLD in g-old. */
$seedContact = static function (BaseConnection $db, string $since = '2026-06-01 00:00:00'): void {
    $db->rows['prospects'][] = [
        'id' => 'p-1', 'organization_id' => 'org-1', 'full_name' => 'Esi Owusu',
        // findExistingContact() matches on email_hash (sha256) / a phone suffix,
        // so the seed carries the hash an invite would be matched against.
        'email' => 'esi@example.org', 'email_hash' => hash('sha256', 'esi@example.org'), 'phone' => '0244000111',
        'owner_user_id' => 'mentor-OLD', 'assigned_group_id' => 'g-old',
        'linked_user_id' => 'u-1', 'temperature' => 'warm', 'follow_up_count' => 2,
        'last_contacted_at' => $since, 'created_at' => '2026-05-01 00:00:00',
    ];
};

/** The two-group world every scenario below uses: OLD holds, NEW would receive. */
$world = static function (array $extraConfig = []) use ($stack, $seedMember, $seedContact): array {
    $s = $stack($extraConfig + [
        'g-old|' . ProspectTransferService::CAPABILITY => ['enabled' => true, 'inactive_weeks' => 8],
    ]);
    $seedMember($s['db'], 'g-old', 'mentor-OLD', 'leader', 6);
    $seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
    $seedContact($s['db']);
    // The contact's linked account holds a belonging in g-old: that is the row an
    // approved transfer ENDS (history preserved) before opening the new one.
    $seedMember($s['db'], 'g-old', 'u-1', 'member', 6);

    return $s;
};

$REVIEW_ON  = ['g-old|' . ProspectTransferService::CAPABILITY => ['enabled' => true, 'inactive_weeks' => 8, 'requires_review' => true]];
$REVIEW_OFF = ['g-old|' . ProspectTransferService::CAPABILITY => ['enabled' => true, 'inactive_weeks' => 8]];

// ══════════════════════════════════════════════════════════════════════════
echo "1. the gate: requires_review is hierarchical group config, DEFAULT OFF\n";
chk('normalizePolicy keeps review OFF for a bare week count',
    ProspectTransferService::normalizePolicy(8)['requires_review'] === false);
chk('normalizePolicy accepts the review switch',
    ProspectTransferService::normalizePolicy(['enabled' => true, 'inactive_weeks' => 8, 'requires_review' => true])['requires_review'] === true);
chk('normalizePolicy takes review from a JSON-string value',
    ProspectTransferService::normalizePolicy('{"enabled":true,"inactive_weeks":6,"requires_review":true}')['requires_review'] === true);

$s = $world($REVIEW_OFF);
chk('unconfigured subtree ⇒ review NOT required', $s['transfers']->requiresReview($ORG, 'g-old') === false);
chk('policyFor reports the whole shape',
    $s['transfers']->policyFor($ORG, 'g-old') === ['enabled' => true, 'inactive_weeks' => 8, 'requires_review' => false],
    json_encode($s['transfers']->policyFor($ORG, 'g-old')));

$s = $world($REVIEW_ON);
chk('configured subtree ⇒ review required', $s['transfers']->requiresReview($ORG, 'g-old') === true);
chk('and the threshold is unchanged by the review flag', $s['transfers']->thresholdWeeks($ORG, 'g-old') === 8);
chk('a group with no policy at all ⇒ review NOT required', $s['transfers']->requiresReview($ORG, 'g-unknown') === false);

// ══════════════════════════════════════════════════════════════════════════
echo "2. review OFF (default): the invite path still auto-applies\n";
$s = $world($REVIEW_OFF);
$res = $s['book']->captureGuestFromInvite($ORG, 'evt-1', 'mentor-NEW', ['full_name' => 'Esi Owusu', 'email' => 'esi@example.org']);
chk('quiet contact ⇒ transferred on the spot', $res->ok && ($res->data['transferred'] ?? false) === true, json_encode($res->data));
chk('nothing queued', ($s['db']->rows['prospect_transfer_requests'] ?? []) === []);
chk('not flagged as queued', ($res->data['transfer_queued'] ?? true) === false);
chk('ownership moved', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-NEW');
chk('provenance row carries NO request_id',
    ($s['db']->rows['prospect_group_transfers'][0]['request_id'] ?? null) === null,
    var_export($s['db']->rows['prospect_group_transfers'][0]['request_id'] ?? 'MISSING', true));

// ══════════════════════════════════════════════════════════════════════════
echo "3. review ON: the same touch QUEUES instead of moving the person\n";
$s = $world($REVIEW_ON);
$res = $s['book']->captureGuestFromInvite($ORG, 'evt-1', 'mentor-NEW', ['full_name' => 'Esi Owusu', 'email' => 'esi@example.org']);
chk('the touch succeeded', $res->ok, (string) $res->message);
chk('but transferred is FALSE (nothing moved)', ($res->data['transferred'] ?? true) === false, json_encode($res->data));
chk('transfer_queued is TRUE', ($res->data['transfer_queued'] ?? false) === true);
chk('ownership UNCHANGED', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD');
chk('placement UNCHANGED', ($s['db']->rows['prospects'][0]['assigned_group_id'] ?? '') === 'g-old');
chk('no provenance row written', ($s['db']->rows['prospect_group_transfers'] ?? []) === []);
chk('no membership churn', $s['memberships']->left === [] && $s['memberships']->added === []);
chk('one request row, pending', count($s['db']->rows['prospect_transfer_requests']) === 1
    && ($s['db']->rows['prospect_transfer_requests'][0]['status'] ?? '') === 'pending');
$req = $s['db']->rows['prospect_transfer_requests'][0];
chk('request id returned to the caller', ($res->data['transfer']['request_id'] ?? '') === $req['id']);
chk('request names both sides of the move',
    $req['from_group_id'] === 'g-old' && $req['to_group_id'] === 'g-new'
    && $req['from_owner_user_id'] === 'mentor-OLD' && $req['to_owner_user_id'] === 'mentor-NEW',
    json_encode([$req['from_group_id'], $req['to_group_id'], $req['from_owner_user_id'], $req['to_owner_user_id']]));
chk('request carries the trigger that produced it',
    $req['trigger_type'] === 'event_invite' && $req['trigger_id'] === 'evt-1');
chk('maker is the mentor who triggered it', $req['requested_by'] === 'mentor-NEW');
chk('eligibility recorded as ok', $req['eligibility_state'] === 'ok');
chk('evaluation snapshot stored', is_string($req['evaluation'])
    && (json_decode($req['evaluation'], true)['reason'] ?? '') === 'inactive_threshold_met');
chk('threshold + observed inactivity snapshotted',
    (int) $req['threshold_weeks'] === 8 && (int) $req['days_inactive'] > 56, json_encode([$req['threshold_weeks'], $req['days_inactive']]));
chk('the submit step is on the trail', count($s['db']->rows['prospect_transfer_reviews']) === 1
    && ($s['db']->rows['prospect_transfer_reviews'][0]['action'] ?? '') === 'submit');
chk('and in the audit log', in_array('prospect.transfer.requested', array_column($s['audit']->entries, 'action'), true));

// A second invite from the SAME mentor while it is pending must not stack. (The
// first invite's RSVP is itself a touch, so it reset last_contacted_at — roll the
// clock back to the quiet date this second invite is measured against.)
$s['db']->rows['prospects'][0]['last_contacted_at'] = '2026-06-01 00:00:00';
$before = count($s['db']->rows['prospect_transfer_requests']);
$res2 = $s['book']->captureGuestFromInvite($ORG, 'evt-2', 'mentor-NEW', ['full_name' => 'Esi Owusu', 'email' => 'esi@example.org']);
chk('second invite dedupes onto the open request',
    count($s['db']->rows['prospect_transfer_requests']) === $before
    && ($res2->data['transfer']['request_id'] ?? '') === $req['id']
    && ($res2->data['transfer']['duplicate'] ?? false) === true,
    json_encode($res2->data['transfer'] ?? null));
chk('still nothing moved', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD');
chk('still exactly one contact', count($s['db']->rows['prospects']) === 1);

// ══════════════════════════════════════════════════════════════════════════
echo "4. segregation of duties: the maker cannot approve their own request\n";
$sod = $s['reviews']->approve($ORG, $req['id'], 'mentor-NEW');
chk('self-approval refused (403)', $sod->failed() && $sod->status === 403 && $sod->code === 'SELF_APPROVAL', json_encode([$sod->code, $sod->status]));
chk('request still pending', ($s['reviews']->find($ORG, $req['id'])['status'] ?? '') === 'pending');
chk('still nothing moved', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD');

// ══════════════════════════════════════════════════════════════════════════
echo "5. approval RE-CHECKS eligibility before it moves anyone\n";
// The contact was followed up while the request sat in the queue ⇒ no longer due.
$s['db']->rows['prospects'][0]['last_contacted_at'] = '2026-09-18 00:00:00';
$stale = $s['reviews']->approve($ORG, $req['id'], 'leader-AREA');
chk('a now-active contact cannot be transferred (409)',
    $stale->failed() && $stale->status === 409 && $stale->code === 'NO_LONGER_ELIGIBLE', json_encode([$stale->code, $stale->status]));
chk('the refusal names the fresh verdict', ($stale->errors['reason'] ?? '') === 'still_active', json_encode($stale->errors));
chk('still nothing moved', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD'
    && ($s['db']->rows['prospect_group_transfers'] ?? []) === []);
$afterBlock = $s['reviews']->find($ORG, $req['id']);
chk('request marked BLOCKED (but still pending)',
    ($afterBlock['eligibility_state'] ?? '') === 'blocked' && ($afterBlock['status'] ?? '') === 'pending');
chk('block detail recorded', ($afterBlock['eligibility_detail'] ?? '') === 'still_active');
chk('and on the trail', in_array('block', array_column($afterBlock['reviews'], 'action'), true));

// The receiving mentor loses their home group while it is queued ⇒ nowhere to go.
$s['db']->rows['prospects'][0]['last_contacted_at'] = '2026-06-01 00:00:00';
$s['db']->rows['group_members'] = array_values(array_filter(
    $s['db']->rows['group_members'],
    static fn ($m) => ($m['user_id'] ?? '') !== 'mentor-NEW',
));
$noGroup = $s['reviews']->approve($ORG, $req['id'], 'leader-AREA');
chk('a mentor with no group cannot receive anyone (409)',
    $noGroup->failed() && ($noGroup->errors['reason'] ?? '') === 'new_mentor_has_no_group', json_encode([$noGroup->code, $noGroup->errors]));
chk('still nothing moved', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD');
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);

// ══════════════════════════════════════════════════════════════════════════
echo "6. a different leader approving performs the real transfer\n";
$ok = $s['reviews']->approve($ORG, $req['id'], 'leader-AREA', 'Verified by phone: Esi now attends g-new');
chk('approval succeeded', $ok->ok, (string) $ok->message);
chk('ownership moved to the receiving mentor', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-NEW');
chk('placement moved to their group', ($s['db']->rows['prospects'][0]['assigned_group_id'] ?? '') === 'g-new');
chk('the belonging was moved, not deleted', count($s['memberships']->left) === 1 && count($s['memberships']->added) === 1);
chk('the sponsor was re-parented', ($s['sponsorships']->assigned[0]['sponsor_id'] ?? '') === 'mentor-NEW');
chk('provenance row appended', count($s['db']->rows['prospect_group_transfers']) === 1);
$prov = $s['db']->rows['prospect_group_transfers'][0];
chk('provenance STAMPS the approving request', ($prov['request_id'] ?? '') === $req['id'], json_encode($prov['request_id'] ?? null));
chk('provenance names the checker as actor', ($prov['created_by'] ?? '') === 'leader-AREA');
chk('provenance keeps the original trigger', ($prov['trigger_type'] ?? '') === 'event_invite' && ($prov['trigger_id'] ?? '') === 'evt-1');
chk('provenance carries the checker note', ($prov['note'] ?? '') === 'Verified by phone: Esi now attends g-new');
$done = $s['reviews']->find($ORG, $req['id']);
chk('request now approved', ($done['status'] ?? '') === 'approved');
chk('decided_by / decided_at stamped', ($done['decided_by'] ?? '') === 'leader-AREA' && ! empty($done['decided_at']));
chk('request points at the transfer row', ($done['transfer_id'] ?? '') === ($prov['id'] ?? ''), json_encode([$done['transfer_id'] ?? null, $prov['id'] ?? null]));
chk('eligibility restored to ok', ($done['eligibility_state'] ?? '') === 'ok');
chk('trail reads submit → block → block → approve (every refusal is recorded)',
    array_values(array_map(static fn ($r) => $r['action'], $done['reviews'])) === ['submit', 'block', 'block', 'approve'],
    json_encode(array_map(static fn ($r) => $r['action'], $done['reviews'])));
chk('approval audited', in_array('prospect.transfer.approved', array_column($s['audit']->entries, 'action'), true));
chk('and the transfer itself audited', in_array('prospect.group.transferred', array_column($s['audit']->entries, 'action'), true));
chk('still exactly one contact', count($s['db']->rows['prospects']) === 1);

echo "7. a decided request cannot be decided twice\n";
$again = $s['reviews']->approve($ORG, $req['id'], 'other-LEADER');
chk('second approval refused (409 BAD_STATE)', $again->failed() && $again->code === 'BAD_STATE' && $again->status === 409, json_encode([$again->code, $again->status]));
chk('reject after approve refused too', $s['reviews']->reject($ORG, $req['id'], 'other-LEADER')->failed());
chk('and the move did not repeat', count($s['db']->rows['prospect_group_transfers']) === 1);

// ══════════════════════════════════════════════════════════════════════════
echo "8. reject and cancel close a request without moving anyone\n";
$s = $world($REVIEW_ON);
$r = $s['book']->captureGuestFromInvite($ORG, 'evt-1', 'mentor-NEW', ['full_name' => 'Esi Owusu', 'email' => 'esi@example.org']);
$rid = (string) $r->data['transfer']['request_id'];
$rej = $s['reviews']->reject($ORG, $rid, 'leader-AREA', 'Still being worked by mentor-OLD');
chk('reject succeeded', $rej->ok && ($rej->data['status'] ?? '') === 'rejected', json_encode($rej->data));
chk('nothing moved', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD'
    && ($s['db']->rows['prospect_group_transfers'] ?? []) === []);
$trail = $s['reviews']->find($ORG, $rid);
chk('reject on the trail with its note', ($trail['reviews'][1]['action'] ?? '') === 'reject'
    && ($trail['reviews'][1]['note'] ?? '') === 'Still being worked by mentor-OLD');
chk('reject audited', in_array('prospect.transfer.rejected', array_column($s['audit']->entries, 'action'), true));

$s = $world($REVIEW_ON);
$r = $s['book']->captureGuestFromInvite($ORG, 'evt-1', 'mentor-NEW', ['full_name' => 'Esi Owusu', 'email' => 'esi@example.org']);
$rid = (string) $r->data['transfer']['request_id'];
$can = $s['reviews']->cancel($ORG, $rid, 'mentor-NEW');
chk('the MAKER may withdraw their own request', $can->ok && ($can->data['status'] ?? '') === 'cancelled', json_encode($can->data));
chk('withdrawal does not trip SoD', $can->ok);
chk('nothing moved', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD');
chk('unknown request ⇒ 404', $s['reviews']->find($ORG, 'nope') === null
    && $s['reviews']->approve($ORG, 'nope', 'leader-AREA')->status === 404);

// ══════════════════════════════════════════════════════════════════════════
echo "9. a pending request NEVER expires — only a human closes it\n";
$s = $world($REVIEW_ON);
$r = $s['book']->captureGuestFromInvite($ORG, 'evt-1', 'mentor-NEW', ['full_name' => 'Esi Owusu', 'email' => 'esi@example.org']);
$rid = (string) $r->data['transfer']['request_id'];
chk('opening writes no expiry column', ! array_key_exists('expires_at', $s['reviews']->find($ORG, $rid)),
    json_encode(array_keys($s['reviews']->find($ORG, $rid))));
chk('"expired" is not a status this service knows', ! in_array('expired', ProspectTransferReviewService::STATUSES, true),
    json_encode(ProspectTransferReviewService::STATUSES));
chk('and no sweep exists to close one on a clock',
    ! method_exists($s['reviews'], 'expireStale') && ! method_exists($s['reviews'], 'isExpired'));

// Months pass with the facts unchanged ⇒ the request is exactly as approvable as
// the day it was opened. Age alone is not a reason to close it. (The capture is
// itself a touch — §1 — so roll it back and backdate the request.)
$s['db']->rows['prospects'][0]['last_contacted_at'] = '2026-06-01 00:00:00';
$s['db']->rows['prospect_transfer_requests'][0]['created_at'] = '2026-01-01 00:00:00.000000';
chk('an aged request stays pending', ($s['reviews']->find($ORG, $rid)['status'] ?? '') === 'pending');
$agedOk = $s['reviews']->approve($ORG, $rid, 'leader-AREA');
chk('and is still approved when its facts still hold',
    $agedOk->ok
    && ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-NEW',
    json_encode($s['reviews']->find($ORG, $rid)['status'] ?? null));

// Staleness is guarded by the RE-CHECK, not by a clock: an aged request whose
// facts have changed is refused (409) and stays OPEN for a human — never
// auto-closed, never silently actioned.
$s = $world($REVIEW_ON);
$q = $s['book']->captureGuestFromInvite($ORG, 'evt-9', 'mentor-NEW', ['full_name' => 'Esi Owusu', 'email' => 'esi@example.org']);
$rid2 = (string) $q->data['transfer']['request_id'];
$s['db']->rows['prospect_transfer_requests'][0]['created_at'] = '2026-01-01 00:00:00.000000';
$s['db']->rows['prospects'][0]['last_contacted_at'] = '2026-09-18 00:00:00';
$aged = $s['reviews']->approve($ORG, $rid2, 'leader-AREA');
chk('an aged request whose facts changed is refused 409 (not 410)',
    $aged->failed() && $aged->status === 409 && $aged->code === 'NO_LONGER_ELIGIBLE', json_encode([$aged->code, $aged->status]));
chk('marked blocked but still pending for a human decision',
    ($s['reviews']->find($ORG, $rid2)['status'] ?? '') === 'pending'
    && ($s['reviews']->find($ORG, $rid2)['eligibility_state'] ?? '') === 'blocked');
chk('nothing moved', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD');
chk('and it is still in the approver queue',
    in_array($rid2, array_column($s['reviews']->pendingForApprover($ORG, 'leader-AREA'), 'id'), true));

// ══════════════════════════════════════════════════════════════════════════
echo "10. the queue an approver sees\n";
$s = $world($REVIEW_ON);
$seedMember($s['db'], 'g-other', 'mentor-OTHER', 'leader', 6);
$seedMember($s['db'], 'g-third', 'mentor-THIRD', 'leader', 6);
$a = $s['reviews']->submit($ORG, 'p-1', 'staff-1', ['to_owner_user_id' => 'mentor-NEW',   'reason' => 'Quiet since June', 'approver_id' => 'leader-AREA']);
$b = $s['reviews']->submit($ORG, 'p-1', 'staff-1', ['to_owner_user_id' => 'mentor-OTHER', 'reason' => 'Quiet since June', 'approver_id' => 'leader-REGION']);
chk('both proposals queued', $a->ok && $b->ok, json_encode([$a->code, $b->code]));
chk('a nominated approver sees their own request', count($s['reviews']->pendingForApprover($ORG, 'leader-AREA')) === 1);
chk('a different approver sees theirs', count($s['reviews']->pendingForApprover($ORG, 'leader-REGION')) === 1);
chk('a request nominated to someone else is NOT listed',
    ! in_array((string) $b->data['request_id'], array_column($s['reviews']->pendingForApprover($ORG, 'leader-AREA'), 'id'), true));
chk('an unrelated leader sees nothing while every request is nominated',
    $s['reviews']->pendingForApprover($ORG, 'nobody') === []);
$c = $s['reviews']->submit($ORG, 'p-1', 'staff-1', ['to_owner_user_id' => 'mentor-THIRD', 'reason' => 'Quiet since June']);
chk('an unnominated request is visible to any checker', $c->ok
    && count($s['reviews']->pendingForApprover($ORG, 'leader-AREA')) === 2
    && count($s['reviews']->pendingForApprover($ORG, 'nobody')) === 1, json_encode($c->code));
chk('submitFromEvaluation of a NOT-due contact is refused',
    $s['reviews']->submitFromEvaluation($ORG, $s['db']->rows['prospects'][0], ['due' => false, 'reason' => 'still_active'])->failed());
$found = $s['reviews']->find($ORG, (string) $a->data['request_id']);
chk('find() returns the row with its trail', is_array($found) && is_array($found['reviews'] ?? null)
    && ($found['reviews'][0]['action'] ?? '') === 'submit');
chk('find() is org-scoped', $s['reviews']->find('org-OTHER', (string) $a->data['request_id']) === null);
chk('the queue honours its bound', count($s['reviews']->pendingForApprover($ORG, 'leader-AREA', 1)) === 1);

// ══════════════════════════════════════════════════════════════════════════
echo "11. a manual proposal is judged by the SAME policy (no side door)\n";
$s = $world($REVIEW_ON);
$noReason = $s['reviews']->submit($ORG, 'p-1', 'staff-1', ['to_owner_user_id' => 'mentor-NEW', 'reason' => '  ']);
chk('a reason is required', $noReason->failed() && $noReason->code === 'REASON_REQUIRED' && $noReason->status === 422, json_encode($noReason->code));
chk('bad input refused', $s['reviews']->submit($ORG, 'p-1', 'staff-1', ['reason' => 'x'])->failed());
chk('unknown contact ⇒ 404', $s['reviews']->submit($ORG, 'nope', 'staff-1',
    ['to_owner_user_id' => 'mentor-NEW', 'reason' => 'x'])->status === 404);

// Still inside the window ⇒ stored, but blocked and unapprovable.
$s['db']->rows['prospects'][0]['last_contacted_at'] = '2026-09-18 00:00:00';
$early = $s['reviews']->submit($ORG, 'p-1', 'staff-1', ['to_owner_user_id' => 'mentor-NEW', 'reason' => 'She asked to move']);
chk('an early proposal is still STORED', $early->ok && ($early->data['status'] ?? '') === 'pending', json_encode($early->data));
chk('but recorded as blocked', ($early->data['eligibility_state'] ?? '') === 'blocked');
chk('with the verdict as detail', ($early->data['eligibility_detail'] ?? '') === 'still_active');
chk('and its audit entry says blocked',
    ($s['audit']->entries[count($s['audit']->entries) - 1]['outcome'] ?? '') === 'blocked');
$tryEarly = $s['reviews']->approve($ORG, (string) $early->data['request_id'], 'leader-AREA');
chk('so it CANNOT be approved', $tryEarly->failed() && $tryEarly->code === 'NO_LONGER_ELIGIBLE', json_encode($tryEarly->code));
chk('nothing moved', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-OLD'
    && ($s['db']->rows['prospect_group_transfers'] ?? []) === []);

// A second OPEN proposal for the same (contact, receiving mentor) is refused.
$dup = $s['reviews']->submit($ORG, 'p-1', 'staff-2', ['to_owner_user_id' => 'mentor-NEW', 'reason' => 'Same idea']);
chk('a duplicate open proposal is refused (409)', $dup->failed() && $dup->code === 'ALREADY_PENDING' && $dup->status === 409, json_encode($dup->code));
chk('and it names the request that is already open',
    ($dup->errors['request_id'] ?? '') === (string) $early->data['request_id']);

// Withdraw the early one; once the contact really has gone quiet the same
// proposal is eligible — the policy decides, not the maker.
chk('the maker can withdraw the early proposal',
    $s['reviews']->cancel($ORG, (string) $early->data['request_id'], 'staff-1')->ok);
$s['db']->rows['prospects'][0]['last_contacted_at'] = '2026-06-01 00:00:00';
$late = $s['reviews']->submit($ORG, 'p-1', 'staff-2', ['to_owner_user_id' => 'mentor-NEW', 'reason' => 'Quiet since June']);
chk('a due manual proposal is eligible', $late->ok && ($late->data['eligibility_state'] ?? '') === 'ok', json_encode($late->data));
$lateId = (string) $late->data['request_id'];
chk('the maker cannot approve their own proposal',
    $s['reviews']->approve($ORG, $lateId, 'staff-2')->code === 'SELF_APPROVAL');
$app = $s['reviews']->approve($ORG, $lateId, 'leader-AREA');
chk('a checker can', $app->ok, (string) $app->message);
chk('and the person moved exactly once', ($s['db']->rows['prospects'][0]['owner_user_id'] ?? '') === 'mentor-NEW'
    && count($s['db']->rows['prospect_group_transfers']) === 1);
chk('the provenance row names the manual trigger',
    ($s['db']->rows['prospect_group_transfers'][0]['trigger_type'] ?? '') === 'manual');
chk('and carries the request that authorized it',
    ($s['db']->rows['prospect_group_transfers'][0]['request_id'] ?? '') === $lateId);
chk('the closed early proposal is not in the queue any more',
    ! in_array((string) $early->data['request_id'], array_column($s['reviews']->pendingForApprover($ORG, 'leader-AREA'), 'id'), true));

// Transfers switched off entirely ⇒ even a manual proposal is blocked.
$s = $stack(['g-old|' . $CAP => ['enabled' => false, 'inactive_weeks' => 0]]);
$seedMember($s['db'], 'g-old', 'mentor-OLD', 'leader', 6);
$seedMember($s['db'], 'g-new', 'mentor-NEW', 'leader', 6);
$seedContact($s['db']);
$off = $s['reviews']->submit($ORG, 'p-1', 'staff-1', ['to_owner_user_id' => 'mentor-NEW', 'reason' => 'Please']);
chk('a subtree with transfers OFF blocks the proposal', ($off->data['eligibility_state'] ?? '') === 'blocked'
    && ($off->data['eligibility_detail'] ?? '') === 'transfer_disabled', json_encode($off->data));
chk('and it cannot be approved', $s['reviews']->approve($ORG, (string) $off->data['request_id'], 'leader-AREA')->failed());

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
