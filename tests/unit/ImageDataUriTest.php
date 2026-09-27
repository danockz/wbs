<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Shared\Support\ImageDataUri;

/**
 * Locks in the certificate design-asset validation contract (SRS FR-EVT-013).
 *
 * Logos/backgrounds are accepted only as `data:` URIs (Dompdf renders with
 * remote loading disabled), and every asset flows through {@see ImageDataUri}
 * before storage. The guarantees under test:
 *  - a genuine image data-URI round-trips to a canonical data-URI,
 *  - non-data-URI / non-base64 input is rejected,
 *  - the type is decided by the real bytes, not the declared MIME (anti-spoof),
 *  - disallowed types are rejected,
 *  - oversized payloads are rejected.
 *
 * Pure-string/byte checks, no DB or image extension required (a magic-byte
 * fallback sniffer keeps it working without GD).
 *
 * @internal
 */
final class ImageDataUriTest extends CIUnitTestCase
{
    /** A minimal but valid 1x1 PNG. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testValidPngDataUriIsAccepted(): void
    {
        $res = ImageDataUri::fromDataUri('data:image/png;base64,' . self::PNG_1X1);

        $this->assertTrue($res['ok']);
        $this->assertSame('image/png', $res['mime']);
        $this->assertStringStartsWith('data:image/png;base64,', $res['data_uri']);
        $this->assertGreaterThan(0, $res['bytes']);
    }

    public function testNonDataUriIsRejected(): void
    {
        $res = ImageDataUri::fromDataUri('https://example.test/logo.png');

        $this->assertFalse($res['ok']);
        $this->assertSame('IMAGE_NOT_DATA_URI', $res['error']);
    }

    public function testBogusBase64IsRejected(): void
    {
        $res = ImageDataUri::fromDataUri('data:image/png;base64,@@@not-base64@@@');

        $this->assertFalse($res['ok']);
        $this->assertContains($res['error'], ['IMAGE_DECODE_FAILED', 'IMAGE_UNRECOGNIZED']);
    }

    public function testTypeDecidedByBytesNotDeclaredMime(): void
    {
        // Bytes are a real PNG but the caller lied and declared JPEG → mismatch.
        $res = ImageDataUri::fromDataUri('data:image/jpeg;base64,' . self::PNG_1X1);

        $this->assertFalse($res['ok']);
        $this->assertSame('IMAGE_TYPE_MISMATCH', $res['error']);
    }

    public function testDisallowedTypeIsRejected(): void
    {
        // A valid-looking SVG (text) is not a raster image type we allow; the
        // sniffer will not recognize it as one of the allowlisted rasters.
        $svg = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $res = ImageDataUri::fromDataUri('data:image/svg+xml;base64,' . $svg);

        $this->assertFalse($res['ok']);
        $this->assertContains($res['error'], ['IMAGE_UNRECOGNIZED', 'IMAGE_TYPE_NOT_ALLOWED', 'IMAGE_TYPE_MISMATCH']);
    }

    public function testOversizedImageIsRejected(): void
    {
        $res = ImageDataUri::fromDataUri('data:image/png;base64,' . self::PNG_1X1, 8);

        $this->assertFalse($res['ok']);
        $this->assertSame('IMAGE_TOO_LARGE', $res['error']);
    }

    public function testEmptyInputIsRejected(): void
    {
        $res = ImageDataUri::fromDataUri('   ');

        $this->assertFalse($res['ok']);
        $this->assertSame('IMAGE_EMPTY', $res['error']);
    }
}
