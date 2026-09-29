<?php
/**
 * Canonical universal browser layout.
 *
 * Supported variants: public, authenticated, auth, admin, minimal, error.
 */

$wbsShellCtx = [
    'variant' => (string) ($wbsShellVariant ?? $shellVariant ?? 'authenticated'),
];
if (isset($wbsPageCapabilities) && is_array($wbsPageCapabilities)) {
    $wbsShellCtx['pageCapabilities'] = $wbsPageCapabilities;
}

include __DIR__ . '/../../Modules/Shared/Views/shell/open.php';
?>
<?php if (! empty($wbsShellWrapContent)): ?>
    <div class="<?= wbs_shell_esc((string) ($wbsShellContentClass ?? 'wrap'), 'attr') ?>">
<?php endif; ?>
<?= isset($this) && is_object($this) && method_exists($this, 'renderSection')
    ? $this->renderSection('content')
    : '' ?>
<?php if (! empty($wbsShellWrapContent)): ?>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../../Modules/Shared/Views/shell/close.php'; ?>
