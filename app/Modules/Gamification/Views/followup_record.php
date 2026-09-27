<?php
/**
 * Record-a-follow-up capture form (GET /gamification/follow-ups/new) — the
 * browser face of FollowUpsController::recordFollowUpForm, and the write side of
 * the follow-ups work queue (previously a follow-up could only be recorded via
 * the JSON API POST /gamification/follow-ups).
 *
 * Posts to POST /gamification/follow-ups (webcsrf-guarded). On success the
 * controller redirects (PRG) to the new record's detail page; on failure it
 * re-renders this form with $error + the submitted values ($old).
 *
 * ENTITY-REFERENCE fields are rendered as PICKERS rather than free-text ids:
 *   - subject_user_id -> a <select> of members drawn from the FOLLOWER'S OWN
 *     SCOPE (their group(s), then descendants), resolved server-side. When the
 *     scope roster is empty (e.g. no session actor) the picker degrades to a
 *     bounded free-text id input so the form still works.
 *   - type_code / method_code -> <select> of the active catalogs (value=code,
 *     label=name); type options group under their phase.
 *   - status / spiritual_health -> fixed vocabulary <select>s.
 *
 * SPECIALLY CLASSIFIED pastoral content — gated by the same follow-up
 * authorization as the JSON endpoint. Extends layouts/app (locale-aware
 * <html lang dir>). UI copy via lang('Gamification.admin.followupRecord.*') with
 * English fallback.
 *
 * @var string                        $csrf
 * @var list<array<string,mixed>>     $types    active follow-up types
 * @var list<array<string,mixed>>     $methods  active follow-up methods
 * @var list<array<string,mixed>>     $subjects scope roster [{id,display_name}]
 * @var list<string>                  $statuses record status vocabulary
 * @var list<string>                  $health   spiritual-health vocabulary
 * @var array<string,mixed>           $old      submitted values (on re-render)
 * @var string                        $error
 * @var string                        $title
 */
$csrf     = $csrf ?? '';
$types    = is_array($types ?? null) ? $types : [];
$methods  = is_array($methods ?? null) ? $methods : [];
$subjects = is_array($subjects ?? null) ? $subjects : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
$health   = is_array($health ?? null) ? $health : [];
$old      = is_array($old ?? null) ? $old : [];
$error    = $error ?? '';
$title    = $title ?? lang('Gamification.admin.followupRecord.metaTitle');

$fr = static fn (string $key): string => 'Gamification.admin.followupRecord.' . $key;

$ov = static function (string $k, string $default = '') use ($old): string {
    $v = $old[$k] ?? $default;
    if ($v === null) {
        $v = '';
    }

    return htmlspecialchars((string) $v, ENT_QUOTES);
};

// Translate a fixed-vocabulary token, falling back to a humanised token.
$vocab = static function (string $group, string $value) use ($fr): string {
    if ($value === '') {
        return '';
    }
    $s = lang(($fr)($group . '.' . $value));
    if (is_string($s) && ! str_contains($s, 'Gamification.')) {
        return $s;
    }

    return ucfirst(str_replace('_', ' ', $value));
};

$curType    = (string) ($old['type_code'] ?? '');
$curMethod  = (string) ($old['method_code'] ?? '');
$curSubject = (string) ($old['subject_user_id'] ?? '');
$curStatus  = (string) ($old['status'] ?? 'completed');
$curHealth  = (string) ($old['spiritual_health'] ?? '');

// Group the type options under their phase for a scannable picker.
$typesByPhase = [];
foreach ($types as $t) {
    $ph = (string) ($t['phase'] ?? 'general');
    $typesByPhase[$ph][] = $t;
}
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <p><a href="/gamification/follow-ups/due">&larr; <?= esc(lang(($fr)('backToDue'))) ?></a></p>
    <h1><?= esc(lang(($fr)('heading'))) ?></h1>
    <div class="sub"><?= esc(lang(($fr)('sub'))) ?></div>

    <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

    <form class="fr-form" method="post" action="/gamification/follow-ups">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

        <div class="fr-grid">
            <div class="fr-field">
                <label for="subject_user_id"><?= esc(lang(($fr)('subjectLabel'))) ?>
                    <span class="hint"><?= esc(lang(($fr)('subjectHint'))) ?></span></label>
                <?php if ($subjects !== []): ?>
                    <select id="subject_user_id" name="subject_user_id" required>
                        <option value=""><?= esc(lang(($fr)('subjectNone'))) ?></option>
                        <?php foreach ($subjects as $s): ?>
                            <?php $sid = (string) ($s['id'] ?? ''); ?>
                            <option value="<?= esc($sid, 'attr') ?>"<?= $curSubject === $sid ? ' selected' : '' ?>><?= esc((string) ($s['display_name'] ?? $sid)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" id="subject_user_id" name="subject_user_id" required maxlength="64"
                           placeholder="<?= esc(lang(($fr)('subjectPh')), 'attr') ?>" value="<?= $ov('subject_user_id') ?>">
                <?php endif; ?>
            </div>
            <div class="fr-field">
                <label for="performed_at"><?= esc(lang(($fr)('performedAtLabel'))) ?>
                    <span class="hint"><?= esc(lang(($fr)('performedAtHint'))) ?></span></label>
                <input type="datetime-local" id="performed_at" name="performed_at" value="<?= $ov('performed_at') ?>">
            </div>
        </div>

        <div class="fr-grid">
            <div class="fr-field">
                <label for="type_code"><?= esc(lang(($fr)('typeLabel'))) ?></label>
                <select id="type_code" name="type_code" required>
                    <option value=""><?= esc(lang(($fr)('typeNone'))) ?></option>
                    <?php foreach ($typesByPhase as $phase => $group): ?>
                        <optgroup label="<?= esc($vocab('phase', (string) $phase), 'attr') ?>">
                            <?php foreach ($group as $t): ?>
                                <?php $tc = (string) ($t['code'] ?? ''); ?>
                                <option value="<?= esc($tc, 'attr') ?>"<?= $curType === $tc ? ' selected' : '' ?>><?= esc((string) ($t['name'] ?? $tc)) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fr-field">
                <label for="method_code"><?= esc(lang(($fr)('methodLabel'))) ?></label>
                <select id="method_code" name="method_code" required>
                    <option value=""><?= esc(lang(($fr)('methodNone'))) ?></option>
                    <?php foreach ($methods as $m): ?>
                        <?php $mc = (string) ($m['code'] ?? ''); ?>
                        <option value="<?= esc($mc, 'attr') ?>"<?= $curMethod === $mc ? ' selected' : '' ?>><?= esc((string) ($m['name'] ?? $mc)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="fr-grid">
            <div class="fr-field">
                <label for="status"><?= esc(lang(($fr)('statusLabel'))) ?></label>
                <select id="status" name="status">
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?= esc($st, 'attr') ?>"<?= $curStatus === $st ? ' selected' : '' ?>><?= esc($vocab('status', $st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fr-field">
                <label for="spiritual_health"><?= esc(lang(($fr)('spiritualHealthLabel'))) ?>
                    <span class="hint"><?= esc(lang(($fr)('spiritualHealthHint'))) ?></span></label>
                <select id="spiritual_health" name="spiritual_health">
                    <option value=""><?= esc(lang(($fr)('spiritualHealthNone'))) ?></option>
                    <?php foreach ($health as $h): ?>
                        <option value="<?= esc($h, 'attr') ?>"<?= $curHealth === $h ? ' selected' : '' ?>><?= esc($vocab('health', $h)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="fr-field full">
            <label for="summary"><?= esc(lang(($fr)('summaryLabel'))) ?></label>
            <textarea id="summary" name="summary" maxlength="1000"><?= $ov('summary') ?></textarea>
        </div>

        <div class="fr-field full">
            <label for="outcome"><?= esc(lang(($fr)('outcomeLabel'))) ?>
                <span class="hint"><?= esc(lang(($fr)('outcomeHint'))) ?></span></label>
            <textarea id="outcome" name="outcome" maxlength="1000"><?= $ov('outcome') ?></textarea>
        </div>

        <div class="fr-field full">
            <label for="needs"><?= esc(lang(($fr)('needsLabel'))) ?>
                <span class="hint"><?= esc(lang(($fr)('needsHint'))) ?></span></label>
            <input type="text" id="needs" name="needs" maxlength="500"
                   placeholder="<?= esc(lang(($fr)('needsPh')), 'attr') ?>" value="<?= $ov('needs') ?>">
        </div>

        <div class="fr-grid">
            <div class="fr-field">
                <label for="next_follow_up_at"><?= esc(lang(($fr)('nextFollowUpLabel'))) ?>
                    <span class="hint"><?= esc(lang(($fr)('nextFollowUpHint'))) ?></span></label>
                <input type="datetime-local" id="next_follow_up_at" name="next_follow_up_at" value="<?= $ov('next_follow_up_at') ?>">
            </div>
            <div class="fr-field">
                <label for="next_notes"><?= esc(lang(($fr)('nextNotesLabel'))) ?></label>
                <input type="text" id="next_notes" name="next_notes" maxlength="500" value="<?= $ov('next_notes') ?>">
            </div>
        </div>

        <div class="fr-actions">
            <button type="submit" class="fr-btn primary"><?= esc(lang(($fr)('save'))) ?></button>
            <a class="fr-btn ghost" href="/gamification/follow-ups/due"><?= esc(lang(($fr)('cancel'))) ?></a>
        </div>
    </form>
<?= $this->endSection() ?>
