<?= $this->extend('layouts/app') ?>

<?php
/**
 * Partnership tiers — admin catalog + in-page CRUD
 * (GET /vbcs/partnership/tiers/manage) — the browser face of
 * VbcsController::managePartnershipTiers. The public catalogue
 * (partnership_tiers.php) is read-only and active-only; this admin view lists
 * ALL tiers (incl. disabled) with inline create/edit/disable, mirroring the
 * gamification ranks/badges/streaks/achievements admin catalogs.
 *
 * define() is an UPSERT keyed on (org, code): the "New tier" form and each row's
 * "Edit" form both POST to /vbcs/partnership/tiers — creating when the code is
 * new, updating in place otherwise. Tiers are DISABLED (status flip) rather than
 * deleted. All writes are webcsrf-guarded and PRG back here with a localized
 * flash.
 *
 * Money (min_pgv_minor) is captured in integer MINOR units to stay consistent
 * with the ledger (a hint says so). Progressive-enhancement: panels are plain
 * <details>, usable with NO JavaScript (CSP-safe: no inline on* handlers, no
 * <script>).
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Contributions.partnershipTierForm.*') with English fallback.
 *
 * @var list<array<string,mixed>> $tiers partnership_level_definitions rows
 * @var string                    $csrf  webcsrf double-submit token
 * @var string                    $title
 */
include __DIR__ . '/_money.php';
$tiers = is_array($tiers ?? null) ? $tiers : [];
$csrf  = $csrf ?? '';
$count = count($tiers);
$title = $title ?? lang('Contributions.partnershipTierForm.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$tf = static fn (string $key): string => 'Contributions.partnershipTierForm.' . $key;
?>

<?= $this->section('content') ?>
    

    <div>
        <h1><?= esc(lang(($tf)('title'))) ?></h1>
        <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang(($tf)('countOne')) : lang(($tf)('count')))) ?></div>
        <p style="margin-top:6px"><a href="/vbcs/partnership/tiers" style="color:#5eead4"><?= esc(lang(($tf)('viewPublic'))) ?></a></p>
    </div>

    <?php if ($flashOk !== ''): ?><div class="cf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php
    $defineForm = static function (array $t, bool $isNew) use ($csrf, $tf): void {
        $code = (string) ($t['code'] ?? '');
        ?>
        <form method="post" action="/vbcs/partnership/tiers" class="cf-form">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="fld">
                <label><?= esc(lang(($tf)('codeLabel'))) ?></label>
                <?php if ($isNew): ?>
                    <input name="code" required placeholder="<?= esc(lang(($tf)('codePh')), 'attr') ?>">
                <?php else: ?>
                    <input value="<?= esc($code, 'attr') ?>" readonly title="<?= esc(lang(($tf)('codeLocked')), 'attr') ?>">
                    <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                <?php endif; ?>
            </div>
            <div class="fld">
                <label><?= esc(lang(($tf)('nameLabel'))) ?></label>
                <input name="name" required maxlength="150" value="<?= esc((string) ($t['name'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($tf)('minPgvLabel'))) ?> <span class="hint"><?= esc(lang(($tf)('minPgvHint'))) ?></span></label>
                <input name="min_pgv_minor" type="number" min="0" step="1" inputmode="numeric" value="<?= esc((string) (int) ($t['min_pgv_minor'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($tf)('minMonthsLabel'))) ?></label>
                <input name="min_consecutive_months" type="number" min="0" step="1" inputmode="numeric" value="<?= esc((string) (int) ($t['min_consecutive_months'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($tf)('sortLabel'))) ?></label>
                <input name="sort_order" type="number" value="<?= esc((string) (int) ($t['sort_order'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($tf)('iconLabel'))) ?></label>
                <input name="icon" value="<?= esc((string) ($t['icon'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang(($tf)('iconPh')), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($tf)('colorLabel'))) ?></label>
                <input name="color" value="<?= esc((string) ($t['color'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang(($tf)('colorPh')), 'attr') ?>">
            </div>
            <div class="fld wide">
                <label><?= esc(lang(($tf)('descriptionLabel'))) ?></label>
                <input name="description" maxlength="255" value="<?= esc((string) ($t['description'] ?? ''), 'attr') ?>">
            </div>
            <div class="cf-actions">
                <button type="submit" class="cf-btn"><?= esc($isNew ? lang(($tf)('saveNew')) : lang(($tf)('saveEdit'))) ?></button>
            </div>
        </form>
        <?php
    };
    ?>

    <details class="cf-panel">
        <summary>+ <?= esc(lang(($tf)('newTier'))) ?></summary>
        <?php $defineForm([], true); ?>
    </details>

    <?php if ($tiers === []): ?>
        <div class="empty"><?= esc(lang(($tf)('empty'))) ?></div>
    <?php else: ?>
        <?php foreach ($tiers as $t): ?>
            <?php
            $tcode    = (string) ($t['code'] ?? '');
            $tc       = rawurlencode($tcode);
            $status   = (string) ($t['status'] ?? 'active');
            $isActive = $status !== 'inactive';
            ?>
            <div class="card<?= $isActive ? '' : ' tier-disabled' ?>">
                <div class="row">
                    <span class="author" style="<?= ! empty($t['color']) ? 'color:' . esc($t['color'], 'attr') . ';' : '' ?>">
                        <?php if (! empty($t['icon'])): ?><?= esc((string) $t['icon']) ?> <?php endif; ?>
                        <?= esc((string) ($t['name'] ?? ($tcode !== '' ? $tcode : '—'))) ?>
                    </span>
                    <span class="tier-badges">
                        <span class="tier-tag"><?= esc(str_replace('{0}', $money((int) ($t['min_pgv_minor'] ?? 0)), lang('Contributions.minPgvSuffix'))) ?></span>
                        <span class="tier-tag"><?= esc(str_replace('{0}', (string) (int) ($t['min_consecutive_months'] ?? 0), lang('Contributions.consecutiveMonths'))) ?></span>
                        <?php if (! $isActive): ?><span class="tier-tag"><?= esc(lang(($tf)('disabled'))) ?></span><?php endif; ?>
                    </span>
                </div>
                <?php if (! empty($t['description'])): ?><div class="counts"><?= esc((string) $t['description']) ?></div><?php endif; ?>
                <div class="meta"><?= esc(lang(($tf)('codeLabel'))) ?>: <?= esc($tcode !== '' ? $tcode : '—') ?></div>
                <?php if ($tcode !== ''): ?>
                <div class="cf-acts">
                    <?php if ($isActive): ?>
                    <details class="cf-inline" style="border:0;margin:0">
                        <summary class="cf-act warn"><?= esc(lang(($tf)('disable'))) ?></summary>
                        <form method="post" action="/vbcs/partnership/tiers/<?= esc($tc, 'attr') ?>/disable" style="padding:8px 0">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <span class="hint" style="color:#94a3b8;font-size:.72rem"><?= esc(lang(($tf)('disableConfirm'))) ?></span>
                            <button type="submit" class="cf-act warn"><?= esc(lang(($tf)('disableYes'))) ?></button>
                        </form>
                    </details>
                    <?php endif; ?>
                </div>
                <details class="cf-inline">
                    <summary><?= esc(lang(($tf)('edit'))) ?></summary>
                    <?php $defineForm($t, false); ?>
                </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
