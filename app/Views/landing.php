<?php
/** @var array<string,mixed> $status */
$status ??= [];
$caps = $status['capabilities'] ?? [];
$modules = $status['modules'] ?? [];
?>

<?php ob_start(); ?>
Win–Build–Send — Platform Status
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        
        
        .wrap { max-width: 820px; margin: 6vh auto; padding: 0 20px; }

        .phase { color:#22d3ee; font-weight:600; margin-bottom: 20px; }

        .stat { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; }

        .stat .k { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:#64748b; }

        .stat .v { font-size:1.05rem; font-weight:700; margin-top:4px; word-break:break-word; }

        h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; color:#818cf8; margin:24px 0 10px; }

        ul { list-style:none; padding:0; margin:0; }

        li { background:#0f172aee; border:1px solid #1e293b; border-radius:10px; padding:12px 16px; margin-bottom:8px; font-size:.9rem; }

        li::before { content:"✓ "; color:#4ade80; font-weight:700; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../Modules/Shared/Views/_shell_open.php'; ?>

<div class="wrap">
    <h1><?= esc($status['platform'] ?? 'Win–Build–Send') ?></h1>
    <div class="phase"><?= esc($status['phase'] ?? '') ?></div>

    <div class="grid">
        <div class="stat"><div class="k">PHP</div><div class="v"><?= esc($status['php'] ?? '') ?></div></div>
        <div class="stat"><div class="k">Framework</div><div class="v"><?= esc($status['framework'] ?? '') ?></div></div>
        <div class="stat"><div class="k">Database</div><div class="v"><?= esc($status['database'] ?? '') ?></div></div>
        <div class="stat"><div class="k">Redis</div><div class="v"><?= esc($status['redis'] ?? '') ?></div></div>
        <div class="stat"><div class="k">Rate policies</div><div class="v"><?= esc((string) ($status['ratePolicies'] ?? 0)) ?></div></div>
    </div>

    <h2>Modules</h2>
    <div class="tags">
        <?php foreach ($modules as $m): ?>
            <span class="tag"><?= esc($m) ?></span>
        <?php endforeach; ?>
    </div>

    <h2>Capabilities</h2>
    <ul>
        <?php foreach ($caps as $c): ?>
            <li><?= esc($c) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<?php include __DIR__ . '/../Modules/Shared/Views/_shell_close.php'; ?>
