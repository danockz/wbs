<?php

declare(strict_types=1);

namespace WBS\Community\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CM4 — give soft-deleted community content a RETENTION anchor.
 *
 * `community_posts` (and `community_comments`) are soft-removed by state
 * transition (`status='deleted'`) so restore + audit stay possible, but there
 * was no timestamp recording WHEN a row entered the terminal `deleted` state and
 * no lifecycle that ever hard-purges it — soft-deleted rows lingered forever.
 * This adds the minimal state the retention purge sweep needs:
 *   - `deleted_at` — stamped when moderation transitions a row to `deleted`
 *     (cleared on restore), so the purge grace window is measured exactly.
 * Indexed by (organization_id, status, deleted_at) on posts for the sweep's
 * bounded scan; comments get (status, deleted_at). Idempotent.
 *
 * STATUS/VISIBILITY PRECEDENCE (documented here + enforced in FeedService):
 *   status wins over visibility for serving. A row is served ONLY when
 *   status='active'; 'hidden'/'deleted' are never served regardless of
 *   visibility. 'archived' is a VISIBILITY (an active row kept out of the
 *   default feed but reachable directly / in archives), NOT a removal — it is
 *   orthogonal to status and is never purged.
 */
final class AddCommunityContentDeletedAt extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so fieldExists()/getIndexData() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();

        if (! $this->db->fieldExists('deleted_at', 'community_posts')) {
            $this->db->query(
                'ALTER TABLE community_posts ADD COLUMN deleted_at DATETIME(6) NULL AFTER status'
            );
        }
        if (! $this->db->fieldExists('deleted_at', 'community_comments')) {
            $this->db->query(
                'ALTER TABLE community_comments ADD COLUMN deleted_at DATETIME(6) NULL AFTER status'
            );
        }

        $postIdx = array_map(static fn ($i) => $i->name, $this->db->getIndexData('community_posts'));
        if (! in_array('cp_retention_idx', $postIdx, true)) {
            $this->db->query(
                'ALTER TABLE community_posts ADD KEY cp_retention_idx (organization_id, status, deleted_at)'
            );
        }

        $commentIdx = array_map(static fn ($i) => $i->name, $this->db->getIndexData('community_comments'));
        if (! in_array('cc_retention_idx', $commentIdx, true)) {
            $this->db->query(
                'ALTER TABLE community_comments ADD KEY cc_retention_idx (status, deleted_at)'
            );
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        $postIdx = array_map(static fn ($i) => $i->name, $this->db->getIndexData('community_posts'));
        if (in_array('cp_retention_idx', $postIdx, true)) {
            $this->db->query('ALTER TABLE community_posts DROP KEY cp_retention_idx');
        }
        $commentIdx = array_map(static fn ($i) => $i->name, $this->db->getIndexData('community_comments'));
        if (in_array('cc_retention_idx', $commentIdx, true)) {
            $this->db->query('ALTER TABLE community_comments DROP KEY cc_retention_idx');
        }

        if ($this->db->fieldExists('deleted_at', 'community_comments')) {
            $this->db->query('ALTER TABLE community_comments DROP COLUMN deleted_at');
        }
        if ($this->db->fieldExists('deleted_at', 'community_posts')) {
            $this->db->query('ALTER TABLE community_posts DROP COLUMN deleted_at');
        }
    }
}
