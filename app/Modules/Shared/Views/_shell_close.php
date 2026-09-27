<?php

declare(strict_types=1);

/**
 * Shared HTML shell — close (menu, optional app.js, </body></html>).
 *
 * Optional: $needsAppJs, $wbsJs, $wbsFooterExtra (markup or callable).
 */

if (! empty($wbsFooterExtra)) {
    if (is_callable($wbsFooterExtra)) {
        $wbsFooterExtra();
    } else {
        echo $wbsFooterExtra;
    }
}

if (class_exists(\WBS\Shared\Navigation\MenuFragment::class)) {
    echo \WBS\Shared\Navigation\MenuFragment::html();
}

$needsAppJs = $needsAppJs ?? false;
$wbsJs      = $wbsJs ?? 'app.js';
if (! empty($needsAppJs)) {
    $jsEsc = function_exists('esc') ? esc($wbsJs, 'attr') : htmlspecialchars((string) $wbsJs, ENT_QUOTES, 'UTF-8');
    echo '<script src="/assets/js/' . $jsEsc . '" defer></script>' . "\n";
}
?>
</body>
</html>
