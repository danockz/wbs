<?php

declare(strict_types=1);

/**
 * approveMerge belongings-honesty test (Phase 0: gap ID2).
 *
 * `approveMerge` does the governance part correctly (maker-checker SoD via the
 * PDP, terminal-state guard) and retires the duplicate account to `merged`
 * (merged_into_id -> primary) while emitting `account.merged`. But it does NOT
 * re-point the duplicate's belongings (memberships, grants, contributions,
 * enrollments, registrations, referrals, points, journeys, prefs) to the
 * survivor — that is each owning module's job, on the account.merged event,
 * through its authorized write path (a blind `UPDATE SET user_id=survivor` would
 * launder authority/PII), and it lands in Phase 2.
 *
 * The Phase-0 correctness slice (per the Identity review) is that the merge must
 * be HONEST about this: the approve response + audit trail must say belongings
 * were NOT moved, so the operator who approved it is not misled. This test
 * asserts:
 *   - approveMerge succeeds and retires the duplicate,
 *   - `account.merged` is staged on the outbox (the re-point signal),
 *   - the response reports belongings_repointed = false + a note,
 *   - SoD self-approval is still denied,
 *   - a non-pending request is refused.
 *
 *   php app/Modules/Identity/Services/tests/merge_belongings_honesty_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public bool $failTx = false;

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
            return ! $this->failTx;
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

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function get(): RS
        {
            return new RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
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

namespace WBS\Shared\Messaging {
    class OutboxService
    {
        /** @var list<array{topic:string,aggregate_id:string,payload:array}> */
        public array $staged = [];

        public function stage(string $aggregateType, string $aggregateId, string $topic, array $payload, ?string $organizationId = null, array $headers = []): string
        {
            $this->staged[] = ['topic' => $topic, 'aggregate_id' => $aggregateId, 'payload' => $payload];

            return 'ob-' . count($this->staged);
        }
    }
}

namespace WBS\Identity\Services {
    class SessionService
    {
        public function revokeAllForUser(string $userId): void
        {
        }
    }

    class TokenService
    {
        public function revokeAllForUser(string $userId): void
        {
        }
    }
}

namespace WBS\Audit\Services {
    class AuditLogger
    {
        /** @var list<array{org:string,entry:array}> */
        public array $records = [];

        public function record(string $organizationId, array $entry): void
        {
            $this->records[] = ['org' => $organizationId, 'entry' => $entry];
        }
    }
}

namespace WBS\AccessControl\Services {
    class AuthorizationService
    {
        public bool $permit = true;

        public function decide($r)
        {
            return new \Fake\Decision($this->permit);
        }
    }
}

namespace WBS\AccessControl\Policy {
    class AccessRequest
    {
        public function __construct(...$args)
        {
        }
    }
}

namespace Fake {
    class Decision
    {
        public function __construct(private bool $ok)
        {
        }

        public function isPermitted(): bool
        {
            return $this->ok;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\AccessControl\Services\AuthorizationService;
    use WBS\Audit\Services\AuditLogger;
    use WBS\Identity\Services\AccountLifecycleService;
    use WBS\Identity\Services\SessionService;
    use WBS\Identity\Services\TokenService;
    use WBS\Shared\Messaging\OutboxService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Identity/Services/AccountLifecycleService.php';

    $passed = 0;
    $failed = 0;
    $chk = static function (string $label, bool $cond) use (&$passed, &$failed): void {
        if ($cond) {
            $passed++;

            return;
        }
        $failed++;
        echo "  FAIL {$label}\n";
    };

    $ORG = 'org-1';

    $mk = static function (string $reqStatus, string $requestedBy) use ($ORG): array {
        $db = new BaseConnection();
        $db->rows['users'] = [
            ['id' => 'dup-1', 'organization_id' => $ORG, 'status' => 'active'],
            ['id' => 'primary-1', 'organization_id' => $ORG, 'status' => 'active'],
        ];
        $db->rows['identity_merge_requests'] = [[
            'id'               => 'mr-1',
            'organization_id'  => $ORG,
            'status'           => $reqStatus,
            'requested_by'     => $requestedBy,
            'duplicate_user_id' => 'dup-1',
            'primary_user_id'  => 'primary-1',
        ]];
        $db->rows['identity_merge_reviews']     = [];
        $db->rows['account_state_transitions']  = [];
        $outbox = new OutboxService();
        $audit  = new AuditLogger();
        $pdp    = new AuthorizationService();
        $svc    = new AccountLifecycleService(
            $db,
            new Clock(),
            new SessionService(),
            new TokenService(),
            $audit,
            $pdp,
            $outbox,
        );

        return [$svc, $outbox, $db, $audit, $pdp];
    };

    // ---- happy path: retire duplicate, stage merged, be honest -------------
    [$svc, $outbox, $db, $audit] = $mk('pending', 'maker-1');
    $res = $svc->approveMerge($ORG, 'mr-1', 'checker-1', 'looks like the same person');
    $chk('approveMerge ok', $res->ok);
    $chk('request marked approved', ($db->rows['identity_merge_requests'][0]['status'] ?? '') === 'approved');
    $chk('duplicate retired to merged', ($db->rows['users'][0]['status'] ?? '') === 'merged');
    $chk('duplicate points at survivor', ($db->rows['users'][0]['merged_into_id'] ?? '') === 'primary-1');
    $chk('duplicate_merged reported', ($res->data['duplicate_merged'] ?? null) === true);

    // account.merged is the re-point signal Phase-2 consumers subscribe to.
    $mergedEvents = array_values(array_filter($outbox->staged, fn ($e) => $e['topic'] === 'account.merged'));
    $chk('account.merged staged (the re-point signal)', count($mergedEvents) === 1);
    $chk('merged event carries loser + survivor', ($mergedEvents[0]['payload']['loser_user_id'] ?? '') === 'dup-1'
        && ($mergedEvents[0]['payload']['survivor_user_id'] ?? '') === 'primary-1');

    // The ID2 honesty slice: the response must NOT imply belongings moved.
    $chk('response reports belongings_repointed = false', ($res->data['belongings_repointed'] ?? true) === false);
    $chk('response carries a belongings note', ($res->data['belongings_note'] ?? '') === 'identity.merge.belongings_pending');
    $chk('response reports the merged event was staged', ($res->data['merged_event_staged'] ?? null) === true);

    // The audit trail is honest too.
    $approved = array_values(array_filter($audit->records, fn ($r) => ($r['entry']['action'] ?? '') === 'identity.merge.approved'));
    $chk('audit records the approval', count($approved) === 1);
    $chk('audit says belongings_repointed = false', ($approved[0]['entry']['metadata']['belongings_repointed'] ?? true) === false);

    // Belongings are genuinely untouched: no user_id was blindly rewritten.
    // (We seeded no belonging tables; assert the users table only changed the
    // duplicate's own status/merged_into_id, and the survivor row is untouched.)
    $chk('survivor row untouched', ($db->rows['users'][1]['status'] ?? '') === 'active'
        && ! array_key_exists('merged_into_id', $db->rows['users'][1]));

    // ---- SoD: self-approval denied ----------------------------------------
    [$svc, $outbox, $db] = $mk('pending', 'checker-1');
    $res = $svc->approveMerge($ORG, 'mr-1', 'checker-1', 'approving my own request');
    $chk('self-approval denied', ! $res->ok && $res->code === 'SOD_SELF_APPROVAL');
    $chk('self-approval retires nothing', ($db->rows['users'][0]['status'] ?? '') === 'active');
    $chk('self-approval stages nothing', $outbox->staged === []);

    // ---- non-pending request refused --------------------------------------
    [$svc, $outbox, $db] = $mk('approved', 'maker-1');
    $res = $svc->approveMerge($ORG, 'mr-1', 'checker-1');
    $chk('already-decided request refused', ! $res->ok && $res->code === 'BAD_STATE');
    $chk('refused request stages nothing', $outbox->staged === []);

    // ---- missing request ---------------------------------------------------
    [$svc, , $db] = $mk('pending', 'maker-1');
    $res = $svc->approveMerge($ORG, 'does-not-exist', 'checker-1');
    $chk('missing request → not found', ! $res->ok && $res->code === 'MERGE_NOT_FOUND');

    // ---- PDP denial --------------------------------------------------------
    [$svc, $outbox, $db, , $pdp] = $mk('pending', 'maker-1');
    $pdp->permit = false;
    $res = $svc->approveMerge($ORG, 'mr-1', 'checker-1');
    $chk('PDP denial rejects approval', ! $res->ok && $res->code === 'MERGE_APPROVE_DENIED');
    $chk('denied approval retires nothing', ($db->rows['users'][0]['status'] ?? '') === 'active');

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
