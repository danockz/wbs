<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Events\Services\CertificateRenderer;

/**
 * Locks in the certificate template substitution contract (SRS FR-EVT-013).
 *
 * The renderer feeds a certificate template through {@see
 * CertificateRenderer::substitute()} before Dompdf turns it into a PDF. Because
 * the template can be authored by an admin and the values (display name, event
 * title, org name) originate from user-controlled data, the substitution must:
 *  - only interpolate the exact allowlisted keys provided in the context,
 *  - leave unknown / unsupported placeholders completely untouched (no leakage,
 *    no execution), and
 *  - HTML-escape every substituted value so markup cannot be injected into the
 *    rendered certificate.
 *
 * These are pure-string guarantees, so no DB or PDF/QR libraries are needed.
 *
 * @internal
 */
final class CertificateRendererTest extends CIUnitTestCase
{
    public function testSubstitutesAllowlistedPlaceholders(): void
    {
        $html = CertificateRenderer::substitute(
            '<h1>{{member.preferred_name}}</h1><p>{{event.title}}</p>',
            [
                'member.preferred_name' => 'Ama Serwaa',
                'event.title'           => 'Leadership Summit',
            ],
        );

        $this->assertStringContainsString('<h1>Ama Serwaa</h1>', $html);
        $this->assertStringContainsString('<p>Leadership Summit</p>', $html);
    }

    public function testUnknownPlaceholderIsLeftLiteral(): void
    {
        // A placeholder not present in the context is passed through verbatim —
        // never blanked, never resolved from anywhere else.
        $html = CertificateRenderer::substitute(
            'Hello {{member.preferred_name}} / {{secret.api_key}}',
            ['member.preferred_name' => 'Kofi'],
        );

        $this->assertStringContainsString('Hello Kofi', $html);
        $this->assertStringContainsString('{{secret.api_key}}', $html);
    }

    public function testValuesAreHtmlEscaped(): void
    {
        // A malicious display name must not be able to inject markup/script into
        // the certificate.
        $html = CertificateRenderer::substitute(
            '<div>{{member.preferred_name}}</div>',
            ['member.preferred_name' => '<script>alert(1)</script>'],
        );

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testWhitespaceInsideBracesIsTolerated(): void
    {
        $html = CertificateRenderer::substitute(
            '{{  member.preferred_name  }}',
            ['member.preferred_name' => 'Yaa'],
        );

        $this->assertSame('Yaa', $html);
    }

    public function testEmptyContextLeavesTemplateUntouched(): void
    {
        $tpl = 'Static certificate body with no tokens.';
        $this->assertSame($tpl, CertificateRenderer::substitute($tpl, []));
    }
}
