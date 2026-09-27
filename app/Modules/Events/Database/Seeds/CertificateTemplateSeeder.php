<?php

declare(strict_types=1);

namespace WBS\Events\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\ImageDataUri;
use WBS\Shared\Support\Uuid;

/**
 * Seeds starter certificate templates (SRS FR-EVT-013).
 *
 * A certificate template IS the "design input": `body_template` holds the HTML
 * design, styled with inline CSS, using the allowlisted `{{namespace.field}}`
 * placeholders the renderer substitutes ({@see
 * \WBS\Events\Services\CertificateRenderer}). Admins can author their own via
 * `POST /events/certificate-templates`; these seeds give a usable default so a
 * certificate can be rendered end-to-end out of the box.
 *
 * Keys off the 'wbs' organization created by RbacBootstrapSeeder. Idempotent:
 * upserts on the (organization_id, event_type, version) unique key.
 *
 *   php spark db:seed 'WBS\Events\Database\Seeds\CertificateTemplateSeeder'
 */
class CertificateTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            if (is_cli()) {
                fwrite(STDERR, "CertificateTemplateSeeder: run RbacBootstrapSeeder first (no 'wbs' org).\n");
            }

            return;
        }
        $orgId = $org['id'];
        $now   = date('Y-m-d H:i:s');

        // Starter logo (a small "WBS" emblem PNG), shipped as a data-URI so the
        // demo certificate renders WITH a logo out of the box. It goes through
        // the same ImageDataUri validator real uploads use — proving the
        // end-to-end asset path — and is simply omitted if it fails to validate.
        $logo = $this->starterLogo();

        // event_type = null is the DEFAULT template (used when no type-specific
        // template exists). 'gathering' matches the demo event's type so the
        // DemoDataSeeder's event resolves a tailored design.
        $templates = [
            [
                'event_type'  => null,
                'name'        => 'Default Certificate of Participation',
                'signer_role' => 'org_admin',
                'body'        => $this->participationDesign(),
            ],
            [
                'event_type'  => 'gathering',
                'name'        => 'Gathering Attendance Certificate',
                'signer_role' => 'event_organizer',
                'body'        => $this->gatheringDesign(),
            ],
        ];

        $seeded = 0;
        foreach ($templates as $t) {
            $row = [
                'organization_id' => $orgId,
                'event_type'      => $t['event_type'],
                'name'            => $t['name'],
                'version'         => 1,
                'body_template'   => $t['body'],
                'logo_data_uri'   => $logo,
                'signer_role'     => $t['signer_role'],
                'status'          => 'active',
                'created_at'      => $now,
            ];

            $existing = $this->db->table('certificate_templates')
                ->where('organization_id', $orgId)
                ->where('event_type', $t['event_type'])
                ->where('version', 1)
                ->get()->getRowArray();

            if ($existing !== null) {
                $this->db->table('certificate_templates')->where('id', $existing['id'])->update($row);
            } else {
                $row['id'] = Uuid::v7();
                $this->db->table('certificate_templates')->insert($row);
            }
            $seeded++;
        }

        if (is_cli()) {
            fwrite(STDOUT, "CertificateTemplateSeeder: {$seeded} template(s) upserted.\n");
        }
    }

    /**
     * Load the shipped starter logo and normalize it through the SAME validator
     * real uploads use. Returns a canonical data-URI, or null if the asset is
     * missing / fails validation (the template just renders without a logo).
     */
    private function starterLogo(): ?string
    {
        $path = __DIR__ . '/assets/wbs_logo.datauri.txt';
        if (! is_file($path)) {
            return null;
        }
        $raw = (string) @file_get_contents($path);
        $res = ImageDataUri::fromDataUri($raw);

        return ($res['ok'] ?? false) ? $res['data_uri'] : null;
    }

    /**
     * Default design — a classic bordered "Certificate of Participation".
     * Inline CSS only (Dompdf renders it directly); allowlisted placeholders.
     */
    private function participationDesign(): string
    {
        return <<<'HTML'
            <div style="border:8px double #b8860b;padding:56px 48px;text-align:center;">
                <div style="font-size:13px;letter-spacing:4px;color:#b8860b;">CERTIFICATE OF PARTICIPATION</div>
                <div style="margin-top:28px;font-size:13px;color:#444;">This is proudly presented to</div>
                <div style="margin:14px 0;font-size:34px;font-weight:bold;color:#1a1a1a;">{{member.preferred_name}}</div>
                <div style="font-size:13px;color:#444;">for attending</div>
                <div style="margin:12px 0;font-size:22px;color:#1a1a1a;">{{event.title}}</div>
                <div style="font-size:12px;color:#666;">held on {{event.start_local}}</div>
                <div style="margin-top:40px;font-size:14px;color:#1a1a1a;">{{org.name}}</div>
                <div style="margin-top:4px;font-size:11px;color:#888;">Win &middot; Build &middot; Send</div>
            </div>
            HTML;
    }

    /** A lighter, warmer design for community gatherings. */
    private function gatheringDesign(): string
    {
        return <<<'HTML'
            <div style="border:4px solid #2e7d32;border-radius:12px;padding:52px 44px;text-align:center;background:#f6fbf6;">
                <div style="font-size:12px;letter-spacing:3px;color:#2e7d32;">CERTIFICATE OF ATTENDANCE</div>
                <div style="margin-top:24px;font-size:12px;color:#444;">Presented to</div>
                <div style="margin:12px 0;font-size:30px;font-weight:bold;color:#1b1b1b;">{{member.preferred_name}}</div>
                <div style="font-size:12px;color:#444;">for being part of</div>
                <div style="margin:10px 0;font-size:20px;color:#1b1b1b;">{{event.title}}</div>
                <div style="font-size:11px;color:#666;">{{event.start_local}}</div>
                <div style="margin-top:36px;font-size:13px;color:#1b1b1b;">{{org.name}}</div>
            </div>
            HTML;
    }
}
