<?= $this->extend('layouts/app') ?>

<?php
/**
 * Campaign reward-ladder (TIER) management console
 * (GET /gamification/campaigns/{id}/tiers/manage) — the browser face of
 * CampaignsController::manageCampaignTiers. Previously a tiered campaign's ladder
 * could only be built via the JSON API (defineTier) and the only browser view
 * was the read-only data-page tier list. This turns the page into a full
 * management console: add a tier + delete individual tiers, all no-JS PRG.
 *
 * All writes are webcsrf-guarded aliases (CSRF field `_csrf`):
 *   - add tier    → POST campaigns/{id}/tiers
 *   - delete tier → POST campaigns/{id}/tiers/{tierId}/delete   (draft only)
 * Each destructive action sits behind a <details> disclosure (CSP-safe confirm —
 * no inline on* handlers, no <script>).
 *
 * Lifecycle rules are enforced authoritatively in CampaignService; the console
 * only mirrors them: a ladder exists only when award_mode=tiered; tiers are
 * editable only while the campaign is a DRAFT. When the campaign is not tiered,
 * or is no longer a draft, the write controls are hidden with an explanatory note.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.campaignTiers.*') with English fallback.
 *
 * @var string                    $campaignId
 * @var array<string,mixed>       $campaign  the campaign row (name, status, award_mode…)
 * @var string                    $status    campaign status
 * @var bool                      $isTiered  whether the ladder applies
 * @var list<array<string,mixed>> $tiers     tier ladder ascending by threshold
 * @var string                    $csrf
 */
$campaignId = (string) ($campaignId ?? '');
$campaign   = is_array($campaign ?? null) ? $campaign : [];
$status     = (string) ($status ?? 'draft');
$isTiered   = (bool) ($isTiered ?? false);
$tiers      = is_array($tiers ?? null) ? $tiers : [];
$csrf       = $csrf ?? '';
$count      = count($tiers);
$isDraft    = $status === 'draft';
$ce         = rawurlencode($campaignId);

$tf = static fn (string $k): string => 'Gamification.campaignTiers.' . $k;

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';
?>

<?= $this->section('content') ?>
    

    <p><a href="/gamification/campaigns/<?= esc($ce, 'attr') ?>" style="color:#fcd34d">&larr; <?= esc(lang(($tf)('backToCampaign'))) ?></a></p>
    <h1><?= esc(lang(($tf)('title'))) ?></h1>
    <div class="sub">
        <?= esc((string) ($campaign['name'] ?? $campaignId)) ?>
        · <span class="cl-tag"><?= esc(lang(($tf)('statusLabel'))) ?>: <?= esc($status) ?></span>
        · <?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang(($tf)('countOne')) : lang(($tf)('count')))) ?>
    </div>

    <?php if ($flashOk !== ''): ?><div class="cl-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cl-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if (! $isTiered): ?>
        <div class="cl-note"><?= esc(lang(($tf)('notTiered'))) ?></div>
    <?php else: ?>
        <?php if (! $isDraft): ?>
            <div class="cl-note"><?= esc(lang(($tf)('frozen'))) ?></div>
        <?php endif; ?>

        <?php if ($isDraft): ?>
        <details class="cl-panel">
            <summary>+ <?= esc(lang(($tf)('form.newTier'))) ?></summary>
            <form method="post" action="/gamification/campaigns/<?= esc($ce, 'attr') ?>/tiers" class="cl-form">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.codeLabel'))) ?></label>
                    <input name="code" required maxlength="40" placeholder="<?= esc(lang(($tf)('form.codePh')), 'attr') ?>">
                </div>
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.nameLabel'))) ?></label>
                    <input name="name" required maxlength="120">
                </div>
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.thresholdLabel'))) ?></label>
                    <input name="threshold_value" type="number" min="1" inputmode="numeric" required>
                </div>
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.awardPointsLabel'))) ?></label>
                    <input name="award_points" type="number" min="0" inputmode="numeric">
                </div>
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.badgeLabel'))) ?></label>
                    <input name="badge_code" maxlength="80">
                </div>
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.iconLabel'))) ?></label>
                    <input name="icon" placeholder="<?= esc(lang(($tf)('form.iconPh')), 'attr') ?>">
                </div>
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.colorLabel'))) ?></label>
                    <input name="color" placeholder="<?= esc(lang(($tf)('form.colorPh')), 'attr') ?>">
                </div>
                <div class="fld">
                    <button type="submit" class="cl-btn"><?= esc(lang(($tf)('form.saveNew'))) ?></button>
                </div>
            </form>
        </details>
        <?php endif; ?>

        <?php if ($tiers === []): ?>
            <div class="cl-empty"><?= esc(lang(($tf)('empty'))) ?></div>
        <?php else: ?>
            <ul class="cl-ladder">
                <?php foreach ($tiers as $i => $t): ?>
                    <?php
                    $tid = (string) ($t['id'] ?? '');
                    $te  = rawurlencode($tid);
                    $pts = $t['award_points'] ?? null;
                    ?>
                    <li class="cl-tier">
                        <span class="cl-pos"><?= esc((string) ((int) ($t['tier_position'] ?? ($i + 1)))) ?></span>
                        <div class="cl-main">
                            <span class="cl-name" style="<?= ! empty($t['color']) ? 'color:' . esc($t['color'], 'attr') . ';' : '' ?>">
                                <?php if (! empty($t['icon'])): ?><?= esc((string) $t['icon']) ?> <?php endif; ?>
                                <?= esc((string) ($t['name'] ?? ($t['code'] ?? '—'))) ?>
                                <span class="cl-code"><?= esc((string) ($t['code'] ?? '')) ?></span>
                            </span>
                            <div class="cl-meta">
                                <span class="cl-tag thr"><?= esc(str_replace('{0}', (string) ((int) ($t['threshold_value'] ?? 0)), lang(($tf)('thresholdTag')))) ?></span>
                                <?php if ($pts !== null && $pts !== ''): ?>
                                    <span class="cl-tag"><?= esc(str_replace('{0}', (string) ((int) $pts), lang(($tf)('pointsTag')))) ?></span>
                                <?php endif; ?>
                                <?php if (! empty($t['badge_code'])): ?>
                                    <span class="cl-tag"><?= esc((string) $t['badge_code']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($isDraft): ?>
                        <details class="cl-inline">
                            <summary><?= esc(lang(($tf)('form.deleteTier'))) ?></summary>
                            <form method="post" action="/gamification/campaigns/<?= esc($ce, 'attr') ?>/tiers/<?= esc($te, 'attr') ?>/delete" class="cl-confirm">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <span><?= esc(lang(($tf)('form.deleteConfirm'))) ?></span>
                                <button type="submit" class="cl-act warn"><?= esc(lang(($tf)('form.deleteYes'))) ?></button>
                            </form>
                        </details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
