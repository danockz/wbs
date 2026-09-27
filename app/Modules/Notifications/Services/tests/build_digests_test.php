<?php

declare(strict_types=1);

/**
 * NotificationService::buildDigests test (Theme C — N4).
 *
 * `digest_frequency=daily|weekly` used to behave like `instant` — nothing bundled
 * the held messages. Now the gate holds each as `digest_pending` (with a
 * defer_until window boundary) and this pass bundles them. Over an in-memory DB
 * fake + outbox spy, proves:
 *   - digest_pending rows whose window has closed (defer_until <= now) are
 *     bundled per (org, user, channel) into ONE digest dispatch;
 *   - each bundled row flips to the terminal `digested` status, defer_until clear;
 *   - a digest_pending row still in the future is left alone;
 *   - a digest_pending row with NULL defer_until (legacy) is skipped;
 *   - non-digest rows (queued/deferred/digested) are untouched;
 *   - two users / two channels produce separate digests; counts are correct;
 *   - org scoping; idempotent re-run bundles 0.
 *
 *   php app/Modules/Notifications/Services/tests/build_digests_test.php
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
        private array $ops = [];
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

    $past   = '2000-01-01 00:00:00.000000';
    $future = '2999-01-01 00:00:00.000000';

    $db = new BaseConnection();
    $db->rows['notification_deliveries'] = [
        // org-1 / u1 / email: two due categories -> ONE digest bundling both
        ['id' => 'a1', 'organization_id' => 'org-1', 'user_id' => 'u1', 'channel' => 'email', 'category' => 'journey', 'status' => 'digest_pending', 'defer_until' => $past, 'created_at' => '2026-01-01 09:00:00.000000'],
        ['id' => 'a2', 'organization_id' => 'org-1', 'user_id' => 'u1', 'channel' => 'email', 'category' => 'gamification', 'status' => 'digest_pending', 'defer_until' => $past, 'created_at' => '2026-01-01 10:00:00.000000'],
        // org-1 / u1 / sms: a separate channel -> its own digest
        ['id' => 'a3', 'organization_id' => 'org-1', 'user_id' => 'u1', 'channel' => 'sms', 'category' => 'event', 'status' => 'digest_pending', 'defer_until' => $past, 'created_at' => '2026-01-01 11:00:00.000000'],
        // org-1 / u2 / email: another user -> its own digest
        ['id' => 'a4', 'organization_id' => 'org-1', 'user_id' => 'u2', 'channel' => 'email', 'category' => 'journey', 'status' => 'digest_pending', 'defer_until' => $past, 'created_at' => '2026-01-01 12:00:00.000000'],
        // future window -> left alone
        ['id' => 'a5', 'organization_id' => 'org-1', 'user_id' => 'u3', 'channel' => 'email', 'category' => 'journey', 'status' => 'digest_pending', 'defer_until' => $future, 'created_at' => '2026-01-01 13:00:00.000000'],
        // NULL defer_until (legacy) -> skipped
        ['id' => 'a6', 'organization_id' => 'org-1', 'user_id' => 'u4', 'channel' => 'email', 'category' => 'journey', 'status' => 'digest_pending', 'defer_until' => null, 'created_at' => '2026-01-01 14:00:00.000000'],
        // non-digest statuses -> untouched
        ['id' => 'a7', 'organization_id' => 'org-1', 'user_id' => 'u5', 'channel' => 'email', 'category' => 'journey', 'status' => 'queued', 'defer_until' => null, 'created_at' => '2026-01-01 15:00:00.000000'],
        ['id' => 'a8', 'organization_id' => 'org-1', 'user_id' => 'u6', 'channel' => 'email', 'category' => 'journey', 'status' => 'deferred', 'defer_until' => $past, 'created_at' => '2026-01-01 16:00:00.000000'],
        // other org
        ['id' => 'a9', 'organization_id' => 'org-2', 'user_id' => 'u7', 'channel' => 'push', 'category' => 'promo', 'status' => 'digest_pending', 'defer_until' => $past, 'created_at' => '2026-01-01 17:00:00.000000'],
    ];

    $outbox = new OutboxService();
    $svc    = new NotificationService($db, new Clock(), new RetentionPolicyGate(), new TemplateRenderer(), $outbox);

    $rowOf = static function (BaseConnection $db, string $id): array {
        foreach ($db->rows['notification_deliveries'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };

    // ---- org-scoped digest build -------------------------------------------
    $r = $svc->buildDigests('org-1', 1000);
    $chk('3 digests built (u1/email, u1/sms, u2/email)', $r['digests'] === 3, json_encode($r));
    $chk('4 messages bundled', $r['messages'] === 4, json_encode($r));

    $chk('a1 -> digested', $rowOf($db, 'a1')['status'] === 'digested');
    $chk('a2 -> digested', $rowOf($db, 'a2')['status'] === 'digested');
    $chk('a1 defer_until cleared', $rowOf($db, 'a1')['defer_until'] === null);
    $chk('a3 (sms) -> digested', $rowOf($db, 'a3')['status'] === 'digested');
    $chk('a4 (u2) -> digested', $rowOf($db, 'a4')['status'] === 'digested');
    $chk('a5 future untouched', $rowOf($db, 'a5')['status'] === 'digest_pending');
    $chk('a6 null-window skipped', $rowOf($db, 'a6')['status'] === 'digest_pending');
    $chk('a7 queued untouched', $rowOf($db, 'a7')['status'] === 'queued');
    $chk('a8 deferred untouched', $rowOf($db, 'a8')['status'] === 'deferred');
    $chk('a9 other-org untouched under org scope', $rowOf($db, 'a9')['status'] === 'digest_pending');

    $chk('3 digest jobs staged', count($outbox->staged) === 3, (string) count($outbox->staged));
    foreach ($outbox->staged as $job) {
        $chk('job topic notification.digest.dispatch', $job['topic'] === 'notification.digest.dispatch');
    }
    // The u1/email digest bundles both categories.
    $u1email = null;
    foreach ($outbox->staged as $job) {
        if (($job['payload']['user_id'] ?? '') === 'u1' && ($job['payload']['channel'] ?? '') === 'email') {
            $u1email = $job;
        }
    }
    $chk('u1/email digest found', $u1email !== null);
    $chk('u1/email digest count = 2', ($u1email['payload']['count'] ?? 0) === 2, json_encode($u1email['payload'] ?? []));
    $cats = array_column($u1email['payload']['items'] ?? [], 'category');
    sort($cats);
    $chk('u1/email digest bundles both categories', $cats === ['gamification', 'journey'], json_encode($cats));

    // ---- idempotent re-run --------------------------------------------------
    $again = $svc->buildDigests('org-1', 1000);
    $chk('re-run builds 0 digests (idempotent)', $again['digests'] === 0 && $again['messages'] === 0, json_encode($again));
    $chk('re-run stages no new jobs', count($outbox->staged) === 3);

    // ---- all-orgs pass picks up org-2 --------------------------------------
    $all = $svc->buildDigests(null, 1000);
    $chk('all-orgs picks up org-2', $all['digests'] === 1 && $all['messages'] === 1, json_encode($all));
    $chk('a9 now digested', $rowOf($db, 'a9')['status'] === 'digested');
    $chk('all-orgs staged a 4th job', count($outbox->staged) === 4);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
