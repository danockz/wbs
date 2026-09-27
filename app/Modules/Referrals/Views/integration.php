<?php
/**
 * Member self-service — "My integration" (FR-REF-3b).
 * Self-contained page (own <html>, inline styles, no JS, CSP-clean); includes
 * _locale.php for a locale-aware <html lang dir> (RTL for Arabic).
 *
 * The required decisions (salvation, water baptism, Holy Spirit baptism,
 * foundation course — each standalone) are rendered as tiles with their
 * recorded date (or "still to be recorded"). A self-declaration POSTs to
 * /my/integration and is
 * stored PENDING until the member's sponsor confirms it — so the page also
 * lists the member's own declarations with their status.
 *
 * @var array{satisfied:list<string>,outstanding:list<string>,integrated:bool,enabled:bool,byType:array<string,string>} $state
 * @var list<array<string,mixed>> $declarations
 * @var list<string>              $groups
 * @var list<string>              $types
 * @var string                    $csrf
 */
$state        = $state ?? ['satisfied' => [], 'outstanding' => [], 'integrated' => false, 'enabled' => false, 'byType' => []];
$declarations = $declarations ?? [];
$groups       = $groups ?? [];
$types        = $types ?? [];
$csrf         = $csrf ?? '';

include __DIR__ . '/_locale.php';

// Labels resolved inline (views stay dependency-free): localized key first,
// raw-value fallback so an unknown slug never breaks the page.
$groupLabel = static function (string $g): string {
    $v = lang('Referrals.integration.decision.' . $g);

    return $v === 'Referrals.integration.decision.' . $g ? ucwords(str_replace('_', ' ', $g)) : $v;
};
$typeLabel = static function (string $t): string {
    $v = lang('Referrals.decision.types.' . $t);

    return $v === 'Referrals.decision.types.' . $t ? ucwords(str_replace('_', ' ', $t)) : $v;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.integration.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 860px; margin: 0 auto; padding: 5vh 20px 60px; }


        .banner { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:16px 20px; margin-bottom:24px; }


        .badge.pending { border-color:#f59e0b88; color:#fbbf24; }


        .badge.rejected { border-color:#ef444488; color:#f87171; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="wrap">
        <h1><?= esc(lang('Referrals.integration.heading')) ?></h1>
        <div class="sub"><?= esc(lang('Referrals.integration.sub')) ?></div>

        <?php if (! $state['enabled']): ?>
            <div class="banner">
                <h2><?= esc(lang('Referrals.integration.disabledTitle')) ?></h2>
                <div class="muted"><?= esc(lang('Referrals.integration.disabledSub')) ?></div>
            </div>
        <?php else: ?>
            <div class="banner <?= $state['integrated'] ? 'ok' : '' ?>">
                <h2>
                    <?php if ($state['integrated']): ?>
                        <span class="okword">✓</span> <?= esc(lang('Referrals.integration.integrated')) ?>
                    <?php else: ?>
                        <span class="noword">◔</span> <?= esc(lang('Referrals.integration.notIntegrated')) ?>
                    <?php endif; ?>
                </h2>
                <div class="muted">
                    <?php if ($state['outstanding'] !== []): ?>
                        <?= esc(lang('Referrals.integration.outstandingLbl')) ?>:
                        <?= esc(implode(', ', array_map($groupLabel, $state['outstanding']))) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tiles">
                <?php
                // Each required decision stands alone: the group's own type
                // carries its date (1:1 — no bundled baptisms).
                $groupTypes = [
                    'salvation'           => ['salvation'],
                    'water_baptism'       => ['water_baptism'],
                    'holy_spirit_baptism' => ['holy_spirit_baptism'],
                    'foundation_course'   => ['foundation_course'],
                ];
                foreach ($groups as $g):
                    $done = in_array($g, $state['satisfied'], true);
                ?>
                    <div class="tile <?= $done ? 'done' : 'miss' ?>">
                        <div class="k"><?= esc($groupLabel($g)) ?></div>
                        <div class="v"><?= $done ? '✓' : '—' ?></div>
                        <?php foreach ($groupTypes[$g] ?? [] as $t): ?>
                            <?php if (isset($state['byType'][$t])): ?>
                                <span class="tag"><?= esc($typeLabel($t)) ?> · <?= esc($state['byType'][$t]) ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="panel">
                <h2><?= esc(lang('Referrals.integration.addTitle')) ?></h2>
                <div class="hint"><?= esc(lang('Referrals.integration.addSub')) ?></div>
                <form method="post" action="/my/integration">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                    <label><?= esc(lang('Referrals.decisionLbl')) ?></label>
                    <select name="decision_type" required>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= esc($t) ?>"><?= esc($typeLabel($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label><?= esc(lang('Referrals.dateLbl')) ?></label>
                    <input type="date" name="decision_date" required>
                    <label><?= esc(lang('Referrals.integration.noteLbl')) ?></label>
                    <textarea name="note" rows="2"></textarea>
                    <button class="btn" type="submit"><?= esc(lang('Referrals.integration.save')) ?></button>
                </form>
            </div>

            <div class="panel">
                <h2><?= esc(lang('Referrals.integration.mineLbl')) ?></h2>
                <?php if ($declarations === []): ?>
                    <div class="muted"><?= esc(lang('Referrals.integration.empty')) ?></div>
                <?php else: ?>
                    <table>
                        <tr>
                            <th><?= esc(lang('Referrals.decisionLbl')) ?></th>
                            <th><?= esc(lang('Referrals.dateLbl')) ?></th>
                            <th><?= esc(lang('Referrals.integration.noteLbl')) ?></th>
                        </tr>
                        <?php foreach ($declarations as $d): ?>
                            <tr>
                                <td><?= esc($typeLabel((string) ($d['decision_type'] ?? ''))) ?>
                                    <?php $st = (string) ($d['status'] ?? ''); ?>
                                    <span class="badge <?= esc($st) ?>"><?= esc(lang('Referrals.integration.' . ($st === 'confirmed' ? 'confirmedBadge' : ($st === 'rejected' ? 'rejectedBadge' : 'pendingBadge')))) ?></span>
                                </td>
                                <td><?= esc((string) ($d['decision_date'] ?? '')) ?></td>
                                <td class="muted"><?= esc((string) ($d['note'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
