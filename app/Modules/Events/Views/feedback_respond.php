<?php
/**
 * FEEDBACK / QUIZ RESPONDENT page (GET /feedback-forms/{id}/respond) — the
 * respondent-facing face of FeedbackController::respond, which otherwise left
 * response submission as a JSON-only endpoint. Renders the form's questions as a
 * no-JS survey/quiz that POSTs to /feedback-forms/{id}/responses.
 *
 *   - rating  → radio 1..5
 *   - boolean → yes/no radios
 *   - choice  → <select> of the question's choices
 *   - text    → <textarea>
 * Fields are namespaced by question id (rating[{qid}], answer_text[{qid}],
 * answer_choice[{qid}]) so the controller can fold them back into answers.
 * Each form carries the `_csrf` field (WebCsrfFilter guards the POST).
 *
 * Quiz secrets (answer keys, points) are NEVER present — respondentView strips
 * them server-side. A closed form or an already-submitted respondent sees a
 * notice instead of the form. SELF-CONTAINED page (own <html>, _locale.php),
 * CSP-safe (no inline JS / on* handlers). Copy via lang('Events.respond.*').
 *
 * @var string                    $formId           the form id (for the POST route)
 * @var array<string,mixed>       $form             feedback form header (no secrets)
 * @var list<array<string,mixed>> $questions        respondent-safe questions
 * @var bool                      $isOpen           form accepts responses
 * @var bool                      $alreadySubmitted this respondent already answered
 * @var string                    $csrf             webcsrf token for the form
 */
$form             = $form ?? [];
$questions        = $questions ?? [];
$isOpen           = $isOpen ?? false;
$alreadySubmitted = $alreadySubmitted ?? false;
$formId           = $formId ?? '';
$csrf             = $csrf ?? '';
$sidAttr          = $formId !== '' ? rawurlencode($formId) : '';

$isQuiz = (string) ($form['kind'] ?? 'feedback') === 'quiz';
$eventId = (string) ($form['event_id'] ?? '');

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Events.respond.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 720px; margin: 0 auto; padding: 5vh 20px 60px; }


        .event { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#7dd3fc; border:1px solid #0369a1; border-radius:6px; padding:2px 8px; }


        .notice { border-radius:12px; padding:20px; background:#0f172aee; border:1px solid #1e293b; }


        .notice p { margin:0; color:#cbd5e1; font-size:.92rem; }


        textarea:focus, select:focus { outline:2px solid #0ea5e9; border-color:#0ea5e9; }


        button { margin-top:6px; border:0; border-radius:8px; padding:11px 22px; font-size:.95rem; font-weight:600; cursor:pointer; background:#0ea5e9; color:#04222f; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc($isQuiz ? lang('Events.respond.headingQuiz') : lang('Events.respond.headingFeedback')) ?></h1>
        <p class="sub"><?= esc((string) ($form['title'] ?? '')) ?><?php if ($eventId !== ''): ?> · <?= esc(lang('Events.respond.eventLabel')) ?>: <span class="event"><?= esc($eventId) ?></span><?php endif; ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($alreadySubmitted || $flashOk !== ''): ?>
            <div class="notice">
                <h2><?= esc(lang('Events.respond.thanksHeading')) ?></h2>
                <p><?= esc(lang('Events.respond.thanksBody')) ?></p>
            </div>
        <?php elseif (! $isOpen): ?>
            <div class="notice">
                <p><?= esc(lang('Events.respond.closedNotice')) ?></p>
            </div>
        <?php elseif ($questions === []): ?>
            <p class="empty"><?= esc(lang('Events.respond.noQuestions')) ?></p>
        <?php else: ?>
            <p class="intro"><?= esc(lang('Events.respond.intro')) ?></p>
            <form method="post" action="/feedback-forms/<?= esc($sidAttr, 'attr') ?>/responses">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <?php foreach ($questions as $q): ?>
                    <?php
                    $qid  = (string) ($q['id'] ?? '');
                    $qa   = esc($qid, 'attr');
                    $type = (string) ($q['qtype'] ?? 'rating');
                    $req  = (bool) ($q['required'] ?? false);
                    ?>
                    <div class="q">
                        <p class="prompt"><?= esc((string) ($q['prompt'] ?? '')) ?><?php if ($req): ?><span class="req"><?= esc(lang('Events.respond.requiredMark')) ?></span><?php endif; ?></p>

                        <?php if ($type === 'rating'): ?>
                            <div class="scale" role="radiogroup" aria-label="<?= esc(lang('Events.respond.ratingLabel'), 'attr') ?>">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <label><input type="radio" name="rating[<?= $qa ?>]" value="<?= $i ?>"<?= $req ? ' required' : '' ?>><?= $i ?></label>
                                <?php endfor; ?>
                            </div>
                            <div class="scalehint"><span><?= esc(lang('Events.respond.ratingLow')) ?></span><span><?= esc(lang('Events.respond.ratingHigh')) ?></span></div>

                        <?php elseif ($type === 'boolean'): ?>
                            <label class="opt"><input type="radio" name="answer_choice[<?= $qa ?>]" value="yes"<?= $req ? ' required' : '' ?>> <?= esc(lang('Events.respond.yesLabel')) ?></label>
                            <label class="opt"><input type="radio" name="answer_choice[<?= $qa ?>]" value="no"<?= $req ? ' required' : '' ?>> <?= esc(lang('Events.respond.noLabel')) ?></label>

                        <?php elseif ($type === 'choice' && ! empty($q['choices'])): ?>
                            <select name="answer_choice[<?= $qa ?>]"<?= $req ? ' required' : '' ?>>
                                <option value=""><?= esc(lang('Events.respond.choosePlaceholder')) ?></option>
                                <?php foreach ($q['choices'] as $c): ?>
                                    <option value="<?= esc((string) $c, 'attr') ?>"><?= esc((string) $c) ?></option>
                                <?php endforeach; ?>
                            </select>

                        <?php else: ?>
                            <textarea name="answer_text[<?= $qa ?>]" placeholder="<?= esc(lang('Events.respond.textPlaceholder'), 'attr') ?>"<?= $req ? ' required' : '' ?>></textarea>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit"><?= esc(lang('Events.respond.submitBtn')) ?></button>
            </form>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
