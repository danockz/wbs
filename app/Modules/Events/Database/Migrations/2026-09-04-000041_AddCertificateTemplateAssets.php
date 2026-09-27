<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * FR-EVT-013: certificate template design assets (logo + background).
 *
 * Dompdf renders with remote asset loading DISABLED, so a template's logo and
 * background image cannot be URLs — they are stored as validated, self-contained
 * `data:` URIs (see {@see \WBS\Shared\Support\ImageDataUri}) and inlined into the
 * certificate HTML at render time. LONGTEXT because a base64 data-URI is ~1.37x
 * the raw byte size (a few hundred KB image → a few hundred KB of text).
 */
final class AddCertificateTemplateAssets extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE certificate_templates
                ADD COLUMN logo_data_uri       LONGTEXT NULL AFTER body_template,
                ADD COLUMN background_data_uri LONGTEXT NULL AFTER logo_data_uri
        ');
    }

    public function down(): void
    {
        $this->db->query('
            ALTER TABLE certificate_templates
                DROP COLUMN logo_data_uri,
                DROP COLUMN background_data_uri
        ');
    }
}
