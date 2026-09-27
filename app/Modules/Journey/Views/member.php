<?php
/**
 * Member JOURNEY DETAIL page (journey/members/{id}) — the browser face of
 * JourneyController::showForUser, which otherwise only spoke JSON. Shows one
 * person's journey in a context: the current stage + phase + status, an
 * append-only transition timeline, and the manual controls a leader uses to move
 * them — advance/set stage, pause/resume/archive, or OPEN a journey when none
 * exists yet. Every mutation POSTs to the webcsrf-guarded per-member routes and
 * is group-scope-checked in the controller (a leader only moves members in scope).
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Journey.*') with English fallback; the stage `phase` vocabulary is
 * localized with a raw-value fallback. The member id is redacted for privacy;
 * stage codes/names and timestamps are server data shown verbatim & escaped.
 * Progressive-enhancement: plain forms, fully usable with no JavaScript.
 *
 * @var string                    $user_id
 * @var ?string                   $group_id
 * @var array<string,mixed>|null  $journey member_journeys row (+history) or null
 * @var list<array<string,mixed>> $history member_journey_transitions rows (asc)
 * @var list<array<string,mixed>> $ladder  effective stage ladder for the context
 * @var string                    $csrf    webcsrf double-submit token
 */
$user_id  = (string) ($user_id ?? '');
$group_id = $group_id ?? null;
$journey  = is_array($journey ?? null) ? $journey : null;
$history  = is_array($history ?? null) ? $history : [];
$ladder   = is_array($ladder ?? null) ? $ladder : [];
$disciplers = is_array($disciplers ?? null) ? $disciplers : [];
$csrf     = $csrf ?? '';

include __DIR__ . '/_locale.php';

if (! function_exists('redact_id')) {
    require_once dirname(__DIR__, 3) . '/Helpers/redactor_helper.php';
}

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$phaseColor = static fn (string $p): string => match ($p) {
    'win'   => '#22d3ee',
    'build' => '#a78bfa',
    'send'  => '#f59e0b',
    default => '#64748b',
};
$phaseLbl = static function (string $p): string {
    if ($p === '') { return ''; }
    $v = lang('Journey.phase.' . $p);
    return $v === 'Journey.phase.' . $p ? ucfirst($p) : $v;
};
$statusLbl = static function (string $s): string {
    $v = lang('Journey.admin.member.status.' . $s);
    return $v === 'Journey.admin.member.status.' . $s ? ucfirst($s) : $v;
};
$ctx     = $group_id === null ? '' : (string) $group_id;
$current = $journey !== null ? (string) ($journey['stage_code'] ?? '') : '';
// Build a name lookup so the timeline can show stage names, not just codes.
$stageName = [];
foreach ($ladder as $s) {
    $stageName[(string) ($s['code'] ?? '')] = (string) ($s['name'] ?? ($s['code'] ?? ''));
}
$nameOf = static fn (string $code): string => $code === '' ? '' : ($stageName[$code] ?? $code);
?>

<?php ob_start(); ?>
<?= esc(lang('Journey.admin.member.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 860px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size:1.05rem; margin:26px 0 10px; color:#e2e8f0; }


        .hero { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:18px 20px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; margin-bottom:14px; }


        .btn.warn { background:#b91c1c; }

 .btn.warn:hover { background:#991b1b; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <a class="back" href="/journey/pipeline<?= $ctx !== '' ? '?group_id=' . esc(rawurlencode($ctx), 'attr') : '' ?>">&larr; <?= esc(lang('Journey.admin.member.backToPipeline')) ?></a>
        <h1><?= esc(lang('Journey.admin.member.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Journey.admin.member.member')) ?>: <span class="mono"><?= esc($user_id !== '' ? redact_id($user_id) : '—') ?></span></p>

        <span class="scope">
            <?= $group_id === null || $group_id === ''
                ? esc(lang('Journey.orgWide'))
                : esc($li('Journey.groupScoped', (string) $group_id)) ?>
        </span>
        <a class="scope" style="text-decoration:none" href="/journey/members/<?= esc(rawurlencode($user_id), 'attr') ?>/recommendations<?= $ctx !== '' ? '?group_id=' . esc(rawurlencode($ctx), 'attr') : '' ?>">&rarr; <?= esc(lang('Journey.admin.recommendations.heading')) ?></a>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($journey === null): ?>
            <!-- No journey in this context yet — offer to OPEN one. -->
            <div class="empty"><?= esc(lang('Journey.admin.member.noJourney')) ?></div>
            <div class="card" style="margin-top:14px">
                <h2 style="margin-top:0"><?= esc(lang('Journey.admin.member.openHeading')) ?></h2>
                <form method="post" action="/journey/members/<?= esc(rawurlencode($user_id), 'attr') ?>/open" class="form">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <input type="hidden" name="group_id" value="<?= esc($ctx, 'attr') ?>">
                    <div class="fld">
                        <label><?= esc(lang('Journey.admin.member.stageLabel')) ?></label>
                        <select name="stage_code">
                            <option value=""><?= esc(lang('Journey.admin.member.entryStageOption')) ?></option>
                            <?php foreach ($ladder as $s): ?>
                                <option value="<?= esc((string) ($s['code'] ?? ''), 'attr') ?>"><?= esc((string) ($s['name'] ?? ($s['code'] ?? ''))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fld wide">
                        <label><?= esc(lang('Journey.admin.member.noteLabel')) ?></label>
                        <input name="note">
                    </div>
                    <div class="fld">
                        <button type="submit" class="btn" <?= $ladder === [] ? 'disabled title="' . esc(lang('Journey.admin.member.noLadder'), 'attr') . '"' : '' ?>><?= esc(lang('Journey.admin.member.openBtn')) ?></button>
                    </div>
                </form>
                <?php if ($ladder === []): ?><p class="sub" style="margin:10px 0 0"><?= esc(lang('Journey.admin.member.noLadder')) ?></p><?php endif; ?>
            </div>
        <?php else: ?>
            <?php
            $stage   = (string) ($journey['stage_code'] ?? '');
            $phase   = (string) ($journey['stage_phase'] ?? '');
            $status  = (string) ($journey['status'] ?? 'active');
            $entered = (string) ($journey['stage_entered_at'] ?? '');
            $source  = (string) ($journey['source'] ?? '');
            $prev    = (string) ($journey['previous_stage'] ?? '');
            $color   = $phaseColor($phase);
            ?>
            <div class="hero">
                <div class="stage">
                    <?= esc($nameOf($stage) !== '' ? $nameOf($stage) : ($stage !== '' ? $stage : '—')) ?>
                    <span class="chip" style="color:<?= esc($color, 'attr') ?>;border-color:<?= esc($color, 'attr') ?>55"><?= esc($phaseLbl($phase)) ?></span>
                    <span class="chip"><?= esc($statusLbl($status)) ?></span>
                </div>
                <div class="facts">
                    <span class="mono"><?= esc($stage) ?></span>
                    <?php if ($entered !== ''): ?> · <?= esc($li('Journey.admin.member.enteredOn', $entered)) ?><?php endif; ?>
                    <?php if ($prev !== ''): ?> · <?= esc(lang('Journey.admin.member.previous')) ?>: <span class="mono"><?= esc($prev) ?></span><?php endif; ?>
                    <?php if ($source !== ''): ?> · <?= esc(lang('Journey.admin.member.source')) ?>: <?= esc($source) ?><?php endif; ?>
                </div>
            </div>

            <!-- Advance / set stage -->
            <h2><?= esc(lang('Journey.admin.member.moveHeading')) ?></h2>
            <div class="card">
                <form method="post" action="/journey/members/<?= esc(rawurlencode($user_id), 'attr') ?>/transition" class="form"
                      onsubmit="return confirm('<?= esc(lang('Journey.admin.member.moveConfirm'), 'js') ?>');">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <input type="hidden" name="group_id" value="<?= esc($ctx, 'attr') ?>">
                    <div class="fld">
                        <label><?= esc(lang('Journey.admin.member.toStageLabel')) ?></label>
                        <select name="to_stage" required>
                            <?php foreach ($ladder as $s): ?>
                                <?php $sc = (string) ($s['code'] ?? ''); ?>
                                <option value="<?= esc($sc, 'attr') ?>" <?= $sc === $current ? 'disabled' : '' ?>>
                                    <?= esc((string) ($s['name'] ?? $sc)) ?><?= $sc === $current ? ' — ' . esc(lang('Journey.admin.member.currentTag')) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fld">
                        <label for="discipler_id"><?= esc(lang('Journey.admin.member.disciplerLabel')) ?></label>
                        <?php if ($disciplers !== []): ?>
                            <select id="discipler_id" name="discipler_id">
                                <option value=""><?= esc(lang('Journey.admin.member.disciplerNone')) ?></option>
                                <?php foreach ($disciplers as $d): ?>
                                    <?php
                                    $did = (string) ($d['user_id'] ?? '');
                                    if ($did === '') {
                                        continue;
                                    }
                                    $dname = trim((string) ($d['display_name'] ?? ''));
                                    $dlabel = $dname !== '' ? $dname : $did;
                                    ?>
                                    <option value="<?= esc($did, 'attr') ?>"><?= esc($dlabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input id="discipler_id" name="discipler_id" maxlength="64"
                                   placeholder="<?= esc(lang('Journey.admin.member.disciplerPh'), 'attr') ?>">
                        <?php endif; ?>
                    </div>
                    <div class="fld wide">
                        <label><?= esc(lang('Journey.admin.member.reasonLabel')) ?></label>
                        <input name="reason">
                    </div>
                    <div class="fld">
                        <button type="submit" class="btn"><?= esc(lang('Journey.admin.member.moveBtn')) ?></button>
                    </div>
                </form>
            </div>

            <!-- Status: pause / resume / archive -->
            <h2><?= esc(lang('Journey.admin.member.statusHeading')) ?></h2>
            <div class="card statusrow">
                <?php
                // Offer the status transitions that make sense from the current one.
                $targets = $status === 'active' ? ['paused', 'completed', 'archived']
                    : ($status === 'paused' ? ['active', 'archived'] : ['active']);
                ?>
                <?php foreach ($targets as $t): ?>
                    <form method="post" action="/journey/members/<?= esc(rawurlencode($user_id), 'attr') ?>/status"
                          onsubmit="return confirm('<?= esc(lang('Journey.admin.member.statusConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="hidden" name="group_id" value="<?= esc($ctx, 'attr') ?>">
                        <input type="hidden" name="status" value="<?= esc($t, 'attr') ?>">
                        <button type="submit" class="btn <?= $t === 'archived' ? 'warn' : 'sec' ?>"><?= esc($statusLbl($t)) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>

            <!-- Timeline -->
            <h2><?= esc(lang('Journey.admin.member.timelineHeading')) ?></h2>
            <?php if ($history === []): ?>
                <div class="empty"><?= esc(lang('Journey.admin.member.noHistory')) ?></div>
            <?php else: ?>
                <ol class="timeline">
                    <?php foreach (array_reverse($history) as $h): ?>
                        <?php
                        $hf   = (string) ($h['from_stage'] ?? '');
                        $ht   = (string) ($h['to_stage'] ?? '');
                        $hdir = (string) ($h['direction'] ?? '');
                        $hsrc = (string) ($h['source'] ?? '');
                        $hwhen = (string) ($h['created_at'] ?? '');
                        $hdisc = (string) ($h['discipler_id'] ?? '');
                        $hreason = (string) ($h['reason'] ?? '');
                        ?>
                        <li>
                            <div class="mv">
                                <?php if ($hdir === 'open' || $hf === ''): ?>
                                    <?= esc($li('Journey.admin.member.tlOpened', $nameOf($ht) !== '' ? $nameOf($ht) : $ht)) ?>
                                <?php else: ?>
                                    <?= esc($nameOf($hf) !== '' ? $nameOf($hf) : $hf) ?> → <?= esc($nameOf($ht) !== '' ? $nameOf($ht) : $ht) ?>
                                    <span class="chip"><?= esc($hdir) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($hwhen !== ''): ?><div class="when"><?= esc($hwhen) ?><?php if ($hsrc !== ''): ?> · <?= esc($hsrc) ?><?php endif; ?></div><?php endif; ?>
                            <?php if ($hdisc !== '' || $hreason !== ''): ?>
                                <div class="det">
                                    <?php if ($hdisc !== ''): ?><?= esc(lang('Journey.admin.member.discipler')) ?>: <span class="mono"><?= esc(redact_id($hdisc)) ?></span><?php endif; ?>
                                    <?php if ($hreason !== ''): ?><?= $hdisc !== '' ? ' · ' : '' ?><?= esc($hreason) ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
