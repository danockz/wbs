<?php
/**
 * Public self-join form for a group. Self-contained, sandbox-safe.
 *
 * @var array<string,mixed>       $data    { group, leader, ... }
 * @var list<string>              $errors  human-readable error messages
 * @var array<string,mixed>       $old     previously submitted values
 */
$g      = $data['group'] ?? [];
$leader = $data['leader'] ?? null;
$errors = $errors ?? [];
$old    = $old ?? [];
$slug   = (string) ($g['slug'] ?? '');
include __DIR__ . '/_locale.php';
$name   = (string) ($g['name'] ?? '') !== '' ? (string) $g['name'] : lang('Groups.join.thisGroup');
$open   = (string) ($g['join_policy'] ?? 'approval') === 'open';

$accent = match ((string) ($g['hero_theme'] ?? 'aurora')) {
    'sunrise' => '#fb923c',
    'forest'  => '#34d399',
    'slate'   => '#4f46e5',
    default   => '#22d3ee',
};
?>

<?php ob_start(); ?>
<?= esc($li('Groups.join.metaTitle', $name)) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .card { width:100%; max-width:460px; background:#0f172aee; border:1px solid #1e293b; border-radius:16px; padding:28px 26px; }


        a { color:<?= $accent ?>; text-decoration:none; }


        .back { font-size:.85rem; opacity:.85; }


        input:focus { outline:none; border-color:<?= $accent ?>; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<?= view('WBS\Groups\Views\_public_nav', ['viewer' => $viewer ?? null, 'csrf' => $csrf ?? null]) ?>
    <form class="card" method="post" action="/g/<?= esc($slug) ?>/join">
        <input type="hidden" name="_csrf" value="<?= esc((string) ($csrf ?? ''), 'attr') ?>">
        <a class="back" href="/g/<?= esc($slug) ?>"><?= esc($li('Groups.join.back', $name)) ?></a>
        <h1><?= esc($li('Groups.join.heading', $name)) ?></h1>
        <div class="sub">
            <?php if ($leader !== null): ?>
                <?= esc($li('Groups.join.sponsor', (string) $leader['display_name'])) ?>
            <?php else: ?>
                <?= esc(lang('Groups.join.leaderFollowUp')) ?>
            <?php endif; ?>
        </div>

        <?php if ($errors !== []): ?>
            <?php foreach ($errors as $e): ?>
                <div class="errors"><?= esc($e) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <label for="name"><?= esc(lang('Groups.join.yourName')) ?></label>
        <input id="name" name="name" type="text" required maxlength="150"
               value="<?= esc((string) ($old['name'] ?? '')) ?>" placeholder="<?= esc(lang('Groups.join.namePlaceholder'), 'attr') ?>">

        <label for="email"><?= esc(lang('Groups.join.emailLabel')) ?></label>
        <input id="email" name="email" type="email" required maxlength="190"
               value="<?= esc((string) ($old['email'] ?? '')) ?>" placeholder="<?= esc(lang('Groups.join.emailPlaceholder'), 'attr') ?>">

        <label for="phone"><?= esc(lang('Groups.join.phoneLabel')) ?> <span style="text-transform:none;color:#64748b;"><?= esc(lang('Groups.join.optional')) ?></span></label>
        <input id="phone" name="phone" type="tel" maxlength="40" placeholder="+233 …">

        <button class="btn" type="submit"><?= esc(lang('Groups.join.submit')) ?></button>

        <div class="policy">
            <?php if ($open): ?>
                <?= esc(lang('Groups.join.policyOpen')) ?>
            <?php else: ?>
                <?= esc(lang('Groups.join.policyApproval')) ?>
            <?php endif; ?>
        </div>
    </form>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
