<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

/**
 * Validates and normalizes a caller-supplied image into a safe, self-contained
 * `data:` URI.
 *
 * Certificates are rendered by Dompdf with remote asset loading DISABLED (a
 * deliberate SSRF/exfil safeguard), so a logo or background cannot be a URL — it
 * must be embedded in the template HTML as a data-URI. This helper is the single
 * choke point that turns untrusted image input (a `data:` URI from a JSON body,
 * or the raw bytes of a multipart upload) into a canonical data-URI, rejecting
 * anything that is not a small, real raster image of an allowed type.
 *
 * It is pure and dependency-free (no DB, no GD required for the sniff) so the
 * validation contract can be unit-tested in isolation.
 */
final class ImageDataUri
{
    /** MIME type => canonical form, allowlist. */
    private const ALLOWED = [
        'image/png'  => 'image/png',
        'image/jpeg' => 'image/jpeg',
        'image/jpg'  => 'image/jpeg',
        'image/gif'  => 'image/gif',
        'image/webp' => 'image/webp',
    ];

    /**
     * Normalize a `data:` URI string into a canonical, validated data-URI.
     *
     * @param string $input      a `data:image/...;base64,....` string
     * @param int    $maxBytes   maximum decoded byte size (default 512 KiB)
     *
     * @return array{ok:bool, data_uri?:string, mime?:string, bytes?:int, error?:string, message?:string}
     */
    public static function fromDataUri(string $input, int $maxBytes = 524288): array
    {
        $input = trim($input);
        if ($input === '') {
            return self::err('IMAGE_EMPTY', 'image.empty');
        }

        if (! preg_match('#^data:([a-z0-9.+/-]+);base64,(.+)$#is', $input, $m)) {
            return self::err('IMAGE_NOT_DATA_URI', 'image.not_base64_data_uri');
        }

        return self::fromRaw(self::decode($m[2]), strtolower(trim($m[1])), $maxBytes);
    }

    /**
     * Normalize raw decoded image bytes (e.g. from a multipart upload) into a
     * canonical data-URI. When $declaredMime is empty the type is sniffed.
     *
     * @param string|false $bytes        decoded image bytes (false = decode failed)
     * @param string       $declaredMime optional declared MIME (still re-sniffed)
     * @param int          $maxBytes     maximum byte size
     *
     * @return array{ok:bool, data_uri?:string, mime?:string, bytes?:int, error?:string, message?:string}
     */
    public static function fromRaw($bytes, string $declaredMime = '', int $maxBytes = 524288): array
    {
        if ($bytes === false || $bytes === '') {
            return self::err('IMAGE_DECODE_FAILED', 'image.decode_failed');
        }

        $size = strlen($bytes);
        if ($size > $maxBytes) {
            return self::err('IMAGE_TOO_LARGE', 'image.too_large', ['max_bytes' => $maxBytes, 'bytes' => $size]);
        }

        // Trust the actual bytes, not the declared type: sniff the real image.
        $sniffed = self::sniffMime($bytes);
        if ($sniffed === null) {
            return self::err('IMAGE_UNRECOGNIZED', 'image.unrecognized_or_not_image');
        }

        $canonical = self::ALLOWED[$sniffed] ?? null;
        if ($canonical === null) {
            return self::err('IMAGE_TYPE_NOT_ALLOWED', 'image.type_not_allowed', ['sniffed' => $sniffed]);
        }

        // If the caller declared a type, it must agree with the sniffed one
        // (after canonicalization) — mismatch usually means a spoofed upload.
        if ($declaredMime !== '') {
            $declaredCanonical = self::ALLOWED[$declaredMime] ?? $declaredMime;
            if ($declaredCanonical !== $canonical) {
                return self::err('IMAGE_TYPE_MISMATCH', 'image.declared_type_mismatch', [
                    'declared' => $declaredMime,
                    'actual'   => $canonical,
                ]);
            }
        }

        return [
            'ok'       => true,
            'data_uri' => 'data:' . $canonical . ';base64,' . base64_encode($bytes),
            'mime'     => $canonical,
            'bytes'    => $size,
        ];
    }

    /** Strict base64 decode; returns false on any invalid character. */
    private static function decode(string $b64): string|false
    {
        return base64_decode(preg_replace('/\s+/', '', $b64), true);
    }

    /**
     * Identify the image type from magic bytes only (no GD dependency). Returns
     * a MIME string or null when the bytes are not a recognized raster image.
     */
    private static function sniffMime(string $bytes): ?string
    {
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($bytes);
            if (is_array($info) && isset($info['mime']) && $info['mime'] !== '') {
                return strtolower((string) $info['mime']);
            }
        }

        // Fallback magic-byte sniff (keeps the helper usable without GD).
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $ctx
     *
     * @return array{ok:false, error:string, message:string, context?:array<string,mixed>}
     */
    private static function err(string $code, string $message, array $ctx = []): array
    {
        $out = ['ok' => false, 'error' => $code, 'message' => $message];
        if ($ctx !== []) {
            $out['context'] = $ctx;
        }

        return $out;
    }
}
