<?php

declare(strict_types=1);

$wbsShellCtx = (isset($wbsShellCtx) && is_array($wbsShellCtx)) ? $wbsShellCtx : [];
if (! empty($wbsShellCtx['showMenu']) && class_exists(\WBS\Shared\Navigation\MenuFragment::class)) {
    echo \WBS\Shared\Navigation\MenuFragment::html();
}
