<?php

declare(strict_types=1);

/**
 * Targeted announcements: AND audience, per-announcement hierarchy mode,
 * maker-checker SoD, snapshot on publish, must-ack inbox, optional expiry.
 *
 *   php app/Modules/Announcements/Services/tests/announcements_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
    }
}
namespace Fake {
    class RS {
        public function __construct(private array $rows) {}
        public function getRowArray() { return $this->rows[0] ?? null; }
        public function getResultArray() { return $this->rows; }
    }
    class QB {
        private array $conds = [];
        private ?string $orderKey = null;
        private string $orderDir = 'ASC';
        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}
        public function select($f) { return $this; }
        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if (preg_match('/^(\S+)\s*(>=|<=|!=|>|<)$/', $k, $m) === 1) {
                $this->conds[] = ['k' => $m[1], 'op' => $m[2], 'v' => $v];
            } else {
                $this->conds[] = ['k' => $k, 'op' => '=', 'v' => $v];
            }
            return $this;
        }
        public function orderBy($k, $dir = 'ASC') { $this->orderKey = trim((string) $k); $this->orderDir = strtoupper((string) $dir); return $this; }
        public function get(): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($this->orderKey !== null) {
                $k = $this->orderKey; $d = $this->orderDir;
                usort($rows, static fn ($a, $b) => $d === 'DESC' ? (($b[$k] ?? '') <=> ($a[$k] ?? '')) : (($a[$k] ?? '') <=> ($b[$k] ?? '')));
            }
            return new RS($rows);
        }
        public function insert(array $row): bool { $this->db->rows[$this->t][] = $row; return true; }
        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) { $this->db->rows[$this->t][$i] = array_merge($r, $set); }
            }
            return true;
        }
        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $rv = $r[$c['k']] ?? null;
                $op = $c['op'];
                $v  = $c['v'];
                if ($op === '=') {
                    if ((string) $rv !== (string) $v) { return false; }
                } elseif ($op === '>') {
                    if (! ((float) $rv > (float) $v)) { return false; }
                } elseif ($op === '>=') {
                    if (! ((float) $rv >= (float) $v)) { return false; }
                } elseif ($op === '<') {
                    if (! ((float) $rv < (float) $v)) { return false; }
                } elseif ($op === '<=') {
                    if (! ((float) $rv <= (float) $v)) { return false; }
                } elseif ($op === '!=') {
                    if ((string) $rv === (string) $v) { return false; }
                }
            }
            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Announcements\Services\AnnouncementAudienceResolver;
    use WBS\Announcements\Services\AnnouncementService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Announcements/Support/AnnouncementScope.php';
    require_once $root . '/app/Modules/Announcements/Services/AnnouncementAudienceResolver.php';
    require_once $root . '/app/Modules/Announcements/Services/AnnouncementService.php';

    $pass = 0; $fail = 0;
    $chk = static function (string $l, bool $ok, string $d = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $l . ($ok ? '' : ' — ' . $d) . "\n";
        $ok ? $pass++ : $fail++;
    };
    $ids = static function (array $got): array {
        $x = $got;
        sort($x);
        return $x;
    };

    Clock::freeze(new DateTimeImmutable('2026-09-25 12:00:00', new DateTimeZone('UTC')));
    $db = new BaseConnection();
    $db->rows = [
        'announcements'           => [],
        'announcement_targets'    => [],
        'announcement_audience'   => [],
        'announcement_receipts'   => [],
        'groups' => [
            ['id' => 'g-nat',  'organization_id' => 'org-1', 'kind_code' => 'national'],
            ['id' => 'g-area', 'organization_id' => 'org-1', 'kind_code' => 'area'],
            ['id' => 'g-cell', 'organization_id' => 'org-1', 'kind_code' => 'cell'],
        ],
        'group_closure' => [
            ['ancestor_id' => 'g-nat',  'descendant_id' => 'g-nat',  'distance' => 0],
            ['ancestor_id' => 'g-nat',  'descendant_id' => 'g-area', 'distance' => 1],
            ['ancestor_id' => 'g-nat',  'descendant_id' => 'g-cell', 'distance' => 2],
            ['ancestor_id' => 'g-area', 'descendant_id' => 'g-area', 'distance' => 0],
            ['ancestor_id' => 'g-area', 'descendant_id' => 'g-cell', 'distance' => 1],
            ['ancestor_id' => 'g-cell', 'descendant_id' => 'g-cell', 'distance' => 0],
        ],
        'group_members' => [
            ['user_id' => 'u-nat',   'group_id' => 'g-nat',  'organization_id' => 'org-1', 'status' => 'active', 'role' => 'leader'],
            ['user_id' => 'u-area',  'group_id' => 'g-area', 'organization_id' => 'org-1', 'status' => 'active', 'role' => 'leader'],
            ['user_id' => 'u-elder', 'group_id' => 'g-area', 'organization_id' => 'org-1', 'status' => 'active', 'role' => 'member'],
            ['user_id' => 'u-cell',  'group_id' => 'g-cell', 'organization_id' => 'org-1', 'status' => 'active', 'role' => 'member'],
            ['user_id' => 'u-lead',  'group_id' => 'g-cell', 'organization_id' => 'org-1', 'status' => 'active', 'role' => 'leader'],
            ['user_id' => 'u-left',  'group_id' => 'g-cell', 'organization_id' => 'org-1', 'status' => 'left',   'role' => 'member'],
        ],
        'roles' => [
            ['id' => 'r-elder', 'code' => 'elder'],
        ],
        'role_assignments' => [
            ['role_id' => 'r-elder', 'user_id' => 'u-elder'],
        ],
    ];

    $aud = new AnnouncementAudienceResolver($db);
    $svc = new AnnouncementService($db, new Clock(), $aud, null);

    echo "audience\n";
    $self = $aud->resolve('org-1', [['kind' => 'group', 'ref' => 'g-nat', 'scope_mode' => 'self']]);
    $chk('self = nat members only', $ids($self) === ['u-nat'], json_encode($self));

    $down = $aud->resolve('org-1', [['kind' => 'group', 'ref' => 'g-nat', 'scope_mode' => 'self_and_descendants']]);
    $chk('self_and_descendants = whole tree', $ids($down) === ['u-area', 'u-cell', 'u-elder', 'u-lead', 'u-nat'], json_encode($down));

    $kids = $aud->resolve('org-1', [['kind' => 'group', 'ref' => 'g-nat', 'scope_mode' => 'descendants_only']]);
    $chk('descendants_only excludes self', $ids($kids) === ['u-area', 'u-cell', 'u-elder', 'u-lead'] && ! in_array('u-nat', $kids, true), json_encode($kids));

    $up = $aud->resolve('org-1', [['kind' => 'group', 'ref' => 'g-cell', 'scope_mode' => 'ancestors']]);
    $chk('ancestors includes self + higher', $ids($up) === ['u-area', 'u-cell', 'u-elder', 'u-lead', 'u-nat'], json_encode($up));

    $kind = $aud->resolve('org-1', [['kind' => 'group_kind', 'ref' => 'cell', 'scope_mode' => '']]);
    $chk('kind-wide cell', $ids($kind) === ['u-cell', 'u-lead'], json_encode($kind));

    $andRole = $aud->resolve('org-1', [
        ['kind' => 'group', 'ref' => 'g-nat', 'scope_mode' => 'self_and_descendants'],
        ['kind' => 'membership_role', 'ref' => 'leader', 'scope_mode' => ''],
    ]);
    $chk('AND membership_role=leader', $ids($andRole) === ['u-area', 'u-lead', 'u-nat'], json_encode($andRole));

    $andPlat = $aud->resolve('org-1', [
        ['kind' => 'group', 'ref' => 'g-nat', 'scope_mode' => 'self_and_descendants'],
        ['kind' => 'platform_role', 'ref' => 'elder', 'scope_mode' => ''],
    ]);
    $chk('AND platform_role=elder', $ids($andPlat) === ['u-elder'], json_encode($andPlat));

    $namedMix = $aud->resolve('org-1', [
        ['kind' => 'group', 'ref' => 'g-nat', 'scope_mode' => 'self'],
        ['kind' => 'user', 'ref' => 'u-named', 'scope_mode' => ''],
    ]);
    $chk('named users ignored on filtered audience', $ids($namedMix) === ['u-nat'], json_encode($namedMix));

    $dm = $aud->resolve('org-1', [
        ['kind' => 'user', 'ref' => 'u-named', 'scope_mode' => ''],
        ['kind' => 'user', 'ref' => 'u-cell', 'scope_mode' => ''],
    ]);
    $chk('direct message = named users only', $ids($dm) === ['u-cell', 'u-named'], json_encode($dm));

    $empty = $aud->resolve('org-1', []);
    $chk('no targets fail-closed empty', $empty === []);

    $inactive = $aud->resolve('org-1', [['kind' => 'group', 'ref' => 'g-cell', 'scope_mode' => 'self']]);
    $chk('inactive member excluded', ! in_array('u-left', $inactive, true), json_encode($inactive));

    echo "lifecycle\n";
    $miss = $svc->create('org-1', 'u-composer', ['title' => '', 'body' => '']);
    $chk('missing fields 422', ! $miss->ok && $miss->status === 422);

    $noT = $svc->create('org-1', 'u-composer', ['title' => 'Hi', 'body' => 'There']);
    $chk('no targets 422', ! $noT->ok && $noT->code === 'NO_TARGETS');

    $mix = $svc->create('org-1', 'u-composer', [
        'title' => 'No mix', 'body' => 'x',
        'group_id' => 'g-nat', 'scope_mode' => 'self',
        'user_ids' => 'u-named',
    ]);
    $chk('named+filter mix 422', ! $mix->ok && $mix->code === 'MIXED_TARGETS');

    $c = $svc->create('org-1', 'u-composer', [
        'title' => 'Fasting week', 'body' => 'Starts Monday.',
        'group_id' => 'g-nat', 'scope_mode' => 'self_and_descendants',
        'membership_roles' => 'leader',
        'ends_at' => '2026-12-31',
    ]);
    $chk('create draft', $c->ok && ($c->data['status'] ?? '') === 'draft');
    $aid = (string) ($c->data['id'] ?? '');

    $sodEarly = $svc->approve('org-1', $aid, 'u-approver');
    $chk('approve draft 409', ! $sodEarly->ok && $sodEarly->status === 409);

    $sub = $svc->submit('org-1', $aid);
    $chk('submit pending', $sub->ok && ($sub->data['status'] ?? '') === 'pending_approval');
    $chk('audience_count leaders only', (int) ($sub->data['audience_count'] ?? 0) === 3, (string) ($sub->data['audience_count'] ?? ''));

    $selfAp = $svc->approve('org-1', $aid, 'u-composer');
    $chk('SoD self-approval 422', ! $selfAp->ok && $selfAp->code === 'SOD_SELF_APPROVAL');

    $ap = $svc->approve('org-1', $aid, 'u-approver');
    $chk('approve publishes', $ap->ok && ($ap->data['status'] ?? '') === 'published', json_encode($ap->data));
    $chk('snapshot size 3', (int) ($ap->data['audience'] ?? 0) === 3);
    $chk('notify skipped without service', (int) ($ap->data['notified'] ?? -1) === 0);

    $inboxLead = $svc->inbox('org-1', 'u-lead');
    $chk('leader sees published', count($inboxLead) === 1 && ($inboxLead[0]['id'] ?? '') === $aid);
    $inboxMember = $svc->inbox('org-1', 'u-cell');
    $chk('non-matching member not in inbox', $inboxMember === []);
    $chk('named user not extra-included', $svc->inbox('org-1', 'u-named') === []);

    $dmCreate = $svc->create('org-1', 'u-composer', [
        'title' => 'Just you', 'body' => 'Direct.',
        'user_ids' => 'u-named',
    ]);
    $chk('direct-message draft', $dmCreate->ok);
    $did = (string) ($dmCreate->data['id'] ?? '');
    $svc->submit('org-1', $did);
    $dmAp = $svc->approve('org-1', $did, 'u-approver');
    $chk('direct-message publishes to named only', $dmAp->ok && (int) ($dmAp->data['audience'] ?? 0) === 1);
    $chk('named inbox is the DM', count($svc->inbox('org-1', 'u-named')) === 1);
    $chk('leader does not get the DM', count($svc->inbox('org-1', 'u-lead')) === 1); // still the filtered one

    $ack = $svc->ack('org-1', $aid, 'u-lead');
    $chk('ack ok', $ack->ok);
    $chk('acked drops from inbox', $svc->inbox('org-1', 'u-lead') === []);
    $chk('named DM still open', count($svc->inbox('org-1', 'u-named')) === 1);
    $ack2 = $svc->ack('org-1', $aid, 'u-lead');
    $chk('ack idempotent', $ack2->ok && ! empty($ack2->data['deduplicated']));

    $stranger = $svc->ack('org-1', $aid, 'u-cell');
    $chk('non-audience ack 403', ! $stranger->ok && $stranger->status === 403);

    echo "expiry + cancel\n";
    $c2 = $svc->create('org-1', 'u-composer', [
        'title' => 'Expired', 'body' => 'gone',
        'group_id' => 'g-nat', 'scope_mode' => 'self',
        'ends_at' => '2026-09-01',
    ]);
    $id2 = (string) ($c2->data['id'] ?? '');
    $svc->submit('org-1', $id2);
    $svc->approve('org-1', $id2, 'u-approver');
    $inboxNat = array_map(static fn ($r) => (string) ($r['id'] ?? ''), $svc->inbox('org-1', 'u-nat'));
    $chk('expired hidden from inbox', ! in_array($id2, $inboxNat, true), json_encode($inboxNat));

    $c3 = $svc->create('org-1', 'u-composer', [
        'title' => 'Cancel me', 'body' => 'nope',
        'group_id' => 'g-nat', 'scope_mode' => 'self',
    ]);
    $id3 = (string) ($c3->data['id'] ?? '');
    $svc->submit('org-1', $id3);
    $chk('cancel pending', $svc->cancel('org-1', $id3)->ok);
    $chk('cancelled not approvable', ! $svc->approve('org-1', $id3, 'u-approver')->ok);

    $nf = $svc->find('org-other', $aid);
    $chk('cross-org find null', $nf === null);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
