<?php
/**
 * FEEDBACK console (GET /events/{id}/feedback-forms) — the browser face of
 * FeedbackController::console, which otherwise left form creation + open/close as
 * JSON-only endpoints. Lists the event's feedback/quiz forms and provides no-JS
 * PRG forms:
 *   - create form → POST /events/{id}/feedback-forms
 *   - open form   → POST /feedback-forms/{fid}/open   (draft/closed → open)
 *   - close form  → POST /feedback-forms/{fid}/close  (open → closed)
 * Each form row links to its questions console. Every form carries the `_csrf`
 * field (WebCsrfFilter).
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.feedback.*') with English
 * fallback.
 *
 * @var list<array<string,mixed>> $forms   event_feedback_forms rows (+counts)
 * @var string                    $eventId the event id (for the write routes)
 * @var string                    $csrf    webcsrf token for the inline forms
 */
$forms   = $forms ?? [];
$eventId = $eventId ?? '';
$csrf    = $csrf ?? '';
$sidAttr = $eventId !== '' ? rawurlencode($eventId) : '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Events.feedback.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 980px; margin: 0 auto; padding: 5vh 20px 60px; }


        .event { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#f9a8d4; border:1px solid #db2777; border-radius:6px; padding:2px 8px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        .pill.draft { background:#334155; color:#cbd5e1; }


        .pill.open { background:#0f3d34; color:#5eead4; }


        input:focus, select:focus { outline:2px solid #db2777; border-color:#db2777; }


        button { border:0; border-radius:8px; padding:7px 14px; font-size:.83rem; font-weight:600; cursor:pointer; }


        button.primary { background:#db2777; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.feedback.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.feedback.eventLabel')) ?>: <span class="event"><?= esc($eventId !== '' ? $eventId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <!-- Forms list -->
        <h2><?= esc(lang('Events.feedback.listHeading')) ?></h2>
        <?php if ($forms === []): ?>
            <p class="empty"><?= esc(lang('Events.feedback.noForms')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.feedback.colTitle')) ?></th>
                    <th><?= esc(lang('Events.feedback.colKind')) ?></th>
                    <th><?= esc(lang('Events.feedback.colStatus')) ?></th>
                    <th class="num"><?= esc(lang('Events.feedback.colQuestions')) ?></th>
                    <th class="num"><?= esc(lang('Events.feedback.colResponses')) ?></th>
                    <th><?= esc(lang('Events.feedback.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($forms as $f): ?>
                        <?php
                        $fid    = (string) ($f['id'] ?? '');
                        $status = (string) ($f['status'] ?? '');
                        $pill   = in_array($status, ['draft', 'open', 'closed'], true) ? $status : 'draft';
                        $fidAttr = esc(rawurlencode($fid), 'attr');
                        ?>
                        <tr>
                            <td><a class="link" href="/feedback-forms/<?= $fidAttr ?>/questions"><?= esc((string) ($f['title'] ?? '—')) ?></a></td>
                            <td><span class="tag"><?= esc((string) ($f['kind'] ?? 'feedback')) ?></span></td>
                            <td><span class="pill <?= esc($pill, 'attr') ?>"><?= esc($status !== '' ? $status : '—') ?></span></td>
                            <td class="num"><?= esc((string) ($f['question_count'] ?? 0)) ?></td>
                            <td class="num"><?= esc((string) ($f['response_count'] ?? 0)) ?></td>
                            <td>
                                <div class="actions">
                                    <?php if ($status !== 'open'): ?>
                                        <form class="inline" method="post" action="/feedback-forms/<?= $fidAttr ?>/open">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                            <button type="submit" class="primary"><?= esc(lang('Events.feedback.openBtn')) ?></button>
                                        </form>
                                    <?php else: ?>
                                        <form class="inline" method="post" action="/feedback-forms/<?= $fidAttr ?>/close">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                            <button type="submit" class="ghost"><?= esc(lang('Events.feedback.closeBtn')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="link" href="/feedback-forms/<?= $fidAttr ?>/questions"><?= esc(lang('Events.feedback.manageQuestions')) ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Create form -->
        <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/feedback-forms">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h3><?= esc(lang('Events.feedback.addFormHeading')) ?></h3>
            <div class="grid">
                <div class="full"><label for="f-title"><?= esc(lang('Events.feedback.colTitle')) ?></label><input id="f-title" name="title" required maxlength="160"></div>
                <div>
                    <label for="f-kind"><?= esc(lang('Events.feedback.colKind')) ?></label>
                    <select id="f-kind" name="kind">
                        <option value="feedback"><?= esc(lang('Events.feedback.kindFeedback')) ?></option>
                        <option value="quiz"><?= esc(lang('Events.feedback.kindQuiz')) ?></option>
                    </select>
                </div>
                <div>
                    <label for="f-scoring"><?= esc(lang('Events.feedback.fScoring')) ?></label>
                    <select id="f-scoring" name="scoring">
                        <option value="none"><?= esc(lang('Events.feedback.scoringNone')) ?></option>
                        <option value="automatic"><?= esc(lang('Events.feedback.scoringAuto')) ?></option>
                        <option value="reviewed"><?= esc(lang('Events.feedback.scoringReviewed')) ?></option>
                    </select>
                </div>
                <div>
                    <label for="f-vis"><?= esc(lang('Events.feedback.fVisibility')) ?></label>
                    <select id="f-vis" name="visibility">
                        <option value="group"><?= esc(lang('Events.feedback.visGroup')) ?></option>
                        <option value="public"><?= esc(lang('Events.feedback.visPublic')) ?></option>
                        <option value="private"><?= esc(lang('Events.feedback.visPrivate')) ?></option>
                    </select>
                </div>
                <div><label for="f-pass"><?= esc(lang('Events.feedback.fPassScore')) ?></label><input type="number" min="0" id="f-pass" name="pass_score"></div>
                <div><label for="f-minn"><?= esc(lang('Events.feedback.fMinAggregate')) ?></label><input type="number" min="0" id="f-minn" name="min_aggregate_n" value="5"></div>
            </div>
            <button type="submit" class="primary" style="margin-top:12px"><?= esc(lang('Events.feedback.addFormBtn')) ?></button>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
