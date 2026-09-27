<?php
/**
 * FEEDBACK aggregate (GET /events/feedback/{formId}/aggregate) — the browser face
 * of FeedbackController::aggregate, which otherwise rendered the generic admin
 * console. Aggregate-only and privacy-safe: when the response count is below the
 * form's min_aggregate_n the page shows a suppression notice instead of figures.
 * Otherwise it shows per-question rating averages and, for quizzes, the score
 * summary. When the form is not found (Result::notFound) a localized panel shows.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.feedbackAgg.*') with
 * English fallback; question prompts and numbers are server data shown verbatim
 * & escaped.
 *
 * @var array<string,mixed>|null $aggregate aggregate map from FeedbackService::aggregate, or null
 */
$aggregate = $aggregate ?? null;
$found     = is_array($aggregate);

include __DIR__ . '/_locale.php';

$num = static fn ($v): string => $v === null ? '—' : (string) $v;
?>

<?php ob_start(); ?>
<?= esc(lang('Events.feedbackAgg.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 820px; margin: 0 auto; padding: 5vh 20px 60px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:16px; }


        h2 { font-size:.95rem; color:#c4b5fd; margin:0 0 10px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <?php if (! $found): ?>
            <h1><?= esc(lang('Events.feedbackAgg.heading')) ?></h1>
            <p class="notfound"><?= esc(lang('Events.feedbackAgg.notFound')) ?></p>
        <?php elseif (! empty($aggregate['suppressed'])): ?>
            <h1><?= esc(lang('Events.feedbackAgg.heading')) ?></h1>
            <p class="sub"><?= esc(lang('Events.feedbackAgg.sub')) ?></p>
            <p class="suppressed">
                <?= esc(lang('Events.feedbackAgg.suppressed')) ?><br>
                <?= esc($li('Events.feedbackAgg.suppressedDetail', (string) ($aggregate['responses'] ?? 0), (string) ($aggregate['min_n'] ?? 0))) ?>
            </p>
        <?php else: ?>
            <?php
            $ratings = (array) ($aggregate['ratings'] ?? []);
            $quiz    = $aggregate['quiz'] ?? null;
            ?>
            <h1><?= esc(lang('Events.feedbackAgg.heading')) ?></h1>
            <p class="sub"><?= esc(lang('Events.feedbackAgg.sub')) ?></p>

            <div class="card">
                <div class="row"><span class="k"><?= esc(lang('Events.feedbackAgg.responses')) ?></span><span class="v"><?= esc(number_format((int) ($aggregate['responses'] ?? 0))) ?></span></div>
                <?php if (! empty($aggregate['kind'])): ?>
                    <div class="row"><span class="k"><?= esc(lang('Events.feedbackAgg.kind')) ?></span><span class="v"><?= esc((string) $aggregate['kind']) ?></span></div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><?= esc(lang('Events.feedbackAgg.ratingsHeading')) ?></h2>
                <?php if ($ratings === []): ?>
                    <p class="empty"><?= esc(lang('Events.feedbackAgg.ratingsEmpty')) ?></p>
                <?php else: ?>
                    <?php foreach ($ratings as $q): ?>
                        <div class="row">
                            <span class="k"><?= esc((string) ($q['prompt'] ?? '—')) ?></span>
                            <span class="v"><?= esc($num($q['avg_rating'] ?? null)) ?> <span style="color:#64748b;font-weight:400">(n=<?= esc((string) ($q['n'] ?? 0)) ?>)</span></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (is_array($quiz)): ?>
                <div class="card">
                    <h2><?= esc(lang('Events.feedbackAgg.quizHeading')) ?></h2>
                    <div class="row"><span class="k"><?= esc(lang('Events.feedbackAgg.avgScore')) ?></span><span class="v"><?= esc($num($quiz['avg_score'] ?? null)) ?><?= isset($quiz['max_score']) && $quiz['max_score'] !== null ? ' / ' . esc((string) $quiz['max_score']) : '' ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.feedbackAgg.passers')) ?></span><span class="v"><?= esc($num($quiz['passers'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.feedbackAgg.scored')) ?></span><span class="v"><?= esc($num($quiz['scored'] ?? null)) ?></span></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
