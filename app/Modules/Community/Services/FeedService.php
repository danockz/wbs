<?php

declare(strict_types=1);

namespace WBS\Community\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\AccessControl\Services\AuthorizationService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Community feed authoring + visibility-aware reads (SRS FR-COM-001/002/004).
 *
 * Every read is filtered by the central PDP BEFORE rows leave the database layer
 * (FR-COM-002): the query itself constrains visibility/scope, and each candidate
 * is confirmed against the PDP so we never return an unauthorized snippet. All
 * authored content is sanitized (FR-COM-004) before persistence.
 */
final class FeedService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ContentSanitizer $sanitizer,
        private readonly AuthorizationService $authz,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function createPost(string $organizationId, string $authorId, array $data): Result
    {
        if (trim((string) ($data['body'] ?? '')) === '' && trim((string) ($data['title'] ?? '')) === '') {
            return Result::fail('EMPTY_POST', 'community.empty_post', 422);
        }
        $visibility = (string) ($data['visibility'] ?? 'group');
        if (! in_array($visibility, ['private', 'group', 'hierarchy', 'public', 'archived'], true)) {
            return Result::fail('BAD_VISIBILITY', 'community.bad_visibility', 422);
        }

        $clean = $this->sanitizer->sanitize((string) ($data['body'] ?? ''));
        $id    = Uuid::v7();
        $now   = $this->clock->nowUtcMicro();

        $this->db->transStart();
        $this->db->table('community_posts')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'group_id'        => $data['group_id'] ?? null,
            'author_id'       => $authorId,
            'kind'            => ($data['kind'] ?? 'post') === 'announcement' ? 'announcement' : 'post',
            'title'           => isset($data['title']) ? mb_substr((string) $data['title'], 0, 200) : null,
            'body'            => $clean['raw'],
            'body_html'       => $clean['html'],
            'visibility'      => $visibility,
            'status'          => 'active',
            'created_at'      => $now,
        ]);

        $this->attachTopics($organizationId, $id, (string) ($data['body'] ?? '') . ' ' . (string) ($data['title'] ?? ''));
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return Result::fail('POST_FAILED', 'community.post_failed', 500);
        }

        return Result::created(['post_id' => $id, 'visibility' => $visibility]);
    }

    public function comment(string $postId, string $authorId, string $body, ?string $parentId = null): Result
    {
        $post = $this->db->table('community_posts')->where('id', $postId)->get()->getRowArray();
        if ($post === null || $post['status'] !== 'active') {
            return Result::notFound('community.post_not_found', 'POST_NOT_FOUND');
        }
        if ((int) $post['locked'] === 1) {
            return Result::fail('POST_LOCKED', 'community.post_locked', 409);
        }
        if (trim($body) === '') {
            return Result::fail('EMPTY_COMMENT', 'community.empty_comment', 422);
        }

        $clean = $this->sanitizer->sanitize($body);
        $id    = Uuid::v7();
        $now   = $this->clock->nowUtcMicro();

        $this->db->transStart();
        $this->db->table('community_comments')->insert([
            'id'         => $id,
            'post_id'    => $postId,
            'parent_id'  => $parentId,
            'author_id'  => $authorId,
            'body'       => $clean['raw'],
            'body_html'  => $clean['html'],
            'status'     => 'active',
            'created_at' => $now,
        ]);
        $this->db->table('community_posts')->where('id', $postId)
            ->set('comment_count', 'comment_count + 1', false)->update();
        $this->db->transComplete();

        return Result::created(['comment_id' => $id]);
    }

    /** Toggle a reaction; idempotent add, returns current state. */
    public function react(string $postId, string $userId, string $reaction = 'like'): Result
    {
        $existing = $this->db->table('community_reactions')
            ->where('post_id', $postId)->where('user_id', $userId)->where('reaction', $reaction)
            ->get()->getRowArray();

        $this->db->transStart();
        if ($existing !== null) {
            $this->db->table('community_reactions')->where('id', $existing['id'])->delete();
            $this->db->table('community_posts')->where('id', $postId)->where('reaction_count >', 0)
                ->set('reaction_count', 'reaction_count - 1', false)->update();
            $state = 'removed';
        } else {
            $this->db->table('community_reactions')->insert([
                'id'         => Uuid::v7(),
                'post_id'    => $postId,
                'user_id'    => $userId,
                'reaction'   => $reaction,
                'created_at' => $this->clock->nowUtcMicro(),
            ]);
            $this->db->table('community_posts')->where('id', $postId)
                ->set('reaction_count', 'reaction_count + 1', false)->update();
            $state = 'added';
        }
        $this->db->transComplete();

        return Result::ok(['state' => $state, 'reaction' => $reaction]);
    }

    /**
     * Visibility-filtered feed read. `public` posts need no viewer; anything else
     * is confirmed through the PDP per candidate before inclusion.
     *
     * @param array<string,mixed> $opts
     */
    public function feed(string $organizationId, ?string $viewerId, array $opts = []): Result
    {
        $limit  = min(max((int) ($opts['limit'] ?? 20), 1), 100);
        $groupId = $opts['group_id'] ?? null;

        $q = $this->db->table('community_posts')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereIn('visibility', $viewerId === null ? ['public'] : ['public', 'group', 'hierarchy', 'private'])
            ->orderBy('pinned', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->limit($limit + 1);
        if ($groupId !== null) {
            $q->where('group_id', $groupId);
        }
        $rows = $q->get()->getResultArray();

        $visible = [];
        foreach ($rows as $row) {
            if ($this->canView($organizationId, $viewerId, $row)) {
                $visible[] = $this->publicShape($row);
            }
            if (count($visible) >= $limit) {
                break;
            }
        }

        return Result::ok(['posts' => $visible, 'count' => count($visible)]);
    }

    /** PDP-backed single-post authorization (FR-COM-002). */
    private function canView(string $organizationId, ?string $viewerId, array $post): bool
    {
        if ($post['visibility'] === 'public') {
            return true;
        }
        if ($viewerId === null) {
            return false;
        }
        if ($post['author_id'] === $viewerId) {
            return true;
        }

        return $this->authz->isAllowed(new AccessRequest(
            organizationId: $organizationId,
            subjectId: $viewerId,
            action: 'community.post.view',
            objectType: 'community_post',
            objectId: $post['id'],
            attributes: [
                'visibility' => $post['visibility'],
                'group_id'   => $post['group_id'],
            ],
        ));
    }

    /** @return array<string,mixed> */
    private function publicShape(array $row): array
    {
        return [
            'id'             => $row['id'],
            'author_id'      => $row['author_id'],
            'kind'           => $row['kind'],
            'title'          => $row['title'],
            'body_html'      => $row['body_html'],
            'visibility'     => $row['visibility'],
            'pinned'         => (bool) $row['pinned'],
            'locked'         => (bool) $row['locked'],
            'reaction_count' => (int) $row['reaction_count'],
            'comment_count'  => (int) $row['comment_count'],
            'created_at'     => $row['created_at'],
        ];
    }

    private function attachTopics(string $organizationId, string $postId, string $text): void
    {
        foreach ($this->sanitizer->extractTopics($text) as $slug) {
            $topic = $this->db->table('community_topics')
                ->where('organization_id', $organizationId)->where('slug', $slug)
                ->get()->getRowArray();
            if ($topic === null) {
                $topicId = Uuid::v7();
                $this->db->table('community_topics')->insert([
                    'id'              => $topicId,
                    'organization_id' => $organizationId,
                    'slug'            => $slug,
                    'label'           => '#' . $slug,
                    'created_at'      => $this->clock->nowUtcMicro(),
                ]);
            } else {
                $topicId = $topic['id'];
            }
            // Ignore duplicate (post,topic) via INSERT IGNORE semantics.
            $this->db->query(
                'INSERT IGNORE INTO community_post_topics (post_id, topic_id) VALUES (?, ?)',
                [$postId, $topicId],
            );
        }
    }
}
