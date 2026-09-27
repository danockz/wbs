<?php
/**
 * Shared HTML layout for server-rendered module pages.
 *
 * Chrome is Shared/Views/_shell_{open,close}.php — the same files every
 * self-contained view includes. Views extend this layout and fill `content`.
 * The universal menu is MenuFragment::html() from `_shell_close.php`.
 *
 * @var string $title
 */
$title = $title ?? 'Win–Build–Send';

include __DIR__ . '/../../Modules/Shared/Views/_shell_open.php';
?>
    <div class="wrap">
        <?= isset($this) && is_object($this) && method_exists($this, 'renderSection')
            ? $this->renderSection('content')
            : '' ?>
    </div>
<?php
include __DIR__ . '/../../Modules/Shared/Views/_shell_close.php';
