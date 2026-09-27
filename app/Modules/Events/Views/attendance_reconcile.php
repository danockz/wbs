<?php
/**
 * ATTENDANCE reconciliation (GET /events/{id}/attendance/reconcile) — the browser
 * face of CheckinController::reconcile (gap L6). Post-event, aggregate-only,
 * read-only, non-destructive: reconciles who ATTENDED against who REGISTERED and
 * who PAID, so the day's walk-ins and no-shows are auditable rather than silent.
 *
 * Shows totals (present, registered, paid), the matched/walk-in split of present
 * attendance, the present-by-method breakdown (qr / manual / streaming), and the
 * two follow-up signals: no-shows (registered, never checked in) and
 * paid-but-absent (paid an order, never checked in). A "reconciled" banner turns
 * green only when attendance, registration and payment all line up.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.attReconcile.*') with
 * English fallback; the method vocabulary is localized with a raw-value fallback.
 * All figures are server data shown verbatim & escaped; no identifiable rows.
 *
 * @var array<string,mixed>|null $recon reconciliation map from CheckinService::reconcile, or null
 */
$recon = $recon ?? null;
$found = is_array($recon);

include __DIR__ . '/_locale.php';

$n = static fn ($v): string => $v === null ? '—' : number_format((float) $v);
$vmethod = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Events.attReconcile.method.' . $value);
    return (is_string($s) && ! str_contains($s, 'Events.')) ? $s : $value;
};
$byMethod   = $found ? (array) ($recon['by_method'] ?? []) : [];
$reconciled = $found && ($recon['reconciled'] ?? false) === true;
?>

<?php ob_start(); ?>
<?= esc(lang('Events.attReconcile.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 820px; margin: 0 auto; padding: 5vh 20px 60px; }


        .title { color:#c4b5fd; font-weight:600; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:16px; }


        h2 { font-size:.95rem; color:#c4b5fd; margin:0 0 10px; }


        .banner { border-radius:12px; padding:12px 16px; margin-top:16px; font-weight:600; font-size:.95rem;
            border:1px solid; }


        .banner.ok { background:#14532d33; border-color:#166534; color:#86efac; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.attReconcile.heading')) ?></h1>
        <?php if (! $found): ?>
            <p class="notfound"><?= esc(lang('Events.attReconcile.notFound')) ?></p>
        <?php else: ?>
            <p class="sub"><span class="title"><?= esc((string) ($recon['title'] ?? '')) ?></span></p>

            <div class="banner <?= $reconciled ? 'ok' : 'warn' ?>">
                <?= esc($reconciled ? lang('Events.attReconcile.reconciledYes') : lang('Events.attReconcile.reconciledNo')) ?>
            </div>

            <div class="card">
                <h2><?= esc(lang('Events.attReconcile.totalsHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('Events.attReconcile.present')) ?></span><span class="v"><?= esc($n($recon['present'] ?? 0)) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Events.attReconcile.registered')) ?></span><span class="v"><?= esc($n($recon['registered'] ?? 0)) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Events.attReconcile.paid')) ?></span><span class="v"><?= esc($n($recon['paid'] ?? 0)) ?></span></div>
            </div>

            <div class="card">
                <h2><?= esc(lang('Events.attReconcile.splitHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('Events.attReconcile.matched')) ?><small><?= esc(lang('Events.attReconcile.matchedHint')) ?></small></span><span class="v"><?= esc($n($recon['matched'] ?? 0)) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Events.attReconcile.walkIn')) ?><small><?= esc(lang('Events.attReconcile.walkInHint')) ?></small></span><span class="v <?= (int) ($recon['walk_in'] ?? 0) > 0 ? 'warn' : '' ?>"><?= esc($n($recon['walk_in'] ?? 0)) ?></span></div>
            </div>

            <div class="card">
                <h2><?= esc(lang('Events.attReconcile.methodHeading')) ?></h2>
                <?php foreach (['qr', 'manual', 'streaming'] as $m): ?>
                    <div class="row"><span class="k"><?= esc($vmethod($m)) ?></span><span class="v"><?= esc($n($byMethod[$m] ?? 0)) ?></span></div>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <h2><?= esc(lang('Events.attReconcile.followUpHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('Events.attReconcile.noShow')) ?><small><?= esc(lang('Events.attReconcile.noShowHint')) ?></small></span><span class="v <?= (int) ($recon['no_show'] ?? 0) > 0 ? 'warn' : 'ok' ?>"><?= esc($n($recon['no_show'] ?? 0)) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Events.attReconcile.paidAbsent')) ?><small><?= esc(lang('Events.attReconcile.paidAbsentHint')) ?></small></span><span class="v <?= (int) ($recon['paid_absent'] ?? 0) > 0 ? 'warn' : 'ok' ?>"><?= esc($n($recon['paid_absent'] ?? 0)) ?></span></div>
            </div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
