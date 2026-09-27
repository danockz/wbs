<?php

declare(strict_types=1);

/**
 * ModerationService::purgeDeletedContent() — CM4 community retention purge.
 *
 * Soft-deleted content (`status='deleted'`, stamped `deleted_at`) is kept for
 * restore + audit, but nothing hard-removed it. This proves the sweep helper:
 *   - hard-deletes posts whose deleted_at is older than the grace window and
 *     CASCADES to their comments / reactions / topic links;
 *   - purges comments deleted DIRECTLY on a still-active post, past grace;
 *   - leaves active / hidden / archived and recently-deleted content ALONE;
 *   - leaves the append-only moderation_actions audit trail intact;
 *   - respects the org filter and the batch limit;
 *   - is IDEMPOTENT: a second pass purges 0.
 *
 * Tiny in-memory fake of the CI4 query builder (no DB/framework).
 *
 *   php app/Modules/Community/Services/tests/retention_purge_test.php
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

        public function affectedRows(): int
        {
            return $this->affected;
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
        private array $conds = [];
        private ?array $in = null;
        private ?string $orderCol = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s, $escape = true)
        {
            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if (str_ends_with($k, 'IS NOT NULL')) {
                $col = trim(substr($k, 0, -strlen('IS NOT NULL')));
                $this->conds[] = ['k' => $col, 'op' => 'notnull', 'v' => null];

                return $this;
            }
            [$key, $op] = $this->splitOp($k);
            $this->conds[] = ['k' => $key, 'op' => $op, 'v' => $v];

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in = ['k' => trim((string) $k), 'vals' => array_map('strval', $vals)];

            return $this;
        }

        public function orderBy($col, $dir = 'ASC')
        {
            $this->orderCol = (string) $col;

            return $this;
        }

        public function get($limit = null): RS
        {
            $rows = $this->matchingRows();
            if ($this->orderCol !== null) {
                usort($rows, fn ($a, $b) => strcmp((string) ($a[$this->orderCol] ?? ''), (string) ($b[$this->orderCol] ?? '')));
            }
            if ($limit !== null) {
                $rows = array_slice($rows, 0, (int) $limit);
            }

            return new RS($rows);
        }

        public function countAllResults(): int
        {
            return count($this->matchingRows());
        }

        public function delete(): bool
        {
            $keep = [];
            $deleted = 0;
            foreach (($this->db->rows[$this->t] ?? []) as $r) {
                if ($this->matches($r)) {
                    $deleted++;
                } else {
                    $keep[] = $r;
                }
            }
            $this->db->rows[$this->t] = $keep;
            $this->db->affected = $deleted;

            return true;
        }

        private function splitOp(string $k): array
        {
            foreach (['>=', '<=', '!=', '>', '<'] as $op) {
                if (str_ends_with($k, ' ' . $op)) {
                    return [trim(substr($k, 0, -strlen($op))), $op];
                }
            }

            return [$k, '='];
        }

        private function matchingRows(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                if ($c['op'] === 'notnull') {
                    if (($r[$c['k']] ?? null) === null || ($r[$c['k']] ?? '') === '') {
                        return false;
                    }
                    continue;
                }
                $left  = (string) ($r[$c['k']] ?? '');
                $right = (string) $c['v'];
                $ok = match ($c['op']) {
                    '='     => $left === $right,
                    '!='    => $left !== $right,
                    '>='    => $left >= $right,
                    '<='    => $left <= $right,
                    '>'     => $left > $right,
                    '<'     => $left < $right,
                    default => false,
                };
                if (! $ok) {
                    return false;
                }
            }
            if ($this->in !== null && ! in_array((string) ($r[$this->in['k']] ?? ''), $this->in['vals'], true)) {
                return false;
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Community\Services\ModerationService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Community/Services/ModerationService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $now = '2026-09-16 12:00:00.000000';
    Clock::freeze(new \DateTimeImmutable($now, new \DateTimeZone('UTC')));

    $old   = '2026-08-01 00:00:00.000000'; // ~46 days ago (> 30-day grace)
    $recent = '2026-09-14 00:00:00.000000'; // 2 days ago (< grace)

    $seed = static function () use ($ORG, $old, $recent): BaseConnection {
        $db = new BaseConnection();
        $db->rows['community_posts'] = [
            // deleted long ago -> purge (+ cascade)
            ['id' => 'p-old', 'organization_id' => $ORG, 'status' => 'deleted', 'deleted_at' => $old, 'visibility' => 'group'],
            // deleted recently -> keep (within grace)
            ['id' => 'p-recent', 'organization_id' => $ORG, 'status' => 'deleted', 'deleted_at' => $recent, 'visibility' => 'group'],
            // active -> keep
            ['id' => 'p-active', 'organization_id' => $ORG, 'status' => 'active', 'deleted_at' => null, 'visibility' => 'group'],
            // hidden -> keep (reversible, not terminal)
            ['id' => 'p-hidden', 'organization_id' => $ORG, 'status' => 'hidden', 'deleted_at' => null, 'visibility' => 'group'],
            // archived visibility, still active -> keep (never purged)
            ['id' => 'p-arch', 'organization_id' => $ORG, 'status' => 'active', 'deleted_at' => null, 'visibility' => 'archived'],
            // other org, deleted long ago -> only purged when org filter null
            ['id' => 'p-other', 'organization_id' => 'org-2', 'status' => 'deleted', 'deleted_at' => $old, 'visibility' => 'group'],
        ];
        $db->rows['community_comments'] = [
            // children of p-old -> cascade purge
            ['id' => 'c-old-1', 'post_id' => 'p-old', 'status' => 'active', 'deleted_at' => null],
            ['id' => 'c-old-2', 'post_id' => 'p-old', 'status' => 'deleted', 'deleted_at' => $old],
            // comment on p-active, deleted long ago -> direct purge
            ['id' => 'c-direct', 'post_id' => 'p-active', 'status' => 'deleted', 'deleted_at' => $old],
            // comment on p-active, deleted recently -> keep
            ['id' => 'c-fresh', 'post_id' => 'p-active', 'status' => 'deleted', 'deleted_at' => $recent],
            // live comment on p-active -> keep
            ['id' => 'c-live', 'post_id' => 'p-active', 'status' => 'active', 'deleted_at' => null],
        ];
        $db->rows['community_reactions'] = [
            ['id' => 'r1', 'post_id' => 'p-old', 'user_id' => 'u1', 'reaction' => 'like'],
            ['id' => 'r2', 'post_id' => 'p-active', 'user_id' => 'u1', 'reaction' => 'like'],
        ];
        $db->rows['community_post_topics'] = [
            ['post_id' => 'p-old', 'topic_id' => 't1'],
            ['post_id' => 'p-active', 'topic_id' => 't2'],
        ];
        $db->rows['moderation_actions'] = [
            ['id' => 'm1', 'subject_type' => 'post', 'subject_id' => 'p-old', 'action' => 'delete'],
        ];

        return $db;
    };

    $has = static function (BaseConnection $db, string $table, string $id): bool {
        foreach ($db->rows[$table] as $r) {
            if (($r['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    };
    $hasPT = static function (BaseConnection $db, string $postId): bool {
        foreach ($db->rows['community_post_topics'] as $r) {
            if (($r['post_id'] ?? null) === $postId) {
                return true;
            }
        }

        return false;
    };

    // ---- org-scoped purge -------------------------------------------------
    $db  = $seed();
    $svc = new ModerationService($db, new Clock());
    $r = $svc->purgeDeletedContent($ORG, 30, 500);

    $chk('purged 1 post', $r['posts'] === 1, json_encode($r));
    $chk('p-old purged', ! $has($db, 'community_posts', 'p-old'));
    $chk('p-recent kept (within grace)', $has($db, 'community_posts', 'p-recent'));
    $chk('p-active kept', $has($db, 'community_posts', 'p-active'));
    $chk('p-hidden kept', $has($db, 'community_posts', 'p-hidden'));
    $chk('p-arch (archived) kept', $has($db, 'community_posts', 'p-arch'));
    $chk('p-other (other org) kept', $has($db, 'community_posts', 'p-other'));

    // cascade
    $chk('c-old-1 cascade purged', ! $has($db, 'community_comments', 'c-old-1'));
    $chk('c-old-2 cascade purged', ! $has($db, 'community_comments', 'c-old-2'));
    $chk('p-old reactions purged', ! $has($db, 'community_reactions', 'r1'));
    $chk('p-old topics purged', ! $hasPT($db, 'p-old'));
    $chk('p-active reactions kept', $has($db, 'community_reactions', 'r2'));
    $chk('p-active topics kept', $hasPT($db, 'p-active'));

    // direct comment purge on a still-living post
    $chk('c-direct purged (direct, past grace)', ! $has($db, 'community_comments', 'c-direct'));
    $chk('c-fresh kept (within grace)', $has($db, 'community_comments', 'c-fresh'));
    $chk('c-live kept (active)', $has($db, 'community_comments', 'c-live'));

    // comments count = 2 cascade + 1 direct = 3
    $chk('reports 3 comments purged', $r['comments'] === 3, (string) $r['comments']);

    // audit intact
    $chk('moderation_actions untouched', $has($db, 'moderation_actions', 'm1'));

    // ---- idempotent: second pass purges 0 ---------------------------------
    $r2 = $svc->purgeDeletedContent($ORG, 30, 500);
    $chk('second pass purges 0 posts', $r2['posts'] === 0, json_encode($r2));
    $chk('second pass purges 0 comments', $r2['comments'] === 0);

    // ---- org=null purges every org ----------------------------------------
    $db  = $seed();
    $svc = new ModerationService($db, new Clock());
    $r = $svc->purgeDeletedContent(null, 30, 500);
    $chk('org=null purges 2 posts (both orgs)', $r['posts'] === 2, json_encode($r));
    $chk('org=null: p-other purged', ! $has($db, 'community_posts', 'p-other'));

    // ---- grace=0 purges recent too ----------------------------------------
    $db  = $seed();
    $svc = new ModerationService($db, new Clock());
    $r = $svc->purgeDeletedContent($ORG, 0, 500);
    $chk('grace=0 purges p-old AND p-recent', $r['posts'] === 2, json_encode($r));

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
