<?php

declare(strict_types=1);

/**
 * CampaignService fan-out test (Theme C — N2).
 *
 * Nothing consumed a campaign's `queued` status, so approved campaigns never
 * reached their audience. Over an in-memory DB fake with a REAL (gated)
 * NotificationService and a stubbed audience port, proves:
 *   - fanOutQueued dispatches one delivery per resolved recipient and flips the
 *     campaign to `sent`;
 *   - it is idempotent: a re-run creates NO duplicate deliveries (send() dedupes
 *     on the stable (campaign,user) key) and the campaign stays `sent`;
 *   - an approved (not-yet-queued) campaign is also eligible;
 *   - a wrong-state campaign (draft) is a BAD_STATE and sends nothing;
 *   - fanOutAllQueued only fans out queued campaigns for the requested org.
 *
 *   php app/Modules/Notifications/Services/tests/campaign_fanout_test.php
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
        private ?string $orderBy = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null, $escape = true)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function orderBy($col, $dir = 'ASC')
        {
            $this->orderBy = $col;

            return $this;
        }

        public function get($limit = null): RS
        {
            $out = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($limit !== null) {
                $out = array_slice($out, 0, (int) $limit);
            }

            return new RS($out);
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;
            $this->db->affected = 1;

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

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

// Gate stub: always `send`. Outbox no-op. Renderer unused (no body set).
namespace WBS\Notifications\Services {
    class RetentionPolicyGate
    {
        public function evaluate(string $org, string $user, string $channel, string $category, array $opts = []): array
        {
            return ['action' => 'send', 'reason' => null, 'defer_until' => null];
        }
    }
    class TemplateRenderer
    {
        public function render(string $body, array $ctx = [], bool $escape = true): string
        {
            return $body;
        }

        public function fingerprint(string $s): string
        {
            return substr(hash('sha256', $s), 0, 16);
        }
    }
}
namespace WBS\Shared\Messaging {
    class OutboxService
    {
        public array $staged = [];

        public function stage(string $a, string $b, string $topic, array $payload, ?string $org = null, array $h = []): string
        {
            $this->staged[] = $topic;

            return 'ob';
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Notifications\Services\CampaignAudiencePort;
    use WBS\Notifications\Services\CampaignService;
    use WBS\Notifications\Services\NotificationService;
    use WBS\Notifications\Services\RetentionPolicyGate;
    use WBS\Notifications\Services\TemplateRenderer;
    use WBS\Shared\Messaging\OutboxService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Notifications/Services/NotificationService.php';
    require_once $root . '/app/Modules/Notifications/Services/CampaignAudiencePort.php';
    require_once $root . '/app/Modules/Notifications/Services/CampaignService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    // Fixed-audience port stub keyed by campaign id.
    $audience = new class implements CampaignAudiencePort {
        /** @var array<string,list<string>> */
        public array $byCampaign = [];

        public function resolve(array $campaign): array
        {
            return $this->byCampaign[(string) $campaign['id']] ?? [];
        }
    };
    $audience->byCampaign = [
        'c1' => ['u1', 'u2', 'u3'],
        'c2' => ['u9'],
        'c3' => ['u1'],
    ];

    $db = new BaseConnection();
    $db->rows['notification_deliveries'] = [];
    $db->rows['notification_campaigns'] = [
        ['id' => 'c1', 'organization_id' => 'org-1', 'group_id' => null, 'channel' => 'email', 'category' => 'newsletter', 'status' => 'queued',  'priority' => 'normal'],
        ['id' => 'c2', 'organization_id' => 'org-1', 'group_id' => null, 'channel' => 'email', 'category' => 'newsletter', 'status' => 'approved','priority' => 'normal'],
        ['id' => 'c3', 'organization_id' => 'org-1', 'group_id' => null, 'channel' => 'email', 'category' => 'newsletter', 'status' => 'draft',   'priority' => 'normal'],
        ['id' => 'c9', 'organization_id' => 'org-2', 'group_id' => null, 'channel' => 'email', 'category' => 'newsletter', 'status' => 'queued',  'priority' => 'normal'],
    ];

    $notifications = new NotificationService($db, new Clock(), new RetentionPolicyGate(), new TemplateRenderer(), new OutboxService());
    $svc = new CampaignService($db, new Clock(), $audience, $notifications);

    $countDeliveries = static fn (BaseConnection $db, string $cid): int => count(array_filter(
        $db->rows['notification_deliveries'],
        fn ($r) => ($r['campaign_id'] ?? '') === $cid,
    ));
    $statusOf = static function (BaseConnection $db, string $cid): string {
        foreach ($db->rows['notification_campaigns'] as $r) {
            if ($r['id'] === $cid) {
                return (string) $r['status'];
            }
        }

        return '';
    };

    // ---- fan out a queued campaign -----------------------------------------
    $res = $svc->fanOutQueued('c1');
    $chk('fanOutQueued ok', $res->ok);
    $chk('reports 3 recipients', ($res->data['recipients'] ?? 0) === 3, (string) ($res->data['recipients'] ?? -1));
    $chk('reports 3 sent', ($res->data['sent'] ?? 0) === 3);
    $chk('created one delivery per recipient', $countDeliveries($db, 'c1') === 3);
    $chk('campaign -> sent', $statusOf($db, 'c1') === 'sent');

    // ---- re-run on a now-sent campaign is rejected (lifecycle guard) --------
    $res2 = $svc->fanOutQueued('c1');
    $chk('re-run on sent campaign rejected', ! $res2->ok && $res2->code === 'BAD_STATE');

    // ---- send-level dedupe protects a mid-fan-out replay -------------------
    // Force c1 back to queued (as if a crash left it partway) and re-fan: the
    // stable (campaign,user) dedupe key means NO duplicate deliveries appear.
    foreach ($db->rows['notification_campaigns'] as $i => $r) {
        if ($r['id'] === 'c1') {
            $db->rows['notification_campaigns'][$i]['status'] = 'queued';
        }
    }
    $res2b = $svc->fanOutQueued('c1');
    $chk('replay ok', $res2b->ok);
    $chk('replay creates NO duplicate deliveries', $countDeliveries($db, 'c1') === 3, (string) $countDeliveries($db, 'c1'));

    // ---- approved is eligible ----------------------------------------------
    $res3 = $svc->fanOutQueued('c2');
    $chk('approved campaign fans out', $res3->ok && $countDeliveries($db, 'c2') === 1);
    $chk('approved -> sent', $statusOf($db, 'c2') === 'sent');

    // ---- wrong state is rejected -------------------------------------------
    $res4 = $svc->fanOutQueued('c3');
    $chk('draft campaign rejected', ! $res4->ok && $res4->code === 'BAD_STATE');
    $chk('draft sent nothing', $countDeliveries($db, 'c3') === 0);

    // ---- fanOutAllQueued is org-scoped -------------------------------------
    // Reset c1/c2 already sent; only c9 (org-2) remains queued. Scoped to org-1
    // it should fan out nothing.
    $n1 = $svc->fanOutAllQueued('org-1', 100);
    $chk('org-1 sweep finds no remaining queued', $n1 === 0, (string) $n1);
    $chk('org-2 campaign still queued after org-1 sweep', $statusOf($db, 'c9') === 'queued');

    $n2 = $svc->fanOutAllQueued('org-2', 100);
    $chk('org-2 sweep fans out its queued campaign', $n2 === 1, (string) $n2);
    $chk('org-2 campaign -> sent', $statusOf($db, 'c9') === 'sent');

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
