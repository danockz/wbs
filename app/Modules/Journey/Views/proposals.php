<?php
/**
 * Membership-journey PROPOSAL REVIEW queue (journey/proposals) — the browser face
 * of JourneyController::listProposals, which otherwise only spoke JSON. The
 * maker-checker review queue for rule-proposed stage transitions (Option C): a
 * membership rule fired with effect require_review/flag and queued a proposed move
 * for a leader to confirm. Each pending proposal renders inline Approve / Reject
 * forms POSTing to the webcsrf-guarded /journey/proposals/{id}/{approve|reject}
 * routes; approving applies the transition (source=rule, proposal id as evidence).
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Journey.admin.proposals.*') with English fallback; the stage `phase`
 * vocabulary is localized with a raw-value fallback. Member/actor ids are redacted
 * for privacy; timestamps shown verbatim. Progressive-enhancement: plain forms,
 * fully usable with no JavaScript.
 *
 * @var ?string                          $group_id
 * @var list<array<string,mixed>>        $proposals journey_stage_proposals rows (pending)
 * @var string                           $csrf      webcsrf double-submit token
 */
$group_id  = $group_id ?? null;
$proposals = is_array($proposals ?? null) ? $proposals : [];
$csrf      = $csrf ?? '';
$count     = count($proposals);

include __DIR__ . '/_locale.php';

// redact_id: privacy-preserving id tail. Guard-load so the view still renders in
// the headless test harness where BaseController's helpers aren't registered.
if (! function_exists('redact_id')) {
    require_once dirname(__DIR__, 3) . '/Helpers/redactor_helper.php';
}

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$phaseLbl = static function (string $p): string {
    if ($p === '') { return ''; }
    $v = lang('Journey.phase.' . $p);
    return $v === 'Journey.phase.' . $p ? ucfirst($p) : $v;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Journey.admin.proposals.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; margin-bottom:14px; }


        .meta { color:#94a3b8; font-size:.8rem; margin-top:8px; line-height:1.6; }


        .fld label { font-size:.64rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }


        .fld input { padding:6px 9px; border-radius:7px; border:1px solid #334155; background:#0b1120; color:#e2e8f0; font-size:.82rem; min-width:170px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Journey.admin.proposals.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Journey.admin.proposals.sub')) ?></p>

        <span class="scope">
            <?= $group_id === null || $group_id === ''
                ? esc(lang('Journey.orgWide'))
                : esc($li('Journey.groupScoped', (string) $group_id)) ?>
        </span>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <p class="sub"><?= esc($li($count === 1 ? 'Journey.admin.proposals.countOne' : 'Journey.admin.proposals.count', (string) $count)) ?></p>

        <?php if ($proposals === []): ?>
            <p class="empty"><?= esc(lang('Journey.admin.proposals.empty')) ?></p>
        <?php else: ?>
            <?php foreach ($proposals as $p): ?>
                <?php
                $pid      = (string) ($p['id'] ?? '');
                $pc       = rawurlencode($pid);
                $from     = (string) ($p['from_stage'] ?? '');
                $to       = (string) ($p['to_stage'] ?? '');
                $userId   = (string) ($p['user_id'] ?? '');
                $ruleCode = (string) ($p['rule_code'] ?? '');
                $signal   = (string) ($p['signal_action'] ?? '');
                $reason   = (string) ($p['reason'] ?? '');
                $project  = (string) ($p['project_code'] ?? '');
                $created  = (string) ($p['created_at'] ?? '');
                $dir      = (string) ($p['direction'] ?? 'advance');
                ?>
                <div class="card">
                    <div class="move">
                        <span class="stage"><?= $from !== '' ? esc($from) : esc(lang('Journey.admin.proposals.noStage')) ?></span>
                        <span class="arrow">→</span>
                        <span class="stage"><?= esc($to !== '' ? $to : '—') ?></span>
                        <span class="chip"><?= esc($dir) ?></span>
                    </div>
                    <div class="meta">
                        <?= esc(lang('Journey.admin.proposals.member')) ?>: <span class="mono"><?= esc($userId !== '' ? redact_id($userId) : '—') ?></span>
                        <?php if ($ruleCode !== ''): ?> · <?= esc(lang('Journey.admin.proposals.rule')) ?>: <span class="mono"><?= esc($ruleCode) ?></span><?php endif; ?>
                        <?php if ($signal !== ''): ?> · <?= esc(lang('Journey.admin.proposals.signal')) ?>: <span class="mono"><?= esc($signal) ?></span><?php endif; ?>
                        <?php if ($project !== ''): ?> · <?= esc(lang('Journey.admin.proposals.project')) ?>: <span class="mono"><?= esc($project) ?></span><?php endif; ?>
                        <?php if ($created !== ''): ?> · <?= esc($created) ?><?php endif; ?>
                    </div>
                    <?php if ($reason !== ''): ?><div class="meta"><?= esc(lang('Journey.admin.proposals.reason')) ?>: <?= esc($reason) ?></div><?php endif; ?>

                    <?php if ($pid !== ''): ?>
                    <div class="acts">
                        <form method="post" action="/journey/proposals/<?= esc($pc, 'attr') ?>/approve"
                              onsubmit="return confirm('<?= esc(lang('Journey.admin.proposals.approveConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <div class="fld">
                                <label for="disc-<?= esc($pc, 'attr') ?>"><?= esc(lang('Journey.admin.proposals.disciplerLabel')) ?></label>
                                <input id="disc-<?= esc($pc, 'attr') ?>" name="discipler_id" placeholder="<?= esc(lang('Journey.admin.proposals.disciplerPh'), 'attr') ?>">
                            </div>
                            <button type="submit" class="btn approve"><?= esc(lang('Journey.admin.proposals.approve')) ?></button>
                        </form>
                        <form method="post" action="/journey/proposals/<?= esc($pc, 'attr') ?>/reject"
                              onsubmit="return confirm('<?= esc(lang('Journey.admin.proposals.rejectConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <div class="fld">
                                <label for="note-<?= esc($pc, 'attr') ?>"><?= esc(lang('Journey.admin.proposals.noteLabel')) ?></label>
                                <input id="note-<?= esc($pc, 'attr') ?>" name="note">
                            </div>
                            <button type="submit" class="btn reject"><?= esc(lang('Journey.admin.proposals.reject')) ?></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
