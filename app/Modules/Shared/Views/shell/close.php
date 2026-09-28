<?php

declare(strict_types=1);

include __DIR__ . '/context.php';

if (! empty($wbsShellCtx['footerExtra'])) {
    if (is_callable($wbsShellCtx['footerExtra'])) {
        $wbsShellCtx['footerExtra']();
    } else {
        echo $wbsShellCtx['footerExtra'];
    }
}

include __DIR__ . '/menu.php';
include __DIR__ . '/scripts.php';
?>
</body>
</html>
