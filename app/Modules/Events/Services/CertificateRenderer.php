<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use RuntimeException;

/**
 * Renders an event certificate to a real PDF artifact (SRS FR-EVT-013).
 *
 * The heavy work the JobRouter previously stubbed out is done here, in-process
 * but off the request path (it runs from the `event.certificate.render` queue
 * worker). Dompdf is a pure-PHP HTML/CSS → PDF engine: no headless browser and
 * no per-render binary spawn, so it stays light on server resources while the
 * certificate template remains ordinary, flexible HTML.
 *
 *  - The template body (`certificate_templates.body_template`) is HTML with the
 *    same allowlisted `{{namespace.field}}` placeholders used elsewhere; only
 *    known, non-PII-leaking fields are substituted and values are HTML-escaped.
 *  - A verification QR (encoding the public opaque verification id) is embedded
 *    as a data-URI so the PDF is self-contained.
 *  - Remote asset loading is disabled in Dompdf for safety; embed images as
 *    data-URIs in the template.
 *  - The artifact is written under writable/certificates/ and the returned
 *    reference (relative path) is what `markRendered()` stores in render_ref.
 */
final class CertificateRenderer
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly string $storageDir,
        private readonly string $verifyBaseUrl = '',
    ) {
    }

    /**
     * Render the certificate PDF and return the stored artifact reference.
     *
     * @return string render_ref (path relative to the storage dir)
     *
     * @throws RuntimeException when the certificate/template is missing or the
     *                          artifact cannot be written.
     */
    public function render(string $certificateId): string
    {
        $cert = $this->db->table('event_certificates')->where('id', $certificateId)->get()->getRowArray();
        if ($cert === null) {
            throw new RuntimeException("certificate not found: {$certificateId}");
        }

        $html = $this->buildHtml($cert);

        $options = new Options();
        // Security: never fetch remote resources during render; keep memory low.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('defaultPaperSize', 'a4');
        $options->set('defaultPaperOrientation', 'landscape');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $pdf = $dompdf->output();
        if ($pdf === null || $pdf === '') {
            throw new RuntimeException("empty PDF produced for certificate {$certificateId}");
        }

        return $this->store($certificateId, $pdf);
    }

    /**
     * Build the certificate HTML from the resolved template + a strictly
     * allowlisted context, with the verification QR embedded as a data-URI.
     *
     * @param array<string,mixed> $cert
     */
    private function buildHtml(array $cert): string
    {
        $template = null;
        if (! empty($cert['template_id'])) {
            $template = $this->db->table('certificate_templates')
                ->where('id', $cert['template_id'])->get()->getRowArray();
        }

        $event = $this->db->table('events')
            ->select('title, type, starts_at')
            ->where('id', $cert['event_id'])->get()->getRowArray() ?? [];
        $user = $this->db->table('users')
            ->select('display_name')
            ->where('id', $cert['user_id'])->get()->getRowArray() ?? [];
        $org = $this->db->table('organizations')
            ->select('name')->where('id', $cert['organization_id'])->get()->getRowArray() ?? [];

        $context = [
            'member.preferred_name' => (string) ($user['display_name'] ?? 'Recipient'),
            'member.first_name'     => (string) ($user['display_name'] ?? ''),
            'event.title'           => (string) ($event['title'] ?? ''),
            'event.start_local'     => (string) ($event['starts_at'] ?? ''),
            'org.name'              => (string) ($org['name'] ?? ''),
        ];

        $body = (string) ($template['body_template'] ?? $this->defaultTemplate());
        $rendered = self::substitute($body, $context);

        $qrDataUri  = $this->qrDataUri((string) $cert['verification_id']);
        $verifyText = htmlspecialchars((string) $cert['verification_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Design assets are stored as pre-validated data-URIs (never remote
        // URLs, since Dompdf has remote loading disabled). Re-guard the shape
        // before inlining so a bad/legacy row can never inject arbitrary markup.
        $logo       = self::safeDataUri((string) ($template['logo_data_uri'] ?? ''));
        $background  = self::safeDataUri((string) ($template['background_data_uri'] ?? ''));

        $bgStyle   = $background !== ''
            ? 'background-image:url(\'' . $background . '\');background-size:cover;background-position:center;'
            : '';
        $logoBlock = $logo !== ''
            ? '<div class="cert-logo"><img src="' . $logo . '" alt="logo"></div>'
            : '';

        // Wrap the rendered body with a print-safe shell + the verification block.
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<style>'
            . 'html,body{margin:0;padding:0;}'
            . '.cert-shell{padding:32px;' . $bgStyle . '}'
            . '.cert-logo{text-align:center;margin-bottom:12px;}'
            . '.cert-logo img{max-height:96px;max-width:280px;}'
            . '.cert-verify{position:fixed;bottom:18px;right:24px;text-align:center;font-size:9px;color:#555;}'
            . '.cert-verify img{width:96px;height:96px;display:block;margin:0 auto 4px;}'
            . '</style></head><body>'
            . '<div class="cert-shell">' . $logoBlock . $rendered . '</div>'
            . '<div class="cert-verify"><img src="' . $qrDataUri . '" alt="verification QR">'
            . 'Verify: ' . $verifyText . '</div>'
            . '</body></html>';
    }

    /**
     * Defence-in-depth guard: accept a stored asset only if it is a base64
     * image `data:` URI (the exact shape ImageDataUri produces). Anything else
     * — a `javascript:` URI, an unclosed quote, remote URL — returns '' so it
     * is simply omitted rather than inlined into the HTML/CSS.
     */
    private static function safeDataUri(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/]+=*$#', $value) === 1
            ? $value
            : '';
    }

    /**
     * Substitute only allowlisted `{{namespace.field}}` tokens; unknown tokens
     * are left literal and values are HTML-escaped. Mirrors the notification
     * TemplateRenderer's safety model (no code/expression execution).
     *
     * Public + static so the substitution contract can be unit-tested without a
     * database or the PDF/QR libraries: the only keys ever interpolated are the
     * ones present in $context, everything else is passed through verbatim, and
     * values are HTML-escaped so a malicious display name / event title cannot
     * inject markup into the rendered certificate.
     *
     * @param array<string,string> $context
     */
    public static function substitute(string $body, array $context): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+\.[a-z0-9_]+)\s*\}\}/i',
            static function (array $m) use ($context): string {
                $key = strtolower($m[1]);
                if (! array_key_exists($key, $context)) {
                    return $m[0];
                }

                return htmlspecialchars($context[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $body,
        );
    }

    /** Build a PNG QR for the verification id and return it as a data-URI. */
    private function qrDataUri(string $verificationId): string
    {
        $data = $this->verifyBaseUrl !== ''
            ? rtrim($this->verifyBaseUrl, '/') . '/' . $verificationId
            : $verificationId;

        // endroid/qr-code v6: build via the Builder (the v5 fluent
        // QrCode::create()->setSize() API was removed).
        $result = (new Builder(
            writer: new PngWriter(),
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 8,
        ))->build();

        return $result->getDataUri();
    }

    /** Persist the PDF bytes; returns the reference relative to the storage dir. */
    private function store(string $certificateId, string $pdf): string
    {
        $dir = rtrim($this->storageDir, '/') . '/certificates';
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create certificate storage dir: {$dir}");
        }
        $relative = 'certificates/' . $certificateId . '.pdf';
        $path     = rtrim($this->storageDir, '/') . '/' . $relative;
        if (@file_put_contents($path, $pdf) === false) {
            throw new RuntimeException("cannot write certificate artifact: {$path}");
        }

        return $relative;
    }

    /** Fallback template when no certificate template is configured. */
    private function defaultTemplate(): string
    {
        return '<div style="text-align:center;border:6px double #b8860b;padding:48px;">'
            . '<div style="font-size:14px;letter-spacing:3px;color:#b8860b;">CERTIFICATE OF PARTICIPATION</div>'
            . '<div style="font-size:12px;margin-top:24px;">This certifies that</div>'
            . '<div style="font-size:32px;margin:12px 0;font-weight:bold;">{{member.preferred_name}}</div>'
            . '<div style="font-size:12px;">attended</div>'
            . '<div style="font-size:20px;margin:12px 0;">{{event.title}}</div>'
            . '<div style="font-size:11px;color:#555;">on {{event.start_local}}</div>'
            . '<div style="font-size:12px;margin-top:28px;">{{org.name}}</div>'
            . '</div>';
    }
}
