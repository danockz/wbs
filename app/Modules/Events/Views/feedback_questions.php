<?php
/**
 * FEEDBACK QUESTIONS console (GET /feedback-forms/{id}/questions) — the browser
 * face of FeedbackController::questionsConsole, which otherwise left addQuestion
 * as a JSON-only endpoint. Shows the form header + its ordered questions and a
 * no-JS PRG add form → POST /feedback-forms/{id}/questions (carries `_csrf`).
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.feedback.*').
 *
 * @var array<string,mixed>       $form      the event_feedback_forms row
 * @var list<array<string,mixed>> $questions ordered event_feedback_questions rows
 * @var string                    $csrf      webcsrf token for the add form
 */
$form      = $form ?? [];
$questions = $questions ?? [];
$csrf      = $csrf ?? '';
$formId    = (string) ($form['id'] ?? '');
$fidAttr   = $formId !== '' ? rawurlencode($formId) : '';
$eventId   = (string) ($form['event_id'] ?? '');

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Events.feedback.questionsMetaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        input:focus, select:focus { outline:2px solid #db2777; border-color:#db2777; }


        button { margin-top:12px; border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#db2777; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <?php if ($eventId !== ''): ?>
            <a class="back" href="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/feedback-forms">&larr; <?= esc(lang('Events.feedback.backToForms')) ?></a>
        <?php endif; ?>
        <h1><?= esc((string) ($form['title'] ?? lang('Events.feedback.questionsHeading'))) ?></h1>
        <p class="sub"><span class="tag"><?= esc((string) ($form['kind'] ?? 'feedback')) ?></span> · <?= esc(lang('Events.feedback.colStatus')) ?>: <?= esc((string) ($form['status'] ?? '—')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <h2><?= esc(lang('Events.feedback.questionsListHeading')) ?></h2>
        <?php if ($questions === []): ?>
            <p class="empty"><?= esc(lang('Events.feedback.noQuestions')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th class="num"><?= esc(lang('Events.feedback.colOrdinal')) ?></th>
                    <th><?= esc(lang('Events.feedback.colPrompt')) ?></th>
                    <th><?= esc(lang('Events.feedback.colType')) ?></th>
                    <th class="num"><?= esc(lang('Events.feedback.colPoints')) ?></th>
                    <th><?= esc(lang('Events.feedback.colRequired')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($questions as $q): ?>
                        <tr>
                            <td class="num"><?= esc((string) ($q['ordinal'] ?? 0)) ?></td>
                            <td><?= esc((string) ($q['prompt'] ?? '—')) ?></td>
                            <td><?= esc((string) ($q['qtype'] ?? 'rating')) ?></td>
                            <td class="num"><?= esc((string) ($q['points'] ?? 0)) ?></td>
                            <td><?= ! empty($q['required']) ? '<span class="req">' . esc(lang('Events.feedback.yes')) . '</span>' : esc(lang('Events.feedback.no')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form class="card" method="post" action="/feedback-forms/<?= esc($fidAttr, 'attr') ?>/questions">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h3><?= esc(lang('Events.feedback.addQuestionHeading')) ?></h3>
            <div class="grid">
                <div class="full"><label for="q-prompt"><?= esc(lang('Events.feedback.colPrompt')) ?></label><input id="q-prompt" name="prompt" required maxlength="500"></div>
                <div>
                    <label for="q-type"><?= esc(lang('Events.feedback.colType')) ?></label>
                    <select id="q-type" name="qtype">
                        <option value="rating"><?= esc(lang('Events.feedback.typeRating')) ?></option>
                        <option value="text"><?= esc(lang('Events.feedback.typeText')) ?></option>
                        <option value="choice"><?= esc(lang('Events.feedback.typeChoice')) ?></option>
                        <option value="boolean"><?= esc(lang('Events.feedback.typeBoolean')) ?></option>
                    </select>
                </div>
                <div><label for="q-ord"><?= esc(lang('Events.feedback.colOrdinal')) ?></label><input type="number" min="0" id="q-ord" name="ordinal" value="0"></div>
                <div><label for="q-points"><?= esc(lang('Events.feedback.colPoints')) ?></label><input type="number" min="0" id="q-points" name="points" value="0"></div>
                <div class="full"><label for="q-key"><?= esc(lang('Events.feedback.fAnswerKey')) ?></label><input id="q-key" name="answer_key" maxlength="255">
                    <div class="hint"><?= esc(lang('Events.feedback.answerKeyHint')) ?></div></div>
                <div class="full check"><input type="checkbox" id="q-req" name="required" value="1"><label for="q-req" style="margin:0"><?= esc(lang('Events.feedback.fRequired')) ?></label></div>
            </div>
            <button type="submit"><?= esc(lang('Events.feedback.addQuestionBtn')) ?></button>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
