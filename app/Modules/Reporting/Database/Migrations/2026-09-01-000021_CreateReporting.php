<?php

declare(strict_types=1);

namespace WBS\Reporting\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Reporting exports (SRS FR-RPT-004).
 *
 * Report/export generation is ASYNCHRONOUS, permission-checked at creation and
 * again at download, private/short-lived and audit-logged. This table tracks the
 * job lifecycle and the (private) artifact reference + expiry; the actual file is
 * produced by a worker and stored outside the public web root.
 */
final class CreateReporting extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS report_exports (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                requested_by     CHAR(36)     NOT NULL,
                report_key       VARCHAR(80)  NOT NULL,       -- which dashboard/report
                params           JSON         NULL,           -- filters (group/geo/date range)
                format           VARCHAR(12)  NOT NULL DEFAULT "csv", -- csv|xlsx
                status           VARCHAR(16)  NOT NULL DEFAULT "queued", -- queued|running|ready|failed|expired
                artifact_ref     VARCHAR(500) NULL,           -- private storage key (never public URL)
                row_count        INT UNSIGNED NULL,
                watermark        VARCHAR(200) NULL,
                error            VARCHAR(500) NULL,
                created_at       DATETIME(6)  NOT NULL,
                completed_at     DATETIME(6)  NULL,
                expires_at       DATETIME(6)  NULL,
                downloaded_at    DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY re_org_idx (organization_id, status),
                KEY re_requester_idx (requested_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS report_exports');
    }
}
