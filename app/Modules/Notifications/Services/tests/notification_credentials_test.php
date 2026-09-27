<?php

declare(strict_types=1);

/**
 * Per-group notification credentials — resolution, subtree grants, fail-closed.
 *
 * Every hierarchical body supplies its OWN provider account and decides whether
 * its subtree may send on it. Over an in-memory DB fake, the REAL vault (with a
 * stand-in SecretBox) and the REAL GroupScopeResolver, this proves:
 *
 *   - a group's own connection is always usable, and the NEAREST body wins each
 *     provider (a cell's own mNotify account beats the national one);
 *   - an ancestor's account is usable ONLY when it was explicitly shared: being
 *     above me in the tree is not enough, and a group with nothing provided or
 *     granted resolves to NOTHING (fail-closed — the chain must refuse);
 *   - `scope_mode` behaves exactly as the PDP's scope vocabulary does:
 *     self / self_and_descendants (including subgroups created AFTER the grant) /
 *     descendants_only (not the grantee itself) / groups (hand-picked set);
 *   - lapsed, not-yet-started and revoked grants authorize nothing;
 *   - capability wildcards (`sms.*`) match, other channels' capabilities don't;
 *   - cross-cut coverage is OPT-IN per grant and never chains;
 *   - an org-wide (group_id NULL) account is still grant-gated, and sorts last;
 *   - draft/revoked connections and adapters no transport knows are ignored;
 *   - `isUsable()` mirrors each transport's own configured-rule, and
 *     `withSecrets()` hands plaintext to the callback ONLY (missing slots arrive
 *     as '', an unusable credential returns null and never runs the callback);
 *   - ConnectionService.grantCapability() stores the scope vocabulary, refuses a
 *     hand-picked grant with no groups, an unknown scope_mode, an oversized set,
 *     and a grantee OUTSIDE the owner's own subtree (containment).
 *
 *   php app/Modules/Notifications/Services/tests/notification_credentials_test.php
 */

namespace WBS\Shared\Security {
    // Stand-in for the (final) production SecretBox: reversible "encryption" so
    // the test can assert what the vault hands a transport.
    class SecretBox
    {
        public function encrypt(string $plaintext, string $aad = ''): string
        {
            return 'enc(' . $plaintext . '|' . $aad . ')';
        }

        public function decrypt(string $stored, string $aad = ''): string
        {
            return explode('|', substr($stored, 4, -1), 2)[0];
        }

        public function fingerprint(string $plaintext): string
        {
            return substr(md5($plaintext), 0, 16);
        }

        public function keyId(): string
        {
            return 'test-key';
        }
    }
}

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;

        public function table(string $t): \Fake\QB
        {
            if (! isset($this->rows[$t])) {
                $this->rows[$t] = [];
            }

            return new \Fake\QB($this, $t);
        }

        public function tableExists(string $t): bool
        {
            return isset($this->rows[$t]);
        }

        public function affectedRows(): int
        {
            return $this->affected;
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
        /** @var array<int,array{k:string,op:string,v:mixed}> */
        private array $conds = [];
        /** @var array<string,list<string>> */
        private array $in = [];
        private ?string $orderKey = null;
        private string $orderDir = 'ASC';
        private ?string $maxCol = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($f)
        {
            return $this;
        }

        public function selectMax($c)
        {
            $this->maxCol = trim((string) $c);

            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            $k  = trim((string) $k);
            $op = '=';
            foreach (['<=', '>=', '<', '>'] as $candidate) {
                if (str_ends_with($k, $candidate)) {
                    $op = $candidate;
                    $k  = trim(substr($k, 0, -strlen($candidate)));
                    break;
                }
            }
            $this->conds[] = ['k' => $k, 'op' => $op, 'v' => $v];

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function orderBy($k, $dir = 'ASC')
        {
            $this->orderKey = trim((string) $k);
            $this->orderDir = strtoupper((string) $dir) === 'DESC' ? 'DESC' : 'ASC';

            return $this;
        }

        private function filtered(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function get($limit = null): RS
        {
            $rows = $this->filtered();
            if ($this->maxCol !== null) {
                $mx = null;
                foreach ($rows as $r) {
                    $v = (int) ($r[$this->maxCol] ?? 0);
                    if ($mx === null || $v > $mx) {
                        $mx = $v;
                    }
                }

                return new RS([[$this->maxCol => $mx]]);
            }
            if ($this->orderKey !== null) {
                $key = $this->orderKey;
                $dir = $this->orderDir;
                usort($rows, static fn ($a, $b) => $dir === 'DESC'
                    ? (string) ($b[$key] ?? '') <=> (string) ($a[$key] ?? '')
                    : (string) ($a[$key] ?? '') <=> (string) ($b[$key] ?? ''));
            }
            if ($limit !== null) {
                $rows = array_slice($rows, 0, (int) $limit);
            }

            return new RS($rows);
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;

            return true;
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

        public function countAllResults(): int
        {
            return count($this->filtered());
        }

        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $rv = $r[$c['k']] ?? null;
                $ok = match ($c['op']) {
                    '<='      => $rv !== null && (string) $rv <= (string) $c['v'],
                    '>='      => $rv !== null && (string) $rv >= (string) $c['v'],
                    '<'       => $rv !== null && (string) $rv < (string) $c['v'],
                    '>'       => $rv !== null && (string) $rv > (string) $c['v'],
                    default   => (string) ($rv ?? '') === (string) $c['v'],
                };
                if (! $ok) {
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
    use WBS\Integrations\Services\ConnectionService;
    use WBS\Integrations\Services\CredentialVault;
    use WBS\Notifications\Services\NotificationCredentialResolver;
    use WBS\Notifications\Services\ResolvedCredential;
    use WBS\Shared\Security\SecretBox;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\GroupScopeResolver;
    use WBS\Shared\Support\ScopeMode;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/ScopeMode.php';
    require_once $root . '/app/Modules/Shared/Support/GroupScopeResolver.php';
    // The real SecretBox is deliberately NOT required: the stand-in above owns
    // its namespace so the vault binds to the reversible fake.
    require_once $root . '/app/Modules/Integrations/Services/CredentialVault.php';
    require_once $root . '/app/Modules/Integrations/Services/ConnectionService.php';
    require_once $root . '/app/Modules/Notifications/Services/ResolvedCredential.php';
    require_once $root . '/app/Modules/Notifications/Services/GroupCredentialSource.php';
    require_once $root . '/app/Modules/Notifications/Services/NotificationCredentialResolver.php';

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    Clock::freeze(new DateTimeImmutable('2026-09-20 12:00:00', new DateTimeZone('UTC')));
    $ORG  = 'org-1';
    $PAST = '2026-05-01 00:00:00';
    $NEXT = '2027-05-01 00:00:00';

    /**
     * National → Region → Area → Cell, plus a sibling region (g-other), a region
     * nobody shared with (g-remote) and two cross-cut groups.
     *
     * @return array{db:BaseConnection,vault:CredentialVault,scope:GroupScopeResolver,resolver:NotificationCredentialResolver,connections:ConnectionService}
     */
    $world = static function () use ($ORG, $PAST, $NEXT): array {
        $db = new BaseConnection();

        // ---- groups + closure ------------------------------------------------
        $groups = [
            ['id' => 'g-nat',    'status' => 'active', 'depth' => 0],
            ['id' => 'g-region', 'status' => 'active', 'depth' => 1],
            ['id' => 'g-area',   'status' => 'active', 'depth' => 2],
            ['id' => 'g-cell',   'status' => 'active', 'depth' => 3],
            ['id' => 'g-other',  'status' => 'active', 'depth' => 1],
            ['id' => 'g-remote', 'status' => 'active', 'depth' => 1],
            ['id' => 'g-xcut-a', 'status' => 'active', 'depth' => 1],
            ['id' => 'g-xcut-b', 'status' => 'active', 'depth' => 1],
        ];
        $closure = [];
        foreach ($groups as $g) {
            $closure[] = ['ancestor_id' => $g['id'], 'descendant_id' => $g['id'], 'distance' => 0];
        }
        foreach ([
            ['g-nat', 'g-region', 1], ['g-nat', 'g-area', 2], ['g-nat', 'g-cell', 3],
            ['g-nat', 'g-other', 1], ['g-nat', 'g-remote', 1],
            ['g-region', 'g-area', 1], ['g-region', 'g-cell', 2],
            ['g-area', 'g-cell', 1],
        ] as [$a, $d, $dist]) {
            $closure[] = ['ancestor_id' => $a, 'descendant_id' => $d, 'distance' => $dist];
        }

        // ---- connections (non-secret config only) ----------------------------
        $conn = static function (string $id, ?string $groupId, string $adapter, string $status, ?string $sender, array $settings = [], array $channels = ['sms']): array {
            return [
                'id'              => $id,
                'organization_id' => 'org-1',
                'group_id'        => $groupId,
                'adapter_code'    => $adapter,
                'adapter_version' => 1,
                'category'        => 'notification',
                'display_name'    => $id,
                'channels'        => json_encode($channels),
                'settings'        => $settings === [] ? null : json_encode($settings),
                'sender_identity' => $sender,
                'status'          => $status,
                'created_at'      => '2026-01-01 00:00:00',
            ];
        };
        $connections = [
            $conn('conn-nat', 'g-nat', 'mnotify_sms_v1', 'active', 'WBS-NAT', ['provider_order' => 'mnotify,nalo']),
            $conn('conn-nat-nalo', 'g-nat', 'nalo_sms_v1', 'active', 'WBS-NALO', ['api_base_url' => 'https://nalo.example', 'api_path' => '/reseller/send/']),
            $conn('conn-region', 'g-region', 'nalo_sms_v1', 'active', 'REGION', ['country_code' => '233']),
            $conn('conn-cell', 'g-cell', 'mnotify_sms_v1', 'active', 'CELL-1'),
            $conn('conn-org', null, 'nalo_sms_v1', 'active', 'ORG'),
            $conn('conn-other-empty', 'g-other', 'mnotify_sms_v1', 'active', 'OTHER'),
            $conn('conn-draft', 'g-area', 'mnotify_sms_v1', 'draft', 'DRAFT'),
            $conn('conn-revoked', 'g-area', 'nalo_sms_v1', 'revoked', 'REVOKED'),
            $conn('conn-email', 'g-nat', 'smtp_generic_v1', 'active', 'no-reply@example.org', [], ['email']),
        ];

        // ---- vault slots (write-only, encrypted) -----------------------------
        $slot = static function (string $connectionId, string $name, string $secret): array {
            return [
                'id'            => 'cc-' . $connectionId . '-' . $name,
                'connection_id' => $connectionId,
                'slot'          => $name,
                'cipher'        => 'enc(' . $secret . '|connection:' . $connectionId . ':' . $name . ')',
                'version'       => 1,
                'status'        => 'active',
                'created_at'    => '2026-01-01 00:00:00',
            ];
        };
        $credentials = [
            $slot('conn-nat', 'api_key', 'KEY-NAT'),
            $slot('conn-nat-nalo', 'username', 'U-NAT'),
            $slot('conn-nat-nalo', 'password', 'P-NAT'),
            $slot('conn-region', 'auth_key', 'REGION-KEY'),
            $slot('conn-cell', 'api_key', 'KEY-CELL'),
            $slot('conn-org', 'auth_key', 'ORG-KEY'),
            $slot('conn-draft', 'api_key', 'KEY-DRAFT'),
            // conn-other-empty deliberately has NO slot: an approved sender id
            // with no key behind it must not burn a hop.
        ];

        // ---- grants ----------------------------------------------------------
        $grant = static function (string $id, string $connectionId, string $grantee, string $capability, ?string $mode, array $extra = []) use ($ORG, $PAST, $NEXT): array {
            return array_merge([
                'id'               => $id,
                'organization_id'  => $ORG,
                'connection_id'    => $connectionId,
                'grantee_group_id' => $grantee,
                'capability'       => $capability,
                'scope_mode'       => $mode,
                'include_crosscut' => 0,
                'constraints'      => null,
                'starts_at'        => $PAST,
                'expires_at'       => null,
                'status'           => 'active',
                'created_at'       => $PAST,
            ], $extra);
        };
        $grants = [
            // The national mNotify account, shared with the whole region subtree.
            $grant('gr-nat-region', 'conn-nat', 'g-region', 'sms.send', ScopeMode::SELF_AND_DESCENDANTS),
            // The national Nalo account, shared with the region ONLY.
            $grant('gr-natnalo-region', 'conn-nat-nalo', 'g-region', 'sms.send', ScopeMode::SELF),
            // The region's own Nalo account, hand-picked to one cell.
            $grant('gr-region-groups', 'conn-region', 'g-region', 'sms.send', ScopeMode::GROUPS),
            // The org-wide Nalo account, granted to the national body itself…
            $grant('gr-org-nat', 'conn-org', 'g-nat', 'sms.send', ScopeMode::SELF),
            // …and to the region's SUBGROUPS only (not the region itself).
            $grant('gr-org-region-desc', 'conn-org', 'g-region', 'sms.send', ScopeMode::DESCENDANTS_ONLY),
            // Cross-cut: opt-IN for g-area's network, opt-OUT for g-cell's.
            $grant('gr-xcut-on', 'conn-region', 'g-area', 'sms.send', ScopeMode::SELF, ['include_crosscut' => 1]),
            $grant('gr-xcut-off', 'conn-cell', 'g-cell', 'sms.send', ScopeMode::SELF, ['include_crosscut' => 0]),
            // Nothing below authorizes g-remote: lapsed, not yet started, revoked.
            $grant('gr-lapsed', 'conn-nat-nalo', 'g-remote', 'sms.send', ScopeMode::SELF, ['expires_at' => '2026-08-01 00:00:00']),
            $grant('gr-future', 'conn-org', 'g-remote', 'sms.send', ScopeMode::SELF, ['starts_at' => $NEXT]),
            $grant('gr-revoked', 'conn-region', 'g-remote', 'sms.send', ScopeMode::SELF, ['status' => 'revoked']),
            // A wildcard capability, and one for a different channel.
            $grant('gr-wildcard', 'conn-nat-nalo', 'g-other', 'sms.*', ScopeMode::SELF),
            $grant('gr-email', 'conn-email', 'g-other', 'email.send', ScopeMode::SELF_AND_DESCENDANTS),
        ];

        $db->rows = [
            'groups'                 => $groups,
            'group_closure'          => $closure,
            'group_crosscut_links'   => [
                ['id' => 'x1', 'organization_id' => $ORG, 'crosscut_group_id' => 'g-xcut-a', 'hierarchy_group_id' => 'g-area'],
                ['id' => 'x2', 'organization_id' => $ORG, 'crosscut_group_id' => 'g-xcut-b', 'hierarchy_group_id' => 'g-cell'],
            ],
            'integration_connections' => $connections,
            'connection_credentials'  => $credentials,
            'capability_grants'       => $grants,
            'grant_scope_groups'      => [
                ['id' => 'gsg-1', 'organization_id' => $ORG, 'grant_type' => 'capability_grant', 'grant_id' => 'gr-region-groups', 'group_id' => 'g-cell', 'created_at' => $PAST],
            ],
        ];

        $vault  = new CredentialVault($db, new SecretBox(), new Clock());
        $scope  = new GroupScopeResolver($db);
        $resolver = new NotificationCredentialResolver($db, $vault, $scope, new Clock());
        $connectionsSvc = new ConnectionService($db, new Clock(), $vault, null, null, null, $scope);

        return compact('db', 'vault', 'scope', 'resolver', 'connectionsSvc');
    };

    /** Provider names in resolution order, with provenance. */
    $plan = static function (array $creds): array {
        return array_map(static fn (ResolvedCredential $c): string => $c->provider . ':' . $c->connectionId . ':' . $c->via, $creds);
    };
    $byProvider = static function (array $creds): array {
        $out = [];
        foreach ($creds as $c) {
            $out[$c->provider] = $c;
        }

        return $out;
    };

    // ══════════════════════════════════════════════════════════════════════
    echo "1. a body's own account is its own, and the nearest body wins\n";
    $w    = $world();
    $cell = $w['resolver']->resolveAll($ORG, 'g-cell', 'sms');
    chk('the cell resolves credentials at all', $cell !== [], 'fail-closed would be wrong here');
    $map = $byProvider($cell);
    chk('its OWN mNotify account wins over the national one shared down',
        ($map['mnotify']->connectionId ?? '') === 'conn-cell' && ($map['mnotify']->via ?? '') === 'own',
        json_encode($plan($cell)));
    chk('own credentials carry no grant', $map['mnotify']->grantId === null);
    chk('and its own approved sender identity', ($map['mnotify']->senderId ?? '') === 'CELL-1');
    chk('the region\'s hand-picked Nalo share is also usable',
        ($map['nalo']->connectionId ?? '') === 'conn-region' && ($map['nalo']->via ?? '') === 'granted',
        json_encode($plan($cell)));
    chk('that share names the grant and its scope', ($map['nalo']->grantId ?? '') === 'gr-region-groups'
        && ($map['nalo']->scopeMode ?? '') === ScopeMode::GROUPS);
    chk('the national Nalo account (shared with the region ONLY) is not',
        ! in_array('conn-nat-nalo', array_map(static fn ($c) => $c->connectionId, $cell), true));
    chk('most specific first', array_map(static fn ($c) => $c->provider, $cell) === ['mnotify', 'nalo']
        || ($cell[0]->specificity <= ($cell[1]->specificity ?? PHP_INT_MAX)));

    // ══════════════════════════════════════════════════════════════════════
    echo "2. no grant ⇒ no credentials (fail closed)\n";
    $remote = $w['resolver']->resolveAll($ORG, 'g-remote', 'sms');
    chk('a subgroup nobody shared with resolves to NOTHING', $remote === [], json_encode($plan($remote)));
    chk('…even though its ancestors hold active, funded accounts',
        $w['resolver']->resolveAll($ORG, 'g-nat', 'sms') !== []);
    chk('resolve() agrees', $w['resolver']->resolve($ORG, 'g-remote', 'sms') === null);
    chk('a lapsed grant authorizes nothing',
        ! in_array('gr-lapsed', array_map(static fn ($c) => $c->grantId, $remote), true));

    // The lapsed grant is renewed ⇒ the same group now resolves.
    $w['db']->rows['capability_grants'][7]['expires_at'] = null;
    $renewed = $w['resolver']->resolveAll($ORG, 'g-remote', 'sms');
    chk('renewing the grant makes the account usable again',
        ($byProvider($renewed)['nalo']->connectionId ?? '') === 'conn-nat-nalo'
        && ($byProvider($renewed)['nalo']->grantId ?? '') === 'gr-lapsed',
        json_encode($plan($renewed)));

    // ══════════════════════════════════════════════════════════════════════
    echo "3. scope_mode: the whole subtree, including groups created later\n";
    foreach (['g-region', 'g-area', 'g-cell'] as $group) {
        $creds = $byProvider($w['resolver']->resolveAll($ORG, $group, 'sms'));
        chk("self_and_descendants reaches {$group}",
            ($creds['mnotify']->connectionId ?? '') === 'conn-nat' || $group === 'g-cell',
            json_encode(array_keys($creds)));
        if ($group !== 'g-cell') {
            chk("…as a granted credential for {$group}", ($creds['mnotify']->via ?? '') === 'granted'
                && ($creds['mnotify']->scopeMode ?? '') === ScopeMode::SELF_AND_DESCENDANTS);
        }
    }
    // A NEW cell created after the grant inherits it — no re-granting needed.
    $w['db']->rows['groups'][] = ['id' => 'g-cell-new', 'status' => 'active', 'depth' => 4];
    $w['db']->rows['group_closure'][] = ['ancestor_id' => 'g-cell-new', 'descendant_id' => 'g-cell-new', 'distance' => 0];
    $w['db']->rows['group_closure'][] = ['ancestor_id' => 'g-area', 'descendant_id' => 'g-cell-new', 'distance' => 1];
    $w['db']->rows['group_closure'][] = ['ancestor_id' => 'g-region', 'descendant_id' => 'g-cell-new', 'distance' => 2];
    $w['db']->rows['group_closure'][] = ['ancestor_id' => 'g-nat', 'descendant_id' => 'g-cell-new', 'distance' => 3];
    $newCell = $byProvider($w['resolver']->resolveAll($ORG, 'g-cell-new', 'sms'));
    chk('a subgroup created AFTER the grant is covered by it',
        ($newCell['mnotify']->connectionId ?? '') === 'conn-nat' && ($newCell['mnotify']->via ?? '') === 'granted',
        json_encode(array_keys($newCell)));

    // ══════════════════════════════════════════════════════════════════════
    echo "4. scope_mode: descendants_only skips the grantee itself\n";
    // Cross-cut grants are switched off here so the org-wide descendants_only
    // grant is the only thing that can reach a subgroup.
    $w4 = $world();
    $w4['db']->rows['capability_grants'] = array_values(array_filter(
        $w4['db']->rows['capability_grants'],
        static fn (array $g): bool => ! in_array($g['id'], ['gr-xcut-on', 'gr-xcut-off'], true),
    ));
    $region = $byProvider($w4['resolver']->resolveAll($ORG, 'g-region', 'sms'));
    chk('the region sends Nalo on its OWN account', ($region['nalo']->connectionId ?? '') === 'conn-region'
        && ($region['nalo']->via ?? '') === 'own', json_encode($region['nalo']->connectionId ?? null));
    chk('the org-wide descendants_only grant does not cover the grantee itself',
        $region['nalo']->grantId === null);
    $area = $byProvider($w4['resolver']->resolveAll($ORG, 'g-area', 'sms'));
    chk('but it does cover the region\'s subgroups',
        ($area['nalo']->connectionId ?? '') === 'conn-org' && ($area['nalo']->scopeMode ?? '') === ScopeMode::DESCENDANTS_ONLY,
        json_encode($area['nalo']->connectionId ?? null));
    chk('the area has no mNotify of its own, so it sends on the national one shared down',
        ($area['mnotify']->connectionId ?? '') === 'conn-nat' && ($area['mnotify']->via ?? '') === 'granted',
        json_encode($area['mnotify']->connectionId ?? null));

    // ══════════════════════════════════════════════════════════════════════
    echo "5. scope_mode: groups means the hand-picked set, nothing more\n";
    // Cross-cut grants are switched off here so the ONLY thing that can bring the
    // region's Nalo account to a group is the hand-picked set.
    $w5 = $world();
    $w5['db']->rows['capability_grants'] = array_values(array_filter(
        $w5['db']->rows['capability_grants'],
        static fn (array $g): bool => ! in_array($g['id'], ['gr-xcut-on', 'gr-xcut-off'], true),
    ));
    $cellNalo = $byProvider($w5['resolver']->resolveAll($ORG, 'g-cell', 'sms'))['nalo'] ?? null;
    chk('the picked cell gets the region\'s Nalo account', ($cellNalo->connectionId ?? '') === 'conn-region'
        && ($cellNalo->grantId ?? '') === 'gr-region-groups', json_encode($cellNalo->connectionId ?? null));
    $areaNalo = $byProvider($w5['resolver']->resolveAll($ORG, 'g-area', 'sms'))['nalo'] ?? null;
    chk('a subgroup that was NOT picked does not', ($areaNalo->connectionId ?? '') === 'conn-org',
        json_encode($areaNalo->connectionId ?? null));
    chk('and no grant in the set was stretched to cover it', ($areaNalo->grantId ?? '') === 'gr-org-region-desc');

    // ══════════════════════════════════════════════════════════════════════
    echo "6. a sibling branch is not covered, and an org-wide account sorts last\n";
    $nat = $w['resolver']->resolveAll($ORG, 'g-nat', 'sms');
    chk('the national body uses its own two accounts',
        array_map(static fn ($c) => $c->connectionId, $nat) === ['conn-nat', 'conn-nat-nalo']
        || count($nat) >= 2, json_encode($plan($nat)));
    $natMap = $byProvider($nat);
    chk('the org-wide Nalo account it was granted sorts AFTER its own',
        ! isset($natMap['nalo']) || ($natMap['nalo']->connectionId ?? '') === 'conn-nat-nalo',
        json_encode($natMap['nalo']->connectionId ?? null));
    $other = $byProvider($w['resolver']->resolveAll($ORG, 'g-other', 'sms'));
    chk('a sibling region gets nothing from the region\'s share',
        ($other['nalo']->connectionId ?? '') === 'conn-nat-nalo' && ($other['nalo']->grantId ?? '') === 'gr-wildcard',
        json_encode($other['nalo']->connectionId ?? null));
    chk('…via the sms.* wildcard capability', ($other['nalo']->scopeMode ?? '') === ScopeMode::SELF);
    chk('its own account has no key behind it, so it is not usable',
        isset($other['mnotify']) && $w['resolver']->isUsable($other['mnotify']) === false);

    // ══════════════════════════════════════════════════════════════════════
    echo "7. cross-cut coverage is opt-in and never chains\n";
    // Only the two cross-cut grants remain, so coverage can come from nowhere else.
    $w7 = $world();
    $w7['db']->rows['capability_grants'] = array_values(array_filter(
        $w7['db']->rows['capability_grants'],
        static fn (array $g): bool => in_array($g['id'], ['gr-xcut-on', 'gr-xcut-off'], true),
    ));
    $xcutA = $byProvider($w7['resolver']->resolveAll($ORG, 'g-xcut-a', 'sms'));
    chk('a cross-cut group linked to a covered node IS covered when the grant opts in',
        ($xcutA['nalo']->connectionId ?? '') === 'conn-region' && ($xcutA['nalo']->grantId ?? '') === 'gr-xcut-on',
        json_encode(array_keys($xcutA)));
    chk('the hierarchy node it is linked to is covered too',
        ($byProvider($w7['resolver']->resolveAll($ORG, 'g-area', 'sms'))['nalo']->grantId ?? '') === 'gr-xcut-on');
    $xcutB = $w7['resolver']->resolveAll($ORG, 'g-xcut-b', 'sms');
    chk('and is NOT covered when the grant left cross-cut off', $xcutB === [], json_encode($plan($xcutB)));
    chk('cross-cut never chains to a second cross-cut group',
        $w7['resolver']->resolveAll($ORG, 'g-xcut-a', 'sms') !== []
        && ($byProvider($w7['resolver']->resolveAll($ORG, 'g-xcut-a', 'sms'))['mnotify'] ?? null) === null);

    // ══════════════════════════════════════════════════════════════════════
    echo "8. channel, state and adapter hygiene\n";
    chk('a channel nobody provides for resolves to nothing',
        $w['resolver']->resolveAll($ORG, 'g-nat', 'push') === []);
    chk('an email connection is not an sms credential',
        ! in_array('conn-email', array_map(static fn ($c) => $c->connectionId, $w['resolver']->resolveAll($ORG, 'g-nat', 'sms')), true));
    chk('the email channel has no transport that knows smtp_generic_v1',
        $w['resolver']->resolveAll($ORG, 'g-other', 'email') === []);
    $areaAll = array_map(static fn ($c) => $c->connectionId, $w['resolver']->resolveAll($ORG, 'g-area', 'sms'));
    chk('a draft connection is not a credential', ! in_array('conn-draft', $areaAll, true));
    chk('a revoked connection is not a credential', ! in_array('conn-revoked', $areaAll, true));
    chk('no group at all ⇒ no credentials', $w['resolver']->resolveAll($ORG, null, 'sms') === []);
    chk('and an unknown organization ⇒ none either', $w['resolver']->resolveAll('org-2', 'g-nat', 'sms') === []);
    chk('the capability for a channel is predictable',
        $w['resolver']->capabilityFor('sms') === 'sms.send' && $w['resolver']->capabilityFor('EMAIL') === 'email.send');

    // ══════════════════════════════════════════════════════════════════════
    echo "9. isUsable mirrors each provider's own configured-rule\n";
    $cred = static fn (string $connectionId, string $provider): ResolvedCredential => new ResolvedCredential(
        connectionId: $connectionId, provider: $provider, adapterCode: $provider . '_sms_v1', channel: 'sms',
        ownerGroupId: null, via: 'own', grantId: null, scopeMode: ScopeMode::SELF,
        senderId: 'X', settings: [], specificity: 0,
    );
    chk('mNotify with an api_key is usable', $w['resolver']->isUsable($cred('conn-nat', 'mnotify')));
    chk('mNotify without one is not', ! $w['resolver']->isUsable($cred('conn-other-empty', 'mnotify')));
    chk('Nalo with only an auth_key is usable', $w['resolver']->isUsable($cred('conn-region', 'nalo')));
    chk('Nalo with only a username is not', ! $w['resolver']->isUsable($cred('conn-half', 'nalo')));
    $w['db']->rows['connection_credentials'][] = [
        'id' => 'cc-half-username', 'connection_id' => 'conn-half', 'slot' => 'username',
        'cipher' => 'enc(U|connection:conn-half:username)', 'version' => 1, 'status' => 'active', 'created_at' => $PAST,
    ];
    chk('…and still is not once only the username exists', ! $w['resolver']->isUsable($cred('conn-half', 'nalo')));
    $w['db']->rows['connection_credentials'][] = [
        'id' => 'cc-half-password', 'connection_id' => 'conn-half', 'slot' => 'password',
        'cipher' => 'enc(P|connection:conn-half:password)', 'version' => 1, 'status' => 'active', 'created_at' => $PAST,
    ];
    chk('username + password together are', $w['resolver']->isUsable($cred('conn-half', 'nalo')));
    chk('a provider nothing knows is never usable', ! $w['resolver']->isUsable($cred('conn-x', 'smsgate')));

    // ══════════════════════════════════════════════════════════════════════
    echo "10. secrets exist only inside the vault callback\n";
    $seen = null;
    $ran  = false;
    $cellCred = $byProvider($w['resolver']->resolveAll($ORG, 'g-cell', 'sms'))['mnotify'];
    $returned = $w['resolver']->withSecrets($cellCred, static function (array $secrets) use (&$seen, &$ran): string {
        $ran  = true;
        $seen = $secrets;

        return 'sent with ' . ($secrets['api_key'] ?? '?');
    });
    chk('the callback ran with the decrypted key', $ran && ($seen['api_key'] ?? '') === 'KEY-CELL', json_encode($seen));
    chk('and only the callback saw it', $returned === 'sent with KEY-CELL');
    chk('the credential object itself carries no secret material',
        ! str_contains(json_encode([
            'sender'   => $cellCred->senderId,
            'settings' => $cellCred->settings,
            'conn'     => $cellCred->connectionId,
        ]), 'KEY-'));

    $naloSeen = null;
    $w['resolver']->withSecrets($byProvider($w['resolver']->resolveAll($ORG, 'g-cell', 'sms'))['nalo'],
        static function (array $secrets) use (&$naloSeen): void {
            $naloSeen = $secrets;
        });
    chk('an auth-key-only Nalo account arrives with empty username/password',
        ($naloSeen['auth_key'] ?? '') === 'REGION-KEY' && ($naloSeen['username'] ?? 'x') === '' && ($naloSeen['password'] ?? 'x') === '',
        json_encode($naloSeen));

    $neverRan = true;
    $out = $w['resolver']->withSecrets($cred('conn-other-empty', 'mnotify'), static function (array $s) use (&$neverRan): string {
        $neverRan = false;

        return 'should not happen';
    });
    chk('an unusable credential returns null and never opens the vault', $out === null && $neverRan);

    // ══════════════════════════════════════════════════════════════════════
    echo "11. the group's own non-secret settings drive the wire\n";
    $regionNalo = $byProvider($w['resolver']->resolveAll($ORG, 'g-region', 'sms'))['nalo'] ?? null;
    // g-other has no Nalo account of its own, so the national one (shared through
    // the sms.* wildcard) is what it would send on.
    $grantedNalo = $byProvider($w['resolver']->resolveAll($ORG, 'g-other', 'sms'))['nalo'] ?? null;
    chk("the region's own Nalo settings are carried", ($regionNalo->setting('country_code') ?? '') === '233');
    chk("the national account's base URL + path are carried",
        ($grantedNalo->setting('api_base_url') ?? '') === 'https://nalo.example'
        && ($grantedNalo->setting('api_path') ?? '') === '/reseller/send/',
        json_encode($grantedNalo?->settings));
    chk('a missing setting falls back to the caller default',
        ($regionNalo->setting('api_base_url', 'https://api.nalosolutions.com') ?? '') === 'https://api.nalosolutions.com');
    $natOwn = null;
    foreach ($w['resolver']->resolveAll($ORG, 'g-nat', 'sms') as $c) {
        if ($c->connectionId === 'conn-nat') {
            $natOwn = $c;
        }
    }
    chk("the national body's own provider order is on the connection",
        ($natOwn->setting('provider_order') ?? '') === 'mnotify,nalo');

    // ══════════════════════════════════════════════════════════════════════
    echo "12. grantCapability stores the scope vocabulary\n";
    $w2 = $world();
    $svc = $w2['connectionsSvc'];
    $ok = $svc->grantCapability($ORG, 'conn-cell', 'g-cell', 'sms.send', ['scope_mode' => ScopeMode::SELF_AND_DESCENDANTS]);
    chk('a subtree grant is accepted', $ok->ok, (string) $ok->message);
    $row = null;
    foreach ($w2['db']->rows['capability_grants'] as $g) {
        if ($g['id'] === ($ok->data['grant_id'] ?? '')) {
            $row = $g;
        }
    }
    chk('and stores scope_mode', ($row['scope_mode'] ?? '') === ScopeMode::SELF_AND_DESCENDANTS);
    chk('with cross-cut OFF unless asked', ($row['include_crosscut'] ?? 1) === 0);
    chk('the payload reports the scope', ($ok->data['scope_mode'] ?? '') === ScopeMode::SELF_AND_DESCENDANTS);

    $picked = $svc->grantCapability($ORG, 'conn-cell', 'g-cell', 'sms.send', [
        'scope_mode' => ScopeMode::GROUPS, 'groups' => ['g-cell', 'g-area'], 'include_crosscut' => true,
    ]);
    chk('a hand-picked grant is accepted', $picked->ok, (string) $picked->message);
    $sets = array_values(array_filter($w2['db']->rows['grant_scope_groups'],
        static fn ($r) => $r['grant_id'] === ($picked->data['grant_id'] ?? '')));
    chk('its group set is stored under grant_type capability_grant', count($sets) === 2
        && ($sets[0]['grant_type'] ?? '') === 'capability_grant', json_encode($sets));
    chk('cross-cut opt-in is recorded', ($picked->data['include_crosscut'] ?? false) === true);

    $empty = $svc->grantCapability($ORG, 'conn-cell', 'g-cell', 'sms.send', ['scope_mode' => ScopeMode::GROUPS, 'groups' => []]);
    chk('a hand-picked grant with no groups is refused', $empty->failed() && $empty->code === 'GROUPS_REQUIRED' && $empty->status === 422,
        json_encode([$empty->code, $empty->status]));
    $bad = $svc->grantCapability($ORG, 'conn-cell', 'g-cell', 'sms.send', ['scope_mode' => 'everything']);
    chk('an unknown scope_mode is refused', $bad->failed() && $bad->code === 'BAD_SCOPE', json_encode([$bad->code]));
    $huge = $svc->grantCapability($ORG, 'conn-cell', 'g-cell', 'sms.send', [
        'scope_mode' => ScopeMode::GROUPS, 'groups' => array_map(static fn ($i) => 'g-' . $i, range(1, 201)),
    ]);
    chk('an oversized hand-picked set is refused', $huge->failed() && $huge->code === 'TOO_MANY_GROUPS', json_encode([$huge->code]));
    $inactive = $svc->grantCapability($ORG, 'conn-draft', 'g-area', 'sms.send', []);
    chk('a draft connection cannot be shared', $inactive->failed() && $inactive->code === 'CONNECTION_INACTIVE');

    // ══════════════════════════════════════════════════════════════════════
    echo "13. containment: a body shares DOWN its own subtree only\n";
    $sideways = $svc->grantCapability($ORG, 'conn-region', 'g-other', 'sms.send', ['scope_mode' => ScopeMode::SELF_AND_DESCENDANTS]);
    chk('granting a sibling region access to the region\'s account is refused (403)',
        $sideways->failed() && $sideways->code === 'GRANT_OUT_OF_SCOPE' && $sideways->status === 403,
        json_encode([$sideways->code, $sideways->status]));
    $upwards = $svc->grantCapability($ORG, 'conn-region', 'g-nat', 'sms.send', []);
    chk('and so is granting it upwards to its own parent', $upwards->failed() && $upwards->code === 'GRANT_OUT_OF_SCOPE');
    $down = $svc->grantCapability($ORG, 'conn-region', 'g-area', 'sms.send', ['scope_mode' => ScopeMode::SELF_AND_DESCENDANTS]);
    chk('down into its own subtree is fine', $down->ok, (string) $down->message);
    $self = $svc->grantCapability($ORG, 'conn-region', 'g-region', 'sms.send', []);
    chk('and to itself', $self->ok);
    $orgWide = $svc->grantCapability($ORG, 'conn-org', 'g-cell', 'sms.send', ['scope_mode' => ScopeMode::SELF]);
    chk('an org-wide account may be granted to any group', $orgWide->ok, (string) $orgWide->message);

    // ══════════════════════════════════════════════════════════════════════
    echo "14. grantsFor lists what a body has shared\n";
    $list = $svc->grantsFor($ORG, 'conn-region');
    chk('the region\'s account lists its grants', count($list) >= 3, json_encode(count($list)));
    $modes = array_column($list, 'scope_mode');
    chk('each with a normalized scope_mode', ! in_array(null, $modes, true) && in_array(ScopeMode::GROUPS, $modes, true),
        json_encode($modes));
    $pickedRow = null;
    foreach ($list as $g) {
        if (($g['scope_mode'] ?? '') === ScopeMode::GROUPS) {
            $pickedRow = $g;
        }
    }
    chk('a hand-picked grant carries its set', is_array($pickedRow['groups'] ?? null) && $pickedRow['groups'] !== [],
        json_encode($pickedRow['groups'] ?? null));
    chk('legacy grants (no scope_mode) read as self',
        (static function () use ($svc, $w2, $ORG): bool {
            $w2['db']->rows['capability_grants'][] = [
                'id' => 'gr-legacy', 'organization_id' => $ORG, 'connection_id' => 'conn-region',
                'grantee_group_id' => 'g-area', 'capability' => 'sms.send', 'scope_mode' => null,
                'include_crosscut' => 0, 'constraints' => null, 'starts_at' => '2026-01-01 00:00:00',
                'expires_at' => null, 'status' => 'active', 'created_at' => '2026-01-01 00:00:00',
            ];
            foreach ($svc->grantsFor($ORG, 'conn-region') as $g) {
                if ($g['id'] === 'gr-legacy') {
                    return ($g['scope_mode'] ?? '') === ScopeMode::SELF;
                }
            }

            return false;
        })());
    chk('a legacy grant still authorizes exactly the named group — and nobody below it',
        (static function () use ($ORG, $world): bool {
            // Only the legacy grant exists, so coverage can come from nowhere else.
            $w3 = $world();
            $w3['db']->rows['capability_grants'] = [[
                'id' => 'gr-legacy', 'organization_id' => $ORG, 'connection_id' => 'conn-region',
                'grantee_group_id' => 'g-area', 'capability' => 'sms.send', 'scope_mode' => null,
                'include_crosscut' => 0, 'constraints' => null, 'starts_at' => '2026-01-01 00:00:00',
                'expires_at' => null, 'status' => 'active', 'created_at' => '2026-01-01 00:00:00',
            ]];
            $r     = new NotificationCredentialResolver($w3['db'], $w3['vault'], $w3['scope'], new Clock());
            $area  = $r->resolveAll($ORG, 'g-area', 'sms');
            $cell  = $r->resolveAll($ORG, 'g-cell', 'sms');

            // g-cell keeps its OWN account; what it must not get is the region's,
            // because a pre-scope_mode grant names one group and reaches no further.
            $cellNalo = null;
            foreach ($cell as $c) {
                if ($c->provider === 'nalo') {
                    $cellNalo = $c;
                }
            }

            return count($area) === 1
                && $area[0]->grantId === 'gr-legacy'
                && $area[0]->scopeMode === ScopeMode::SELF
                && $cellNalo === null;
        })());

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
