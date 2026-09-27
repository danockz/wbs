<?php

declare(strict_types=1);

/**
 * NotificationService::releaseDeferred test (Theme C — N1).
 *
 * Over an in-memory DB fake + outbox spy, proves:
 *   - a deferred delivery whose defer_until <= now is flipped to `queued`,
 *     defer_until cleared, and a notification.dispatch job staged;
 *   - a deferred delivery still in the future is left alone;
 *   - a deferred row with NULL defer_until (legacy) is skipped, not released;
 *   - non-deferred rows (queued/sent) are untouched;
 *   - org scoping only touches the requested org; null org releases across orgs;
 *   - idempotent re-run releases 0 (already queued no longer matches);
 *   - the staged job carries delivery_id/org/channel/category.
 *
 *   php app/Modules/Notifications/Services/tests/release_deferred_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;
        public bool $txOpen = false;

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
            $this->txOpen = true;
        }

        public function transComplete(): void
        {
            $this->txOpen = false;
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
        private array $ops = [];   // list of [field, operator, value]
        private array $notNull = [];
        private ?string $orderBy = null;
        private string $orderDir = 'ASC';

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
            if (str_contains($k, '<=')) {
                $this->ops[] = [trim(str_replace('<=', '', $k)), '<=', $v];

                return $this;
            }
            $this->eq[$k] = $v;

            return $this;
        }

        public function orderBy($col, $dir = 'ASC')
        {
            $this->orderBy  = $col;
            $this->orderDir = strtoupper((string) $dir);

            return $this;
        }

        public function get($limit = null): RS
        {
            $out = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($this->orderBy !== null) {
                usort($out, fn ($a, $b) => ($this->orderDir === 'DESC' ? -1 : 1)
                    * strcmp((string) ($a[$this->orderBy] ?? ''), (string) ($b[$this->orderBy] ?? '')));
            }
            if ($limit !== null) {
                $out = array_slice($out, 0, (int) $limit);
            }

            return new RS($out);
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
            foreach ($this->notNull as $f) {
                if (($r[$f] ?? null) === null || (string) $r[$f] === '') {
                    return false;
                }
            }
            foreach ($this->ops as [$f, $op, $v]) {
                $cell = (string) ($r[$f] ?? '');
                if ($cell === '' || $r[$f] === null) {
                    return false;
                }
                if ($op === '<=' && ! (strcmp($cell, (string) $v) <= 0)) {
                    return false;
                }
            }

            return true;
        }
    }
}

// Outbox spy + unused collaborators stubbed at their FQCNs.
namespace WBS\Shared\Messaging {
    class OutboxService
    {
        /** @var list<array<string,mixed>> */
        public array $staged = [];

        public function stage(string $aggregateType, string $aggregateId, string $topic, array $payload, ?string $organizationId = null, array $headers = []): string
        {
            $this->staged[] = ['topic' => $topic, 'aggregate_id' => $aggregateId, 'payload' => $payload, 'org' => $organizationId];

            return 'ob-' . count($this->staged);
        }
    }
}
namespace WBS\Notifications\Services {
    if (! class_exists(RetentionPolicyGate::class)) {
        class RetentionPolicyGate
        {
        }
    }
    if (! class_exists(TemplateRenderer::class)) {
        class TemplateRenderer
        {
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
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

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $now = (new Clock())->nowUtcMicro();
    // A time safely in the past and one in the future relative to `now`.
    $past   = '2000-01-01 00:00:00.000000';
    $future = '2999-01-01 00:00:00.000000';

    $db = new BaseConnection();
    $db->rows['notification_deliveries'] = [
        ['id' => 'd1', 'organization_id' => 'org-1', 'user_id' => 'u1', 'channel' => 'email', 'category' => 'digest', 'status' => 'deferred', 'defer_until' => $past],
        ['id' => 'd2', 'organization_id' => 'org-1', 'user_id' => 'u2', 'channel' => 'sms',   'category' => 'alert',  'status' => 'deferred', 'defer_until' => $future],
        ['id' => 'd3', 'organization_id' => 'org-1', 'user_id' => 'u3', 'channel' => 'email', 'category' => 'digest', 'status' => 'deferred', 'defer_until' => null],
        ['id' => 'd4', 'organization_id' => 'org-1', 'user_id' => 'u4', 'channel' => 'email', 'category' => 'digest', 'status' => 'queued',   'defer_until' => null],
        ['id' => 'd5', 'organization_id' => 'org-2', 'user_id' => 'u5', 'channel' => 'push',  'category' => 'promo',  'status' => 'deferred', 'defer_until' => $past],
    ];
    $outbox = new OutboxService();
    $svc    = new NotificationService($db, new Clock(), new RetentionPolicyGate(), new TemplateRenderer(), $outbox);

    $statusOf = static function (BaseConnection $db, string $id): array {
        foreach ($db->rows['notification_deliveries'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };

    // ---- org-scoped release -------------------------------------------------
    $released = $svc->releaseDeferred('org-1', 500);
    $chk('releases exactly the one due org-1 row', $released === 1, (string) $released);
    $chk('due deferred -> queued', $statusOf($db, 'd1')['status'] === 'queued');
    $chk('released row clears defer_until', $statusOf($db, 'd1')['defer_until'] === null);
    $chk('future deferred untouched', $statusOf($db, 'd2')['status'] === 'deferred');
    $chk('null defer_until skipped', $statusOf($db, 'd3')['status'] === 'deferred');
    $chk('already-queued untouched', $statusOf($db, 'd4')['status'] === 'queued');
    $chk('other org untouched under org scope', $statusOf($db, 'd5')['status'] === 'deferred');

    $chk('one dispatch job staged', count($outbox->staged) === 1, (string) count($outbox->staged));
    $job = $outbox->staged[0] ?? ['topic' => '', 'payload' => []];
    $chk('job topic is notification.dispatch', $job['topic'] === 'notification.dispatch');
    $chk('job carries delivery_id', ($job['payload']['delivery_id'] ?? '') === 'd1');
    $chk('job carries channel', ($job['payload']['channel'] ?? '') === 'email');
    $chk('job carries category', ($job['payload']['category'] ?? '') === 'digest');
    $chk('job carries org', ($job['payload']['organization_id'] ?? '') === 'org-1');

    // ---- idempotent re-run --------------------------------------------------
    $again = $svc->releaseDeferred('org-1', 500);
    $chk('re-run releases 0 (idempotent)', $again === 0, (string) $again);
    $chk('re-run stages no new job', count($outbox->staged) === 1);

    // ---- all-orgs pass picks up org-2 --------------------------------------
    $all = $svc->releaseDeferred(null, 500);
    $chk('all-orgs release picks up org-2', $all === 1, (string) $all);
    $chk('org-2 due row -> queued', $statusOf($db, 'd5')['status'] === 'queued');
    $chk('all-orgs staged a second job', count($outbox->staged) === 2);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
