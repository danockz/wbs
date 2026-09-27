<?php
/**
 * KIOSK console (GET /events/{id}/kiosks) — the browser face of
 * KioskController::console, which otherwise left kiosk register / manifest /
 * reconcile / revoke as JSON-only endpoints. Lists the event's check-in kiosks
 * and turns each management action into a no-JS PRG form:
 *   - register        → POST /events/{id}/kiosks
 *   - refresh manifest→ POST /kiosks/{kid}/manifest
 *   - reconcile scans → POST /kiosks/{kid}/reconcile
 *   - revoke          → POST /kiosks/{kid}/revoke   (confirm() before submit)
 *
 * The device-facing offline-scan ENQUEUE endpoint is intentionally NOT here — it
 * is called by the kiosk app, not a browser. Every form carries the `_csrf` field
 * (WebCsrfFilter). No device fingerprints or manifest ciphertext are shown.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.kiosk.*') with English
 * fallback.
 *
 * @var list<array<string,mixed>> $kiosks  event_checkin_kiosks rows (+queued_scans)
 * @var string                    $eventId the event id (for the write routes)
 * @var string                    $csrf    webcsrf token for the inline forms
 */
$kiosks  = $kiosks ?? [];
$eventId = $eventId ?? '';
$csrf    = $csrf ?? '';
$sidAttr = $eventId !== '' ? rawurlencode($eventId) : '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';
$confirmRevoke = str_replace('"', '&quot;', (string) lang('Events.kiosk.revokeConfirm'));
?>

<?php ob_start(); ?>
<?= esc(lang('Events.kiosk.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 980px; margin: 0 auto; padding: 5vh 20px 60px; }


        .event { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#93c5fd; border:1px solid #2563eb; border-radius:6px; padding:2px 8px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }
        .pill.active { background:#0f3d34; color:#5eead4; }


        input:focus { outline:2px solid #2563eb; border-color:#2563eb; }


        button { border:0; border-radius:8px; padding:7px 13px; font-size:.82rem; font-weight:600; cursor:pointer; }


        button.primary { background:#2563eb; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.kiosk.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.kiosk.eventLabel')) ?>: <span class="event"><?= esc($eventId !== '' ? $eventId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <!-- Register -->
        <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/kiosks">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h3><?= esc(lang('Events.kiosk.registerHeading')) ?></h3>
            <div class="row">
                <div>
                    <label for="k-label"><?= esc(lang('Events.kiosk.fDeviceLabel')) ?></label>
                    <input id="k-label" name="device_label" required maxlength="120">
                </div>
                <div>
                    <label for="k-fp"><?= esc(lang('Events.kiosk.fFingerprint')) ?></label>
                    <input id="k-fp" name="device_fingerprint" maxlength="255">
                    <div class="hint"><?= esc(lang('Events.kiosk.fingerprintHint')) ?></div>
                </div>
                <button type="submit" class="primary"><?= esc(lang('Events.kiosk.registerBtn')) ?></button>
            </div>
        </form>

        <!-- Kiosk list -->
        <h2><?= esc(lang('Events.kiosk.listHeading')) ?></h2>
        <?php if ($kiosks === []): ?>
            <p class="empty"><?= esc(lang('Events.kiosk.noKiosks')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.kiosk.colLabel')) ?></th>
                    <th><?= esc(lang('Events.kiosk.colStatus')) ?></th>
                    <th class="num"><?= esc(lang('Events.kiosk.colManifest')) ?></th>
                    <th class="num"><?= esc(lang('Events.kiosk.colQueued')) ?></th>
                    <th><?= esc(lang('Events.kiosk.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($kiosks as $k): ?>
                        <?php
                        $kid    = (string) ($k['id'] ?? '');
                        $status = (string) ($k['status'] ?? '');
                        $pill   = $status === 'active' ? 'active' : 'revoked';
                        $queued = (int) ($k['queued_scans'] ?? 0);
                        $kidAttr = esc(rawurlencode($kid), 'attr');
                        ?>
                        <tr>
                            <td><?= esc((string) ($k['device_label'] ?? '—')) ?></td>
                            <td><span class="pill <?= esc($pill, 'attr') ?>"><?= esc($status !== '' ? $status : '—') ?></span></td>
                            <td class="num">v<?= esc((string) ($k['manifest_version'] ?? 0)) ?></td>
                            <td class="num"><?php if ($queued > 0): ?><span class="badge"><?= esc((string) $queued) ?></span><?php else: ?>0<?php endif; ?></td>
                            <td>
                                <?php if ($status === 'active'): ?>
                                    <div class="actions">
                                        <form class="inline" method="post" action="/kiosks/<?= $kidAttr ?>/manifest">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                            <button type="submit" class="primary"><?= esc(lang('Events.kiosk.manifestBtn')) ?></button>
                                        </form>
                                        <form class="inline" method="post" action="/kiosks/<?= $kidAttr ?>/reconcile">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                            <button type="submit" class="ghost"<?= $queued === 0 ? ' disabled' : '' ?>><?= esc(lang('Events.kiosk.reconcileBtn')) ?></button>
                                        </form>
                                        <form class="inline" method="post" action="/kiosks/<?= $kidAttr ?>/revoke"
                                              onsubmit="return confirm('<?= $confirmRevoke ?>')">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                            <button type="submit" class="danger"><?= esc(lang('Events.kiosk.revokeBtn')) ?></button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="empty"><?= esc(lang('Events.kiosk.revokedNote')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
