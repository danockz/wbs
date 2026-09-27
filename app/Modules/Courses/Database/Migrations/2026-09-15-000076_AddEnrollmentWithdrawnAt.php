<?php

declare(strict_types=1);

namespace WBS\Courses\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CO6 (Theme B account-lifecycle) — record WHEN an enrollment was withdrawn.
 *
 * `enrollments.status` already models `withdrawn`, but there was no timestamp of
 * the transition. The account-teardown consumer
 * ({@see \WBS\Courses\Services\EnrollmentService::withdrawActiveForSubject()})
 * and the merge-dedup path both set `withdrawn_at` so an operator can tell a
 * teardown/merge withdrawal apart from a historical one and audit the moment a
 * gone learner's in-flight enrollments were closed.
 *
 * Additive + nullable + reversible; existing rows keep NULL.
 */
final class AddEnrollmentWithdrawnAt extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE enrollments
                ADD COLUMN withdrawn_at DATETIME NULL AFTER completed_at
        ');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE enrollments DROP COLUMN withdrawn_at');
    }
}
