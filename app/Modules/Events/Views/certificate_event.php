<?php
/**
 * EVENT CERTIFICATES console (GET /events/{id}/certificates) — the browser face
 * of CertificateController::eventConsole, which otherwise left the batch-request /
 * issue / revoke actions as JSON-only endpoints. Lists every certificate
 * requested/issued for the event and provides no-JS PRG forms:
 *   - batch request → POST /events/{id}/certificates/request (optional template)
 *   - issue         → POST /certificates/{cid}/issue   (pending only)
 *   - revoke        → POST /certificates/{cid}/revoke  (issued only, needs reason,
 *                     confirm() before submit)
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.certificate.*') with
 * English fallback. Every form carries the `_csrf` field (WebCsrfFilter). No user
 * PII beyond the raw user_id column is shown (this is an organizer admin page).
 *
 * @var list<array<string,mixed>> $certificates event_certificates rows
 * @var list<array<string,mixed>> $templates    certificate_templates rows (picker)
 * @var string                    $eventId      the event id (for the write routes)
 * @var string                    $csrf         webcsrf token for the inline forms
 */
$certificates = $certificates ?? [];
$templates    = $templates ?? [];
$eventId      = $eventId ?? '';
$csrf         = $csrf ?? '';
$sidAttr      = $eventId !== '' ? rawurlencode($eventId) : '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';
$confirmRevoke = str_replace('"', '&quot;', (string) lang('Events.certificate.revokeConfirm'));
?>

<?php ob_start(); ?>
<?= esc(lang('Events.certificate.eventMetaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 980px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        .pill.pending { background:#3f3410; color:#fde68a; }


        input:focus, select:focus { outline:2px solid #0e7666; border-color:#0e7666; }


        button { border:0; border-radius:8px; padding:7px 14px; font-size:.83rem; font-weight:600; cursor:pointer; }


        button.primary { background:#0e7666; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.certificate.eventHeading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.certificate.eventLabel')) ?>: <span class="event"><?= esc($eventId !== '' ? $eventId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <!-- Batch request -->
        <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/certificates/request">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h3><?= esc(lang('Events.certificate.requestHeading')) ?></h3>
            <p class="empty" style="font-style:normal;color:#94a3b8"><?= esc(lang('Events.certificate.requestHint')) ?></p>
            <div class="row">
                <div>
                    <label for="req-tpl"><?= esc(lang('Events.certificate.fTemplate')) ?></label>
                    <select id="req-tpl" name="template_id">
                        <option value=""><?= esc(lang('Events.certificate.templateAuto')) ?></option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= esc((string) ($t['id'] ?? ''), 'attr') ?>"><?= esc((string) ($t['name'] ?? '') . ' v' . (string) ($t['version'] ?? 1)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="primary"><?= esc(lang('Events.certificate.requestBtn')) ?></button>
            </div>
        </form>

        <!-- Certificates list -->
        <h2><?= esc(lang('Events.certificate.listHeading')) ?></h2>
        <?php if ($certificates === []): ?>
            <p class="empty"><?= esc(lang('Events.certificate.noCertificates')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.certificate.colUser')) ?></th>
                    <th><?= esc(lang('Events.certificate.colStatus')) ?></th>
                    <th><?= esc(lang('Events.certificate.colVerifyId')) ?></th>
                    <th><?= esc(lang('Events.certificate.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($certificates as $c): ?>
                        <?php
                        $cid    = (string) ($c['id'] ?? '');
                        $status = (string) ($c['status'] ?? '');
                        $pill   = in_array($status, ['pending', 'issued', 'revoked'], true) ? $status : 'pending';
                        ?>
                        <tr>
                            <td class="mono"><?= esc((string) ($c['user_id'] ?? '—')) ?></td>
                            <td><span class="pill <?= esc($pill, 'attr') ?>"><?= esc($status !== '' ? $status : '—') ?></span></td>
                            <td class="mono"><?= esc((string) ($c['verification_id'] ?? '—')) ?></td>
                            <td>
                                <div class="actions">
                                    <?php if ($status === 'pending'): ?>
                                        <form class="inline" method="post" action="/certificates/<?= esc(rawurlencode($cid), 'attr') ?>/issue">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                            <button type="submit" class="primary"><?= esc(lang('Events.certificate.issueBtn')) ?></button>
                                        </form>
                                    <?php elseif ($status === 'issued'): ?>
                                        <form class="inline" method="post" action="/certificates/<?= esc(rawurlencode($cid), 'attr') ?>/revoke"
                                              onsubmit="return confirm('<?= $confirmRevoke ?>')">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                            <div class="actions">
                                                <div>
                                                    <label for="rr-<?= esc($cid, 'attr') ?>"><?= esc(lang('Events.certificate.fReason')) ?></label>
                                                    <input id="rr-<?= esc($cid, 'attr') ?>" name="reason" required maxlength="200">
                                                </div>
                                                <button type="submit" class="danger"><?= esc(lang('Events.certificate.revokeBtn')) ?></button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <span class="empty"><?= esc((string) ($c['revoke_reason'] ?? '—')) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
