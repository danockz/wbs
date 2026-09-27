<?php

declare(strict_types=1);

namespace WBS\Community\Services;

/**
 * Strict allowlist HTML sanitizer for community content (SRS FR-COM-004).
 *
 * Rich content is passed through a small allowlist; everything else is escaped
 * or stripped. There is no attempt to "clean" arbitrary HTML — unknown tags are
 * removed entirely, which is the safe default. External links are rendered with
 * safe rel/target attributes and only http(s)/mailto schemes are permitted.
 *
 * This is deliberately dependency-free (no external HTML parser): the input
 * model is a tiny, well-defined subset, so a conservative regex/DOM pass is
 * appropriate and auditable. It errs toward removing rather than preserving.
 */
final class ContentSanitizer
{
    /** Inline/block tags allowed in rendered output. */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'a', 'h3', 'h4'];

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Sanitize author input into safe render HTML. Returns [raw, html].
     *
     * @return array{raw:string,html:string}
     */
    public function sanitize(string $input): array
    {
        $raw = trim($input);

        // Hard cap to prevent abuse; callers can enforce their own limits too.
        if (mb_strlen($raw) > 20000) {
            $raw = mb_substr($raw, 0, 20000);
        }

        // Remove control characters except tab/newline.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? '';

        // Strip anything except the allowed tags. strip_tags handles the bulk;
        // we then neutralize attributes on everything except anchors.
        $allowed = '<' . implode('><', self::ALLOWED_TAGS) . '>';
        $stripped = strip_tags($clean, $allowed);

        // Remove all attributes from non-anchor tags (defense against on* handlers,
        // style, etc.), then rebuild safe anchors.
        $noAttrs = preg_replace_callback(
            '/<(\/?)([a-z0-9]+)([^>]*)>/i',
            function (array $m): string {
                $slash = $m[1];
                $tag   = strtolower($m[2]);
                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    return '';
                }
                if ($tag === 'a' && $slash === '') {
                    return $this->rebuildAnchor($m[3]);
                }

                return "<{$slash}{$tag}>";
            },
            $stripped,
        ) ?? '';

        return ['raw' => $raw, 'html' => $noAttrs];
    }

    /** Extract #hashtags as normalized slugs. @return list<string> */
    public function extractTopics(string $input): array
    {
        preg_match_all('/(?:^|\s)#([\p{L}0-9_]{2,50})/u', $input, $m);
        $slugs = array_map(static fn ($t) => mb_strtolower($t), $m[1] ?? []);

        return array_values(array_unique($slugs));
    }

    /** Rebuild an <a> with only a safe href + hardened rel/target. */
    private function rebuildAnchor(string $attrs): string
    {
        if (! preg_match('/href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $hm)) {
            return '<a>';
        }
        $href = html_entity_decode($hm[2] ?: $hm[3] ?: $hm[4] ?: '', ENT_QUOTES);
        $href = trim($href);

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme === '' || ! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return '<a>';
        }

        $safe = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');

        return '<a href="' . $safe . '" rel="noopener noreferrer nofollow ugc" target="_blank">';
    }
}
