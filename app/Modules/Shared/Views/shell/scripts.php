<?php

declare(strict_types=1);

$wbsShellCtx = (isset($wbsShellCtx) && is_array($wbsShellCtx)) ? $wbsShellCtx : [];
foreach (($wbsShellCtx['scriptAssets'] ?? []) as $wbsScript) {
    $wbsScript = (string) $wbsScript;
    if ($wbsScript === '' || ! str_starts_with($wbsScript, '/assets/')) {
        continue;
    }
    echo '<script src="' . wbs_shell_esc($wbsScript, 'attr') . '" defer></script>' . "\n";
}
