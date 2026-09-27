<?php
/**
 * Achievement definitions — admin catalog + in-page CRUD
 * (GET /gamification/achievement-definitions) — the browser face of
 * AwardsController::listAchievementDefinitions. Previously the achievement
 * definitions could only be created/edited/disabled via the JSON API; the list
 * had no admin view at all. This mirrors the ranks/badges/streaks admin catalogs.
 *
 * define() is an UPSERT keyed on (code, group): the "New achievement" form and
 * each row's "Edit" form both POST to /gamification/achievements — creating when
 * the code is new, updating in place otherwise. Achievements are DISABLED (status
 * flip) rather than deleted, so existing unlocks are untouched. All writes are
 * webcsrf-guarded and PRG back here with a localized flash.
 *
 * ENTITY-REFERENCE / FIXED-VOCAB fields are PICKERS, not free text: trigger_type
 * (the trigger vocabulary) and phase (win/build/send/general) render as <select>.
 * trigger_config is a small JSON textarea (e.g. {"threshold":100}); a hint shows
 * the expected shape. Progressive-enhancement: panels are plain <details>, fully
 * usable with NO JavaScript (CSP-safe: no inline on* handlers, no <script>).
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.achievements.*') with English fallback.
 *
 * @var list<array<string,mixed>> $achievements achievement_definitions rows
 * @var list<string>              $triggers      trigger-type vocabulary
 * @var list<string>              $phases        phase vocabulary
 * @var string                    $csrf          webcsrf double-submit token
 * @var string                    $title
 */
$achievements = is_array($achievements ?? null) ? $achievements : [];
$triggers     = is_array($triggers ?? null) ? $triggers : ['points', 'count', 'streak', 'combo', 'first_time', 'cumulative_points', 'rank_reached', 'custom'];
$phases       = is_array($phases ?? null) ? $phases : ['general', 'win', 'build', 'send'];
$csrf         = $csrf ?? '';
$count        = count($achievements);
$title        = $title ?? lang('Gamification.admin.achievements.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$af = static fn (string $key): string => 'Gamification.admin.achievements.form.' . $key;

// Localize a fixed-vocabulary token with a humanized fallback.
$vocab = static function (string $group, string $value) use ($af): string {
    if ($value === '') {
        return '';
    }
    $s = lang(($af)($group . '.' . $value));

    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : ucfirst(str_replace('_', ' ', $value));
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <div>
        <h1><?= esc(lang('Gamification.admin.achievements.title')) ?></h1>
        <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.achievements.countOne') : lang('Gamification.admin.achievements.count'))) ?></div>
    </div>

    <?php if ($flashOk !== ''): ?><div class="cf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php
    $defineForm = static function (array $a, bool $isNew) use ($csrf, $af, $triggers, $phases, $vocab): void {
        $code    = (string) ($a['code'] ?? '');
        $curTrig = (string) ($a['trigger_type'] ?? '');
        $curPh   = (string) ($a['phase'] ?? 'general');
        // trigger_config is stored as a JSON string; show it verbatim for editing.
        $cfg = $a['trigger_config'] ?? '';
        if (is_array($cfg)) {
            $cfg = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        ?>
        <form method="post" action="/gamification/achievements" class="cf-form">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="fld">
                <label><?= esc(lang(($af)('codeLabel'))) ?></label>
                <?php if ($isNew): ?>
                    <input name="code" required placeholder="<?= esc(lang(($af)('codePh')), 'attr') ?>">
                <?php else: ?>
                    <input value="<?= esc($code, 'attr') ?>" readonly title="<?= esc(lang(($af)('codeLocked')), 'attr') ?>">
                    <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                <?php endif; ?>
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('nameLabel'))) ?></label>
                <input name="name" required value="<?= esc((string) ($a['name'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('triggerLabel'))) ?></label>
                <select name="trigger_type" required>
                    <option value=""><?= esc(lang(($af)('triggerNone'))) ?></option>
                    <?php foreach ($triggers as $t): ?>
                        <option value="<?= esc($t, 'attr') ?>"<?= $curTrig === $t ? ' selected' : '' ?>><?= esc($vocab('trigger', $t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('phaseLabel'))) ?></label>
                <select name="phase">
                    <?php foreach ($phases as $p): ?>
                        <option value="<?= esc($p, 'attr') ?>"<?= $curPh === $p ? ' selected' : '' ?>><?= esc($vocab('phase', $p)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fld wide">
                <label><?= esc(lang(($af)('triggerConfigLabel'))) ?> <span class="hint"><?= esc(lang(($af)('triggerConfigHint'))) ?></span></label>
                <textarea name="trigger_config" placeholder='{"threshold": 100}'><?= esc((string) $cfg) ?></textarea>
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('xpLabel'))) ?></label>
                <input name="xp" type="number" min="0" value="<?= esc((string) (int) ($a['xp'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('bonusPointsLabel'))) ?></label>
                <input name="bonus_points" type="number" min="0" value="<?= esc((string) (int) ($a['bonus_points'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('bonusRuleLabel'))) ?> <span class="hint"><?= esc(lang(($af)('bonusRuleHint'))) ?></span></label>
                <input name="bonus_rule_code" value="<?= esc((string) ($a['bonus_rule_code'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('categoryLabel'))) ?></label>
                <input name="category" value="<?= esc((string) ($a['category'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('iconLabel'))) ?></label>
                <input name="icon" value="<?= esc((string) ($a['icon'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang(($af)('iconPh')), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('colorLabel'))) ?></label>
                <input name="color" value="<?= esc((string) ($a['color'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang(($af)('colorPh')), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang(($af)('sortLabel'))) ?></label>
                <input name="sort_order" type="number" value="<?= esc((string) (int) ($a['sort_order'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld wide">
                <label><?= esc(lang(($af)('descriptionLabel'))) ?></label>
                <input name="description" value="<?= esc((string) ($a['description'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld cf-check">
                <input type="checkbox" id="secret_<?= esc($isNew ? 'new' : $code, 'attr') ?>" name="secret" value="1"<?= ! empty($a['secret']) ? ' checked' : '' ?>>
                <label for="secret_<?= esc($isNew ? 'new' : $code, 'attr') ?>"><?= esc(lang(($af)('secretLabel'))) ?></label>
            </div>
            <div class="cf-actions">
                <button type="submit" class="cf-btn"><?= esc($isNew ? lang(($af)('saveNew')) : lang(($af)('saveEdit'))) ?></button>
            </div>
        </form>
        <?php
    };
    ?>

    <details class="cf-panel">
        <summary>+ <?= esc(lang(($af)('newAchievement'))) ?></summary>
        <?php $defineForm([], true); ?>
    </details>

    <?php if ($achievements === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.achievements.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($achievements as $a): ?>
            <?php
            $acode    = (string) ($a['code'] ?? '');
            $ac       = rawurlencode($acode);
            $status   = (string) ($a['status'] ?? 'active');
            $isActive = $status !== 'inactive';
            ?>
            <div class="card<?= $isActive ? '' : ' ach-disabled' ?>">
                <div class="row">
                    <span class="author" style="<?= ! empty($a['color']) ? 'color:' . esc($a['color'], 'attr') . ';' : '' ?>">
                        <?php if (! empty($a['icon'])): ?><?= esc((string) $a['icon']) ?> <?php endif; ?>
                        <?= esc((string) ($a['name'] ?? ($acode !== '' ? $acode : '—'))) ?>
                    </span>
                    <span class="ach-badges">
                        <?php if (($a['trigger_type'] ?? '') !== ''): ?><span class="ach-tag"><?= esc($vocab('trigger', (string) $a['trigger_type'])) ?></span><?php endif; ?>
                        <?php if ((int) ($a['xp'] ?? 0) > 0): ?><span class="ach-tag"><?= esc(str_replace('{0}', (string) (int) $a['xp'], lang('Gamification.admin.achievements.xp'))) ?></span><?php endif; ?>
                        <?php if (! empty($a['secret'])): ?><span class="ach-tag"><?= esc(lang('Gamification.admin.achievements.secret')) ?></span><?php endif; ?>
                        <?php if (! $isActive): ?><span class="ach-tag"><?= esc(lang('Gamification.admin.achievements.disabled')) ?></span><?php endif; ?>
                    </span>
                </div>
                <?php if (! empty($a['description'])): ?><div class="counts"><?= esc((string) $a['description']) ?></div><?php endif; ?>
                <div class="meta">
                    <?= esc(lang('Gamification.admin.achievements.code')) ?>: <?= esc($acode !== '' ? $acode : '—') ?>
                    <?php if (! empty($a['bonus_points'])): ?> · <?= esc(str_replace('{0}', (string) (int) $a['bonus_points'], lang('Gamification.admin.achievements.bonus'))) ?><?php endif; ?>
                </div>
                <?php if ($acode !== ''): ?>
                <div class="cf-acts">
                    <a class="cf-act" href="/gamification/achievements/<?= esc($ac, 'attr') ?>"><?= esc(lang(($af)('view'))) ?></a>
                    <?php if ($isActive): ?>
                    <details class="cf-inline" style="border:0;margin:0">
                        <summary class="cf-act warn"><?= esc(lang(($af)('disable'))) ?></summary>
                        <form method="post" action="/gamification/achievements/<?= esc($ac, 'attr') ?>/disable" style="padding:8px 0">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <span class="hint" style="color:#94a3b8;font-size:.72rem"><?= esc(lang(($af)('disableConfirm'))) ?></span>
                            <button type="submit" class="cf-act warn"><?= esc(lang(($af)('disableYes'))) ?></button>
                        </form>
                    </details>
                    <?php endif; ?>
                </div>
                <details class="cf-inline">
                    <summary><?= esc(lang(($af)('edit'))) ?></summary>
                    <?php $defineForm($a, false); ?>
                </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
