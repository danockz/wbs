<?php
/**
 * Point rule create/edit form (GET /gamification/rules/new and
 * /gamification/rules/{code}/edit) — the browser face of
 * ConfigController::createRuleForm / editRuleForm, and the write side of the
 * rules list's "New rule" / "Edit" links (previously the list was read-only,
 * with the create/update endpoints reachable only via the JSON API).
 *
 * Posts to POST /gamification/rules (create) or POST /gamification/rules/{code}
 * (update), both webcsrf-guarded. Editing is IMMUTABLE: an update supersedes the
 * active version with a new one rather than mutating award history, so the code
 * is fixed on edit. On success the controller redirects (PRG) to the rule
 * detail; on failure it re-renders with $error + the submitted values.
 *
 * Extends layouts/app (locale-aware <html lang dir>). UI copy via
 * lang('Gamification.admin.rules.form.*') with English fallback.
 *
 * @var string               $csrf
 * @var string               $mode  'create' | 'edit'
 * @var array<string,mixed>  $rule  current/submitted values
 * @var string               $error
 * @var string               $title
 */
$csrf  = $csrf ?? '';
$mode  = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$rule  = is_array($rule ?? null) ? $rule : [];
$error = $error ?? '';

$isEdit = $mode === 'edit';
$code   = (string) ($rule['code'] ?? '');
$title  = $title ?? lang($isEdit ? 'Gamification.admin.rules.form.metaTitleEdit' : 'Gamification.admin.rules.form.metaTitleNew');

$rf = static fn (string $key): string => 'Gamification.admin.rules.form.' . $key;

$ov = static function (string $k, string $default = '') use ($rule): string {
    $v = $rule[$k] ?? $default;
    if ($v === null) {
        $v = '';
    }

    return htmlspecialchars((string) $v, ENT_QUOTES);
};

// Fixed vocabularies (mirror RuleService constants).
$phases     = ['general', 'win', 'build', 'send'];
$pointModes = ['fixed', 'variable', 'formula'];
$periods    = ['', 'day', 'week', 'month', 'season'];

$vocab = static function (string $group, string $value) use ($rf): string {
    if ($value === '') {
        return '';
    }
    $s = lang(($rf)($group . '.' . $value));

    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : ucfirst($value);
};

$curPhase  = (string) ($rule['phase'] ?? 'general');
$curMode   = (string) ($rule['point_mode'] ?? 'fixed');
$curPeriod = (string) ($rule['period'] ?? '');
$action    = $isEdit ? '/gamification/rules/' . rawurlencode($code) : '/gamification/rules';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <p><a href="/gamification/rules">&larr; <?= esc(lang(($rf)('backToList'))) ?></a></p>
    <h1><?= esc(lang($isEdit ? ($rf)('headingEdit') : ($rf)('headingNew'))) ?></h1>
    <div class="sub"><?= esc(lang($isEdit ? ($rf)('subEdit') : ($rf)('subNew'))) ?></div>

    <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>
    <?php if ($isEdit): ?><div class="note"><?= esc(lang(($rf)('immutableNote'))) ?></div><?php endif; ?>

    <form class="rf-form" method="post" action="<?= esc($action, 'attr') ?>">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

        <div class="rf-grid">
            <div class="rf-field">
                <label for="code"><?= esc(lang(($rf)('codeLabel'))) ?>
                    <?php if ($isEdit): ?><span class="hint"><?= esc(lang(($rf)('codeLocked'))) ?></span><?php endif; ?></label>
                <input type="text" id="code" name="code" required maxlength="80"
                       placeholder="<?= esc(lang(($rf)('codePh')), 'attr') ?>" value="<?= $ov('code') ?>"<?= $isEdit ? ' readonly' : '' ?>>
            </div>
            <div class="rf-field">
                <label for="event_type"><?= esc(lang(($rf)('eventTypeLabel'))) ?>
                    <span class="hint"><?= esc(lang(($rf)('eventTypeHint'))) ?></span></label>
                <input type="text" id="event_type" name="event_type" required maxlength="120"
                       placeholder="<?= esc(lang(($rf)('eventTypePh')), 'attr') ?>" value="<?= $ov('event_type') ?>">
            </div>
        </div>

        <div class="rf-grid">
            <div class="rf-field">
                <label for="points"><?= esc(lang(($rf)('pointsLabel'))) ?></label>
                <input type="number" id="points" name="points" required inputmode="numeric" value="<?= $ov('points', '0') ?>">
            </div>
            <div class="rf-field">
                <label for="phase"><?= esc(lang(($rf)('phaseLabel'))) ?>
                    <span class="hint"><?= esc(lang(($rf)('phaseHint'))) ?></span></label>
                <select id="phase" name="phase">
                    <?php foreach ($phases as $p): ?>
                        <option value="<?= esc($p, 'attr') ?>"<?= $curPhase === $p ? ' selected' : '' ?>><?= esc($vocab('phase', $p)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="rf-grid">
            <div class="rf-field">
                <label for="point_mode"><?= esc(lang(($rf)('pointModeLabel'))) ?></label>
                <select id="point_mode" name="point_mode">
                    <?php foreach ($pointModes as $m): ?>
                        <option value="<?= esc($m, 'attr') ?>"<?= $curMode === $m ? ' selected' : '' ?>><?= esc($vocab('pointMode', $m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rf-field">
                <label for="point_formula"><?= esc(lang(($rf)('formulaLabel'))) ?>
                    <span class="hint"><?= esc(lang(($rf)('formulaHint'))) ?></span></label>
                <input type="text" id="point_formula" name="point_formula" maxlength="255"
                       placeholder="<?= esc(lang(($rf)('formulaPh')), 'attr') ?>" value="<?= $ov('point_formula') ?>">
            </div>
        </div>

        <div class="rf-grid">
            <div class="rf-field">
                <label for="min_points"><?= esc(lang(($rf)('minPointsLabel'))) ?></label>
                <input type="number" id="min_points" name="min_points" inputmode="numeric" value="<?= $ov('min_points') ?>">
            </div>
            <div class="rf-field">
                <label for="max_points"><?= esc(lang(($rf)('maxPointsLabel'))) ?></label>
                <input type="number" id="max_points" name="max_points" inputmode="numeric" value="<?= $ov('max_points') ?>">
            </div>
        </div>

        <div class="rf-grid">
            <div class="rf-field">
                <label for="period"><?= esc(lang(($rf)('periodLabel'))) ?>
                    <span class="hint"><?= esc(lang(($rf)('periodHint'))) ?></span></label>
                <select id="period" name="period">
                    <?php foreach ($periods as $p): ?>
                        <option value="<?= esc($p, 'attr') ?>"<?= $curPeriod === $p ? ' selected' : '' ?>><?= $p === '' ? esc(lang(($rf)('periodNone'))) : esc($vocab('period', $p)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rf-field">
                <label for="per_period_cap"><?= esc(lang(($rf)('perPeriodCapLabel'))) ?>
                    <span class="hint"><?= esc(lang(($rf)('perPeriodCapHint'))) ?></span></label>
                <input type="number" id="per_period_cap" name="per_period_cap" inputmode="numeric" value="<?= $ov('per_period_cap') ?>">
            </div>
        </div>

        <div class="rf-grid">
            <div class="rf-field">
                <label for="cooldown_seconds"><?= esc(lang(($rf)('cooldownLabel'))) ?>
                    <span class="hint"><?= esc(lang(($rf)('cooldownHint'))) ?></span></label>
                <input type="number" id="cooldown_seconds" name="cooldown_seconds" inputmode="numeric" value="<?= $ov('cooldown_seconds') ?>">
            </div>
            <div class="rf-field rf-check">
                <input type="checkbox" id="requires_review" name="requires_review" value="1"<?= ! empty($rule['requires_review']) ? ' checked' : '' ?>>
                <label for="requires_review"><?= esc(lang(($rf)('requiresReviewLabel'))) ?></label>
            </div>
        </div>

        <div class="rf-field full">
            <label for="explanation"><?= esc(lang(($rf)('explanationLabel'))) ?></label>
            <textarea id="explanation" name="explanation" maxlength="255"><?= $ov('explanation') ?></textarea>
        </div>

        <div class="rf-actions">
            <button type="submit" class="rf-btn primary"><?= esc(lang($isEdit ? ($rf)('saveEdit') : ($rf)('saveNew'))) ?></button>
            <a class="rf-btn ghost" href="/gamification/rules"><?= esc(lang(($rf)('cancel'))) ?></a>
        </div>
    </form>
<?= $this->endSection() ?>
