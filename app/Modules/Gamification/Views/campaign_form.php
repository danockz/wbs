<?= $this->extend('layouts/app') ?>

<?php
/**
 * Campaign config FORM (GET /gamification/campaigns/new and /{id}/edit) — the
 * browser face of CampaignsController::createCampaignForm / editCampaignForm, and
 * the write side of the group-campaigns list's "New campaign" / per-row "Edit"
 * links (previously campaigns could only be created/edited via the JSON API).
 *
 * Posts to POST /gamification/campaigns (create) or
 * POST /gamification/campaigns/{id}/update (edit) — both webcsrf-guarded. On
 * success the controller PRG-redirects to the campaign detail with a flash; on
 * failure it re-renders here with $error + the submitted values.
 *
 * IDENTITY fields (group_id, code, award_mode) are immutable after creation, so
 * on EDIT they render read-only (hidden inputs carry the values). metric,
 * award_mode and team_mode are fixed-vocab <select> pickers; the owning group is
 * a group picker. Only DRAFT campaigns are editable — the controller enforces
 * that; this form is the capture surface. Progressive-enhancement only: no
 * inline JS, CSP-safe.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.campaignForm.*') with English fallback.
 *
 * @var string                    $csrf
 * @var string                    $mode      'create' | 'edit'
 * @var array<string,mixed>       $campaign  current / submitted values
 * @var list<array<string,mixed>> $groups    owning-group picker options
 * @var list<string>              $metrics   metric vocabulary
 * @var list<string>              $modes     award-mode vocabulary
 * @var list<string>              $teamModes team-mode vocabulary
 * @var string                    $error
 */
$csrf      = $csrf ?? '';
$mode      = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$campaign  = is_array($campaign ?? null) ? $campaign : [];
$groups    = is_array($groups ?? null) ? $groups : [];
$metrics   = is_array($metrics ?? null) ? $metrics : ['points', 'amount', 'volume', 'count'];
$modes     = is_array($modes ?? null) ? $modes : ['single', 'repeatable', 'tiered'];
$teamModes = is_array($teamModes ?? null) ? $teamModes : ['subtree', 'adhoc'];
$error     = (string) ($error ?? '');

$isEdit = $mode === 'edit';
$id     = (string) ($campaign['id'] ?? '');
$cf     = static fn (string $k): string => 'Gamification.campaignForm.' . $k;
$action = $isEdit ? '/gamification/campaigns/' . rawurlencode($id) . '/update' : '/gamification/campaigns';

$ov = static function (string $k, string $default = '') use ($campaign): string {
    $v = $campaign[$k] ?? $default;
    if ($v === null) {
        $v = '';
    }

    return htmlspecialchars((string) $v, ENT_QUOTES);
};
// datetime-local wants "YYYY-MM-DDTHH:MM"; stored value is "YYYY-MM-DD HH:MM:SS".
$dtLocal = static function (string $k) use ($campaign): string {
    $v = (string) ($campaign[$k] ?? '');
    if ($v === '') {
        return '';
    }
    $v = str_replace(' ', 'T', $v);

    return htmlspecialchars(substr($v, 0, 16), ENT_QUOTES);
};

$vocab = static function (string $group, string $value) use ($cf): string {
    if ($value === '') {
        return '';
    }
    $s = lang(($cf)($group . '.' . $value));

    return str_contains($s, 'Gamification.') ? ucfirst(str_replace('_', ' ', $value)) : $s;
};

$curMetric   = (string) ($campaign['metric'] ?? 'points');
$curMode     = (string) ($campaign['award_mode'] ?? 'single');
$curTeamMode = (string) ($campaign['team_mode'] ?? 'subtree');
$curGroup    = (string) ($campaign['group_id'] ?? '');
$teamOn      = ! empty($campaign['team_challenge']);
?>

<?= $this->section('content') ?>
    

    <p><a href="<?= $isEdit && $curGroup !== '' ? '/gamification/groups/' . esc(rawurlencode($curGroup), 'attr') . '/campaigns' : '/gamification/campaigns/' . esc(rawurlencode($id), 'attr') ?>" style="color:#fcd34d">&larr; <?= esc(lang(($cf)('back'))) ?></a></p>
    <h1><?= esc(lang($isEdit ? ($cf)('headingEdit') : ($cf)('headingNew'))) ?></h1>
    <div class="sub"><?= esc(lang(($cf)('sub'))) ?></div>

    <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

    <form class="cf-form" method="post" action="<?= esc($action, 'attr') ?>">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

        <div class="cf-sec"><?= esc(lang(($cf)('secIdentity'))) ?></div>
        <div class="cf-grid">
            <div class="cf-field">
                <label for="group_id"><?= esc(lang(($cf)('groupLabel'))) ?>
                    <?php if ($isEdit): ?><span class="hint"><?= esc(lang(($cf)('lockedHint'))) ?></span><?php endif; ?></label>
                <?php if ($isEdit): ?>
                    <input type="text" value="<?= $ov('group_id') ?>" readonly>
                    <input type="hidden" name="group_id" value="<?= $ov('group_id') ?>">
                <?php else: ?>
                    <select id="group_id" name="group_id" required>
                        <option value=""><?= esc(lang(($cf)('groupNone'))) ?></option>
                        <?php foreach ($groups as $g): ?>
                            <?php $gid = (string) ($g['id'] ?? ''); ?>
                            <option value="<?= esc($gid, 'attr') ?>"<?= $curGroup === $gid ? ' selected' : '' ?>>
                                <?= esc((string) ($g['name'] ?? $gid)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="cf-field">
                <label for="code"><?= esc(lang(($cf)('codeLabel'))) ?>
                    <?php if ($isEdit): ?><span class="hint"><?= esc(lang(($cf)('lockedHint'))) ?></span><?php endif; ?></label>
                <?php if ($isEdit): ?>
                    <input type="text" value="<?= $ov('code') ?>" readonly>
                    <input type="hidden" name="code" value="<?= $ov('code') ?>">
                <?php else: ?>
                    <input type="text" id="code" name="code" required maxlength="80" placeholder="<?= esc(lang(($cf)('codePh')), 'attr') ?>" value="<?= $ov('code') ?>">
                <?php endif; ?>
            </div>
        </div>

        <div class="cf-field full">
            <label for="name"><?= esc(lang(($cf)('nameLabel'))) ?></label>
            <input type="text" id="name" name="name" required maxlength="200" value="<?= $ov('name') ?>">
        </div>
        <div class="cf-field full">
            <label for="description"><?= esc(lang(($cf)('descriptionLabel'))) ?></label>
            <textarea id="description" name="description"><?= $ov('description') ?></textarea>
        </div>
        <div class="cf-grid">
            <div class="cf-field">
                <label for="category"><?= esc(lang(($cf)('categoryLabel'))) ?></label>
                <input type="text" id="category" name="category" value="<?= $ov('category') ?>">
            </div>
            <div class="cf-field cf-check" style="margin-top:26px">
                <input type="checkbox" id="include_descendants" name="include_descendants" value="1"<?= ! empty($campaign['include_descendants']) ? ' checked' : '' ?>>
                <label for="include_descendants"><?= esc(lang(($cf)('includeDescendantsLabel'))) ?></label>
            </div>
        </div>

        <div class="cf-sec"><?= esc(lang(($cf)('secTarget'))) ?></div>
        <div class="cf-grid">
            <div class="cf-field">
                <label for="metric"><?= esc(lang(($cf)('metricLabel'))) ?></label>
                <select id="metric" name="metric">
                    <?php foreach ($metrics as $m): ?>
                        <option value="<?= esc($m, 'attr') ?>"<?= $curMetric === $m ? ' selected' : '' ?>><?= esc($vocab('metric', $m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cf-field">
                <label for="award_mode"><?= esc(lang(($cf)('awardModeLabel'))) ?>
                    <?php if ($isEdit): ?><span class="hint"><?= esc(lang(($cf)('lockedHint'))) ?></span><?php endif; ?></label>
                <?php if ($isEdit): ?>
                    <input type="text" value="<?= esc($vocab('mode', $curMode), 'attr') ?>" readonly>
                    <input type="hidden" name="award_mode" value="<?= esc($curMode, 'attr') ?>">
                <?php else: ?>
                    <select id="award_mode" name="award_mode">
                        <?php foreach ($modes as $m): ?>
                            <option value="<?= esc($m, 'attr') ?>"<?= $curMode === $m ? ' selected' : '' ?>><?= esc($vocab('mode', $m)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
        </div>
        <div class="cf-grid">
            <div class="cf-field">
                <label for="target_value"><?= esc(lang(($cf)('targetLabel'))) ?>
                    <span class="hint"><?= esc(lang(($cf)('targetHint'))) ?></span></label>
                <input type="number" id="target_value" name="target_value" min="0" inputmode="numeric" value="<?= $ov('target_value') ?>">
            </div>
            <div class="cf-field">
                <label for="award_points"><?= esc(lang(($cf)('awardPointsLabel'))) ?></label>
                <input type="number" id="award_points" name="award_points" min="0" inputmode="numeric" value="<?= $ov('award_points') ?>">
            </div>
        </div>
        <div class="cf-grid">
            <div class="cf-field">
                <label for="badge_code"><?= esc(lang(($cf)('badgeLabel'))) ?></label>
                <input type="text" id="badge_code" name="badge_code" value="<?= $ov('badge_code') ?>">
            </div>
            <div class="cf-field">
                <label for="recognize_top_n"><?= esc(lang(($cf)('recognizeTopNLabel'))) ?></label>
                <input type="number" id="recognize_top_n" name="recognize_top_n" min="0" inputmode="numeric" value="<?= $ov('recognize_top_n') ?>">
            </div>
        </div>

        <div class="cf-sec"><?= esc(lang(($cf)('secWindow'))) ?></div>
        <div class="cf-grid">
            <div class="cf-field">
                <label for="starts_at"><?= esc(lang(($cf)('startsAtLabel'))) ?></label>
                <input type="datetime-local" id="starts_at" name="starts_at" value="<?= $dtLocal('starts_at') ?>"<?= $isEdit ? '' : ' required' ?>>
            </div>
            <div class="cf-field">
                <label for="ends_at"><?= esc(lang(($cf)('endsAtLabel'))) ?></label>
                <input type="datetime-local" id="ends_at" name="ends_at" value="<?= $dtLocal('ends_at') ?>"<?= $isEdit ? '' : ' required' ?>>
            </div>
        </div>

        <div class="cf-sec"><?= esc(lang(($cf)('secTeam'))) ?></div>
        <div class="cf-grid">
            <div class="cf-field cf-check">
                <input type="checkbox" id="team_challenge" name="team_challenge" value="1"<?= $teamOn ? ' checked' : '' ?>>
                <label for="team_challenge"><?= esc(lang(($cf)('teamChallengeLabel'))) ?></label>
            </div>
            <div class="cf-field">
                <label for="team_mode"><?= esc(lang(($cf)('teamModeLabel'))) ?>
                    <span class="hint"><?= esc(lang(($cf)('teamModeHint'))) ?></span></label>
                <select id="team_mode" name="team_mode">
                    <?php foreach ($teamModes as $tm): ?>
                        <option value="<?= esc($tm, 'attr') ?>"<?= $curTeamMode === $tm ? ' selected' : '' ?>><?= esc($vocab('teamMode', $tm)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="cf-grid">
            <div class="cf-field">
                <label for="team_target_value"><?= esc(lang(($cf)('teamTargetLabel'))) ?>
                    <span class="hint"><?= esc(lang(($cf)('teamTargetHint'))) ?></span></label>
                <input type="number" id="team_target_value" name="team_target_value" min="0" inputmode="numeric" value="<?= $ov('team_target_value') ?>">
            </div>
            <div class="cf-field">
                <label for="team_award_points"><?= esc(lang(($cf)('teamAwardPointsLabel'))) ?></label>
                <input type="number" id="team_award_points" name="team_award_points" min="0" inputmode="numeric" value="<?= $ov('team_award_points') ?>">
            </div>
        </div>
        <div class="cf-grid">
            <div class="cf-field">
                <label for="team_badge_code"><?= esc(lang(($cf)('teamBadgeLabel'))) ?></label>
                <input type="text" id="team_badge_code" name="team_badge_code" value="<?= $ov('team_badge_code') ?>">
            </div>
            <div class="cf-field">
                <label for="recognize_top_teams"><?= esc(lang(($cf)('recognizeTopTeamsLabel'))) ?></label>
                <input type="number" id="recognize_top_teams" name="recognize_top_teams" min="0" inputmode="numeric" value="<?= $ov('recognize_top_teams') ?>">
            </div>
        </div>

        <div class="cf-actions">
            <button type="submit" class="cf-btn primary"><?= esc(lang($isEdit ? ($cf)('saveEdit') : ($cf)('saveNew'))) ?></button>
            <a class="cf-btn ghost" href="<?= $isEdit ? '/gamification/campaigns/' . esc(rawurlencode($id), 'attr') : '/gamification/campaigns/' . esc(rawurlencode($curGroup), 'attr') ?>"><?= esc(lang(($cf)('cancel'))) ?></a>
        </div>
    </form>
<?= $this->endSection() ?>
