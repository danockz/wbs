<?php
/**
 * CREATE GROUP form (GET /groups/create) — the browser face of
 * GroupController::createForm, and the landing page for the Groups → Create group
 * menu item (previously a 404). Posts back to POST /groups (webcsrf-guarded); on
 * success the controller redirects to the new group (PRG), on failure it
 * re-renders this form with $error and the submitted $old values.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Groups.createForm.*') with English fallback. The type dropdown reflects
 * the standing hierarchy chain (National → Region → Area → Local Assembly →
 * Fellowship → Senior Cell → Cell). The double-submit CSRF token is issued by the
 * controller (renderForm) and echoed into the hidden _csrf field.
 *
 * @var string              $csrf
 * @var string              $error
 * @var array<string,mixed> $old
 */
$csrf  = $csrf ?? '';
$error = $error ?? '';
$old   = $old ?? [];
$ov    = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);

include __DIR__ . '/_locale.php';

$types  = ['national', 'region', 'area', 'local_assembly', 'fellowship', 'senior_cell', 'cell'];
$groups = is_array($groups ?? null) ? $groups : [];
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.createForm.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }


        button { margin-top:22px; width:100%; padding:12px; border:0; border-radius:9px; cursor:pointer;
            background:#d97706; color:#fff; font-size:1rem; font-weight:700; }


        button:hover { background:#b45309; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.createForm.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.createForm.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <form method="post" action="<?= esc(base_url('groups'), 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <label for="name"><?= esc(lang('Groups.createForm.nameLabel')) ?> <span class="req">*</span></label>
            <input id="name" name="name" required value="<?= $ov('name') ?>" placeholder="<?= esc(lang('Groups.createForm.namePh'), 'attr') ?>">

            <label for="type"><?= esc(lang('Groups.createForm.typeLabel')) ?></label>
            <select id="type" name="type">
                <option value=""><?= esc(lang('Groups.createForm.typeNone')) ?></option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= esc($t, 'attr') ?>"<?= ($old['type'] ?? '') === $t ? ' selected' : '' ?>><?= esc(lang('Groups.createForm.type.' . $t)) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="parent_id"><?= esc(lang('Groups.createForm.parentLabel')) ?></label>
            <?php $curParent = (string) ($old['parent_id'] ?? ''); ?>
            <?php if ($groups !== []): ?>
                <select id="parent_id" name="parent_id">
                    <option value=""><?= esc(lang('Groups.createForm.parentNone')) ?></option>
                    <?php
                    $seenParent = false;
                    foreach ($groups as $g):
                        $gid = (string) ($g['id'] ?? '');
                        if ($gid === '') {
                            continue;
                        }
                        $gname  = trim((string) ($g['name'] ?? ''));
                        $glabel = $gname !== '' ? $gname : $gid;
                        // indent by tree depth so the hierarchy reads at a glance
                        $depth  = max(0, (int) ($g['depth'] ?? 0));
                        $prefix = str_repeat("\u{00A0}\u{00A0}", $depth);
                        $gtype  = (string) ($g['type'] ?? '');
                        $suffix = $gtype !== '' ? ' (' . lang('Groups.createForm.type.' . $gtype) . ')' : '';
                        $sel    = $gid === $curParent;
                        $seenParent = $seenParent || $sel;
                        ?>
                        <option value="<?= esc($gid, 'attr') ?>"<?= $sel ? ' selected' : '' ?>><?= $prefix . esc($glabel . $suffix) ?></option>
                    <?php endforeach; ?>
                    <?php if ($curParent !== '' && ! $seenParent): // preserve a parent not in the active list ?>
                        <option value="<?= esc($curParent, 'attr') ?>" selected><?= esc($curParent) ?></option>
                    <?php endif; ?>
                </select>
            <?php else: ?>
                <input id="parent_id" name="parent_id" maxlength="64" value="<?= $ov('parent_id') ?>" placeholder="<?= esc(lang('Groups.createForm.parentPh'), 'attr') ?>">
            <?php endif; ?>

            <label for="slug"><?= esc(lang('Groups.createForm.slugLabel')) ?></label>
            <input id="slug" name="slug" value="<?= $ov('slug') ?>">

            <button type="submit"><?= esc(lang('Groups.createForm.submit')) ?></button>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
