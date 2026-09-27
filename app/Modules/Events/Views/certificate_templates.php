<?php
/**
 * CERTIFICATE TEMPLATES console (GET /certificates/templates) — the browser face
 * of CertificateController::templatesConsole, which otherwise left template
 * creation as a JSON-only endpoint. Lists the organization's certificate
 * templates and turns creation into a no-JS PRG form → POST /certificates/templates.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.certificate.*') with
 * English fallback. The create form carries the `_csrf` field (WebCsrfFilter).
 *
 * @var list<array<string,mixed>> $templates certificate_templates rows
 * @var string                    $csrf      webcsrf token for the inline form
 */
$templates = $templates ?? [];
$csrf      = $csrf ?? '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Events.certificate.templatesMetaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        input:focus, textarea:focus { outline:2px solid #0e7666; border-color:#0e7666; }


        button { margin-top:12px; border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#0e7666; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.certificate.templatesHeading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.certificate.templatesSub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <h2><?= esc(lang('Events.certificate.templatesListHeading')) ?></h2>
        <?php if ($templates === []): ?>
            <p class="empty"><?= esc(lang('Events.certificate.noTemplates')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.certificate.colName')) ?></th>
                    <th><?= esc(lang('Events.certificate.colGroup')) ?></th>
                    <th><?= esc(lang('Events.certificate.colEventType')) ?></th>
                    <th class="num"><?= esc(lang('Events.certificate.colVersion')) ?></th>
                    <th><?= esc(lang('Events.certificate.colSigner')) ?></th>
                    <th><?= esc(lang('Events.certificate.colStatus')) ?></th>
                    <th><?= esc(lang('Events.certificate.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($templates as $t): ?>
                        <?php $tid = (string) ($t['id'] ?? ''); ?>
                        <tr>
                            <td><?= esc((string) ($t['name'] ?? '—')) ?></td>
                            <td><?= esc((string) ($t['group_id'] ?? '') !== '' ? (string) $t['group_id'] : lang('Events.certificate.orgLevel')) ?></td>
                            <td><?= esc((string) ($t['event_type'] ?? lang('Events.certificate.anyType'))) ?></td>
                            <td class="num"><?= esc((string) ($t['version'] ?? 1)) ?></td>
                            <td><?= esc((string) ($t['signer_role'] ?? '—')) ?></td>
                            <td><span class="pill"><?= esc((string) ($t['status'] ?? '—')) ?></span></td>
                            <td>
                                <a href="/certificates/templates/<?= esc($tid, 'attr') ?>/preview"><?= esc(lang('Events.certificate.preview')) ?></a>
                                <?php if ((string) ($t['status'] ?? '') !== 'retired'): ?>
                                    · <a href="/certificates/templates/<?= esc($tid, 'attr') ?>/edit"><?= esc(lang('Events.certificate.edit')) ?></a>
                                    <form method="post" action="/certificates/templates/<?= esc($tid, 'attr') ?>/retire" style="display:inline">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <button type="submit"><?= esc(lang('Events.certificate.retireBtn')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form class="card" method="post" action="/certificates/templates">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h3><?= esc(lang('Events.certificate.addTemplateHeading')) ?></h3>
            <div class="grid">
                <div><label for="c-name"><?= esc(lang('Events.certificate.colName')) ?></label><input id="c-name" name="name" required maxlength="120"></div>
                <div><label for="c-group"><?= esc(lang('Events.certificate.fGroup')) ?></label><input id="c-group" name="group_id" maxlength="36">
                    <div class="hint"><?= esc(lang('Events.certificate.groupHint')) ?></div></div>
                <div><label for="c-type"><?= esc(lang('Events.certificate.fEventType')) ?></label><input id="c-type" name="event_type" maxlength="60">
                    <div class="hint"><?= esc(lang('Events.certificate.eventTypeHint')) ?></div></div>
                <div><label for="c-ver"><?= esc(lang('Events.certificate.colVersion')) ?></label><input type="number" min="1" id="c-ver" name="version" value="1"></div>
                <div><label for="c-signer"><?= esc(lang('Events.certificate.fSignerRole')) ?></label><input id="c-signer" name="signer_role" maxlength="80"></div>
                <div class="full"><label for="c-body"><?= esc(lang('Events.certificate.fBodyTemplate')) ?></label><textarea id="c-body" name="body_template" required></textarea>
                    <div class="hint"><?= esc(lang('Events.certificate.bodyHint')) ?></div></div>
            </div>
            <button type="submit"><?= esc(lang('Events.certificate.addTemplateBtn')) ?></button>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
