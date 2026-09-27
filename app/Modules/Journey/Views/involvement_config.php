<?php
/**
 * INVOLVEMENT-TRIAGE config console (journey/involvement/config) — the browser
 * face of JourneyController::involvementConfig, which otherwise only spoke JSON.
 *
 * Lets an admin/leader switch involvement-based triage ON/OFF for a context
 * (org-wide primary, or a group override) and tune the measurement window,
 * activity target, band thresholds and quantum-of-work weights. Previously these
 * `journey.involvement.*` capabilities could only be set by hand-writing raw keys
 * through the generic group-config form — this is the dedicated, self-explaining
 * surface the involvement TODO asked for.
 *
 * All fields POST to the webcsrf-guarded /journey/involvement/config route; the
 * controller CLAMPS every value to the same bounds the reader enforces and PRGs
 * back here with a flash. The group context is carried as a hidden field so an
 * org-wide vs group-scoped save lands on the right node.
 *
 * SELF-CONTAINED page: renders its own <html>, includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy via
 * lang('Journey.admin.involvementConfig.*') with English fallback. No JavaScript
 * — plain inputs + a single submit; fully usable with JS disabled and CSP-safe.
 *
 * @var ?string             $group_id
 * @var bool                $enabled
 * @var int                 $window_days
 * @var int                 $activity_target
 * @var array<string,int>   $thresholds
 * @var array<string,int>   $weights
 * @var array<string,mixed> $defaults
 * @var array{window_min:int,window_max:int,target_max:int} $bounds
 * @var list<string>        $threshold_keys
 * @var list<string>        $weight_keys
 * @var bool                $writable
 * @var string              $csrf
 */
$group_id        = $group_id ?? null;
$enabled         = (bool) ($enabled ?? false);
$window_days     = (int) ($window_days ?? 90);
$activity_target = (int) ($activity_target ?? 4);
$thresholds      = is_array($thresholds ?? null) ? $thresholds : [];
$weights         = is_array($weights ?? null) ? $weights : [];
$defaults        = is_array($defaults ?? null) ? $defaults : ['thresholds' => [], 'weights' => []];
$bounds          = is_array($bounds ?? null) ? $bounds : ['window_min' => 30, 'window_max' => 365, 'target_max' => 100];
$threshold_keys  = is_array($threshold_keys ?? null) ? $threshold_keys : array_keys($thresholds);
$weight_keys     = is_array($weight_keys ?? null) ? $weight_keys : array_keys($weights);
$writable        = (bool) ($writable ?? true);
$csrf            = $csrf ?? '';

include __DIR__ . '/_locale.php';

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$ic  = static fn (string $k): string => 'Journey.admin.involvementConfig.' . $k;
$lbl = static function (string $k) use ($ic): string {
    $v = lang(($ic)($k));
    return $v === ($ic)($k) ? ucfirst(str_replace(['th_', 'wt_', '_'], ['', '', ' '], $k)) : $v;
};
$ctx = $group_id === null ? '' : (string) $group_id;
?>

<?php ob_start(); ?>
<?= esc(lang(($ic)('metaTitle'))) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 760px; margin: 0 auto; padding: 5vh 20px 60px; }


        .note { color:#64748b; font-size:.82rem; margin:6px 0 18px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:20px 22px; margin-bottom:18px; }


        .sec { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin:4px 0 14px; }


        .field { display:flex; flex-direction:column; gap:6px; margin-bottom:6px; }


        input[type=number] { font:inherit; color:#e2e8f0; background:#0b1424; border:1px solid #334155; border-radius:9px; padding:10px 12px; width:100%; }


        input[type=number]:focus { outline:none; border-color:#a78bfa; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <p><a href="/journey/pipeline<?= $ctx !== '' ? '?group_id=' . esc(rawurlencode($ctx), 'url') : '' ?>">&larr; <?= esc(lang(($ic)('backToPipeline'))) ?></a></p>

        <span class="scope">
            <?= $group_id === null || $group_id === ''
                ? esc(lang(($ic)('contextOrg')))
                : esc(str_replace('{0}', (string) $group_id, lang(($ic)('contextGroup')))) ?>
        </span>
        <h1><?= esc(lang(($ic)('heading'))) ?></h1>
        <div class="sub"><?= esc(lang(($ic)('sub'))) ?></div>
        <div class="note"><?= esc(lang(($ic)('inheritNote'))) ?></div>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>
        <?php if (! $writable): ?><div class="flash err"><?= esc(lang(($ic)('notWritable'))) ?></div><?php endif; ?>

        <form method="post" action="/journey/involvement/config">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <?php if ($ctx !== ''): ?><input type="hidden" name="group_id" value="<?= esc($ctx, 'attr') ?>"><?php endif; ?>

            <div class="card">
                <div class="toggle">
                    <input type="checkbox" id="enabled" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
                    <label class="lbltext" for="enabled"><?= esc(lang(($ic)('enableLabel'))) ?></label>
                </div>
                <div class="hint" style="margin-top:8px"><?= esc(lang(($ic)('enableHint'))) ?></div>
            </div>

            <div class="card">
                <div class="sec"><?= esc(lang(($ic)('windowSection'))) ?></div>
                <div class="grid">
                    <div class="field">
                        <label for="window_days"><?= esc(lang(($ic)('windowLabel'))) ?></label>
                        <input type="number" id="window_days" name="window_days" min="<?= esc((string) $bounds['window_min'], 'attr') ?>" max="<?= esc((string) $bounds['window_max'], 'attr') ?>" step="1" value="<?= esc((string) $window_days, 'attr') ?>">
                        <span class="hint"><?= esc(str_replace(['{0}', '{1}'], [(string) $bounds['window_min'], (string) $bounds['window_max']], lang(($ic)('windowHint')))) ?></span>
                    </div>
                    <div class="field">
                        <label for="activity_target"><?= esc(lang(($ic)('targetLabel'))) ?></label>
                        <input type="number" id="activity_target" name="activity_target" min="1" max="<?= esc((string) $bounds['target_max'], 'attr') ?>" step="1" value="<?= esc((string) $activity_target, 'attr') ?>">
                        <span class="hint"><?= esc(str_replace('{0}', (string) $bounds['target_max'], lang(($ic)('targetHint')))) ?></span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="sec"><?= esc(lang(($ic)('thresholdsSection'))) ?></div>
                <div class="grid">
                    <?php foreach ($threshold_keys as $k): ?>
                        <?php $def = (string) (($defaults['thresholds'][$k] ?? '')); ?>
                        <div class="field">
                            <label for="th_<?= esc($k, 'attr') ?>"><?= esc($lbl('th_' . $k)) ?></label>
                            <input type="number" id="th_<?= esc($k, 'attr') ?>" name="th_<?= esc($k, 'attr') ?>" min="0" step="1" value="<?= esc((string) ($thresholds[$k] ?? $def), 'attr') ?>">
                            <span class="hint"><?= esc(str_replace('{0}', $def, lang(($ic)('defaultTag')))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="sec"><?= esc(lang(($ic)('weightsSection'))) ?></div>
                <div class="hint" style="margin-bottom:12px"><?= esc(lang(($ic)('weightsHint'))) ?></div>
                <div class="grid">
                    <?php foreach ($weight_keys as $k): ?>
                        <?php $def = (string) (($defaults['weights'][$k] ?? '')); ?>
                        <div class="field">
                            <label for="wt_<?= esc($k, 'attr') ?>"><?= esc($lbl('wt_' . $k)) ?></label>
                            <input type="number" id="wt_<?= esc($k, 'attr') ?>" name="wt_<?= esc($k, 'attr') ?>" min="0" <?= $k === 'downline_share_pct' ? 'max="100"' : '' ?> step="1" value="<?= esc((string) ($weights[$k] ?? $def), 'attr') ?>">
                            <span class="hint"><?= esc(str_replace('{0}', $def, lang(($ic)('defaultTag')))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn"<?= $writable ? '' : ' disabled' ?>><?= esc(lang(($ic)('save'))) ?></button>
            </div>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
