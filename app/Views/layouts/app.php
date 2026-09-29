<?php
/**
 * Compatibility layout for legacy `extend('layouts/app')` views.
 * Delegates to the canonical universal layout.
 */
$wbsShellVariant = $wbsShellVariant ?? 'authenticated';
$wbsShellWrapContent = true;
$wbsShellContentClass = $wbsShellContentClass ?? 'wrap';
include __DIR__ . '/universal.php';
