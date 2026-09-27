<?php
/**
 * Badge definitions — admin list + in-page CRUD (GET /gamification/badges) — the
 * browser face of AwardsController::listBadges. Badges in sort order, each showing
 * code, name, visibility, permanence and expiry policy, now with inline create /
 * edit / disable controls posting to the webcsrf-guarded catalog routes.
 *
 * define() is an UPSERT keyed on (code, group): the "New badge" form and each
 * row's "Edit" form both POST to /gamification/badges — creating when the code is
 * new, updating in place when it already exists. Badges are DISABLED (status flip)
 * rather than deleted so award history is preserved. Progressive-enhancement: the
 * edit panels are plain <details> so the page is fully usable with no JavaScript.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.badges.*') with English fallback; the visibility
 * vocabulary is localized with a raw-value fallback.
 *
 * @var list<array<string,mixed>> $badges badges rows
 * @var string                    $csrf   webcsrf double-submit token
 * @var string                    $title
 */
$badges = is_array($badges ?? null) ? $badges : [];
$csrf   = $csrf ?? '';
$count  = count($badges);
$title  = $title ?? lang('Gamification.admin.badges.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$VIS = ['public', 'group', 'private'];
$vis = static function (string $v): string {
    $v = strtolower(trim($v));
    if ($v === '') { return '—'; }
    $s = lang('Gamification.admin.badges.visibility.' . $v);
    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : $v;
};
$visLabel = static function (string $v) use ($vis): string { return $vis($v); };
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <div class="cf-head">
        <div class="cf-txt">
            <h1><?= esc(lang('Gamification.admin.badges.title')) ?></h1>
            <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.badges.countOne') : lang('Gamification.admin.badges.count'))) ?></div>
        </div>
    </div>

    <?php if ($flashOk !== ''): ?><div class="cf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php
    // Reusable define form. $b is the row (empty for create); $isNew controls
    // whether the code field is editable (code is the upsert key → locked on edit).
    $defineForm = static function (array $b, bool $isNew) use ($csrf, $VIS, $visLabel): void {
        $code = (string) ($b['code'] ?? '');
        ?>
        <form method="post" action="/gamification/badges" class="cf-form">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.badges.form.codeLabel')) ?></label>
                <?php if ($isNew): ?>
                    <input name="code" required placeholder="<?= esc(lang('Gamification.admin.badges.form.codePh'), 'attr') ?>">
                <?php else: ?>
                    <input value="<?= esc($code, 'attr') ?>" readonly title="<?= esc(lang('Gamification.admin.badges.form.codeLocked'), 'attr') ?>">
                    <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                <?php endif; ?>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.badges.form.nameLabel')) ?></label>
                <input name="name" required value="<?= esc((string) ($b['name'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.badges.form.visibilityLabel')) ?></label>
                <select name="visibility">
                    <?php foreach ($VIS as $vopt): ?>
                        <option value="<?= esc($vopt, 'attr') ?>" <?= (string) ($b['visibility'] ?? 'public') === $vopt ? 'selected' : '' ?>><?= esc($visLabel($vopt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.badges.form.sortLabel')) ?></label>
                <input name="sort_order" type="number" value="<?= esc((string) (int) ($b['sort_order'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.badges.form.iconLabel')) ?></label>
                <input name="icon" value="<?= esc((string) ($b['icon'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Gamification.admin.badges.form.iconPh'), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.badges.form.expiryLabel')) ?></label>
                <input name="expiry_policy" value="<?= esc((string) ($b['expiry_policy'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Gamification.admin.badges.form.expiryPh'), 'attr') ?>">
            </div>
            <div class="fld wide">
                <label><?= esc(lang('Gamification.admin.badges.form.descriptionLabel')) ?></label>
                <input name="description" value="<?= esc((string) ($b['description'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld chk">
                <input type="checkbox" id="perm-<?= esc($isNew ? 'new' : $code, 'attr') ?>" name="permanent" value="1" <?= ! empty($b['permanent']) || $isNew ? 'checked' : '' ?>>
                <label for="perm-<?= esc($isNew ? 'new' : $code, 'attr') ?>" style="text-transform:none;letter-spacing:0;"><?= esc(lang('Gamification.admin.badges.form.permanentLabel')) ?></label>
            </div>
            <div class="cf-actions">
                <button type="submit" class="cf-btn"><?= esc($isNew ? lang('Gamification.admin.badges.form.saveNew') : lang('Gamification.admin.badges.form.saveEdit')) ?></button>
            </div>
        </form>
        <?php
    };
    ?>

    <details class="cf-panel">
        <summary>+ <?= esc(lang('Gamification.admin.badges.form.newBadge')) ?></summary>
        <?php $defineForm([], true); ?>
    </details>

    <?php if ($badges === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.badges.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($badges as $b): ?>
            <?php
            $bcode    = (string) ($b['code'] ?? '');
            $bc       = rawurlencode($bcode);
            $status   = (string) ($b['status'] ?? 'active');
            $isActive = $status !== 'inactive';
            ?>
            <div class="card<?= $isActive ? '' : ' badge-disabled' ?>">
                <div class="row">
                    <span class="author"><?= esc((string) ($b['name'] ?? ($bcode !== '' ? $bcode : '—'))) ?></span>
                    <span class="pill"><?= esc($vis((string) ($b['visibility'] ?? ''))) ?></span>
                </div>
                <?php if (! empty($b['criteria'])): ?><div class="counts"><?= esc((string) $b['criteria']) ?></div><?php endif; ?>
                <div class="meta">
                    <?= esc(lang('Gamification.admin.badges.code')) ?>: <?= esc($bcode !== '' ? $bcode : '—') ?>
                    · <?= esc(! empty($b['permanent']) ? lang('Gamification.admin.badges.permanent') : lang('Gamification.admin.badges.expiring')) ?>
                    <?php if (! empty($b['group_id'])): ?> · <?= esc(lang('Gamification.admin.badges.group')) ?>: <?= esc((string) $b['group_id']) ?><?php endif; ?>
                    <?php if (! $isActive): ?> · <?= esc($status) ?><?php endif; ?>
                </div>
                <?php if ($bcode !== ''): ?>
                <div class="cf-acts">
                    <a class="cf-act" href="/gamification/badges/<?= esc($bc, 'attr') ?>"><?= esc(lang('Gamification.admin.badges.form.view')) ?></a>
                    <?php if ($isActive): ?>
                    <form method="post" action="/gamification/badges/<?= esc($bc, 'attr') ?>/disable"
                          onsubmit="return confirm('<?= esc(lang('Gamification.admin.badges.form.disableConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="cf-act warn"><?= esc(lang('Gamification.admin.badges.form.disable')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <details class="cf-inline">
                    <summary><?= esc(lang('Gamification.admin.badges.form.edit')) ?></summary>
                    <?php $defineForm($b, false); ?>
                </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
