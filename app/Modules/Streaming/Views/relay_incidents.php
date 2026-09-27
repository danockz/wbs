<?= $this->extend('layouts/app') ?>

<?php
/**
 * Relay incident OPERATOR CONSOLE (GET /streaming/{id}/relay/incidents) — the
 * browser face of StreamRelayController::incidents, which otherwise rendered the
 * generic admin console. Lists a stream's relay-failure incidents newest-first
 * and drives the FR-STR-013 mid-session failure response: a "report a failure"
 * form plus, per OPEN/ACKNOWLEDGED incident, the stage-appropriate controls —
 * acknowledge, activate the documented single-destination bypass (choosing a
 * destination), and resolve (with a note). Each control POSTs to a webcsrf-
 * guarded route; the controller PRG-redirects back here with a localized flash.
 * No-JS friendly (each action is its own form; destructive/consequential actions
 * use confirm()). The acting operator is taken from the session, not the form.
 *
 * Extends layouts/app (locale-aware <html lang dir>, RTL-correct). Copy via
 * lang('Streaming.relayIncidents.*') with English fallback; the {0} count is
 * interpolated via str_replace; severity/status localize with a raw-value
 * fallback. The CSRF token issued for this request is echoed into hidden _csrf.
 *
 * @var list<array<string,mixed>> $incidents    stream_relay_incidents rows
 * @var list<array<string,mixed>> $destinations bypass candidate destinations
 * @var string                    $streamId
 * @var string                    $csrf
 * @var string                    $title
 */
$incidents    = is_array($incidents ?? null) ? $incidents : [];
$destinations = is_array($destinations ?? null) ? $destinations : [];
$streamId     = $streamId ?? '';
$csrf         = $csrf ?? '';
$count        = count($incidents);
$title        = $title ?? lang('Streaming.relayIncidents.title');
$sidAttr      = rawurlencode($streamId);

$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;

$vsev = static function (string $v): string {
    $v = strtolower(trim($v));
    if ($v === '') { return '—'; }
    $s = lang('Streaming.relayIncidents.severity.' . $v);
    return (is_string($s) && ! str_contains($s, 'Streaming.')) ? $s : $v;
};
$vstatus = static function (string $v): string {
    $v = strtolower(trim($v));
    if ($v === '') { return '—'; }
    $s = lang('Streaming.relayIncidents.statusLabel.' . $v);
    return (is_string($s) && ! str_contains($s, 'Streaming.')) ? $s : $v;
};
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Streaming.relayIncidents.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Streaming.relayIncidents.countOne') : lang('Streaming.relayIncidents.count'))) ?></div>

    <?php if ($flashOk !== null && $flashOk !== ''): ?>
        <div class="card" style="border-color:#22c55e55;background:#052e1b;color:#bbf7d0"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== null && $flashErr !== ''): ?>
        <div class="card" style="border-color:#ef444455;background:#3f1d1d;color:#fecaca"><?= esc($flashErr) ?></div>
    <?php endif; ?>

    

    <div class="console">
        <!-- Report a failure -->
        <details class="report">
            <summary><?= esc(lang('Streaming.relayIncidents.reportHeading')) ?></summary>
            <form method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/relay/report'), 'attr') ?>" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div>
                    <label><?= esc(lang('Streaming.relayIncidents.severityLabel')) ?></label>
                    <select name="severity">
                        <option value="critical"><?= esc($vsev('critical')) ?></option>
                        <option value="warning"><?= esc($vsev('warning')) ?></option>
                    </select>
                </div>
                <div style="flex:1;min-width:200px">
                    <label><?= esc(lang('Streaming.relayIncidents.causeLabel')) ?></label>
                    <input type="text" name="cause" maxlength="255" placeholder="<?= esc(lang('Streaming.relayIncidents.causePh'), 'attr') ?>" style="width:100%">
                </div>
                <button class="btn go" type="submit"><?= esc(lang('Streaming.relayIncidents.reportBtn')) ?></button>
            </form>
        </details>

        <?php if ($incidents === []): ?>
            <div class="empty"><?= esc(lang('Streaming.relayIncidents.empty')) ?></div>
        <?php else: ?>
            <?php foreach ($incidents as $inc): ?>
                <?php
                $iid    = (string) ($inc['id'] ?? '');
                $status = strtolower((string) ($inc['status'] ?? ''));
                $iidAttr = rawurlencode($iid);
                $open   = in_array($status, ['open', 'acknowledged'], true);
                ?>
                <div class="card">
                    <div class="row">
                        <span class="author"><?= esc((string) ($inc['cause'] ?? '—')) ?></span>
                        <span class="pill"><?= esc($vsev((string) ($inc['severity'] ?? ''))) ?></span>
                    </div>
                    <div class="meta" style="border:0;padding:0;margin-top:8px">
                        <?= esc(lang('Streaming.relayIncidents.status')) ?>: <?= esc($vstatus($status)) ?>
                        <?php if (! empty($inc['detected_at'])): ?> · <?= esc(lang('Streaming.relayIncidents.detected')) ?> <?= esc((string) $inc['detected_at']) ?><?php endif; ?>
                        <?php if (! empty($inc['resolved_at'])): ?> · <?= esc(lang('Streaming.relayIncidents.resolved')) ?> <?= esc((string) $inc['resolved_at']) ?><?php endif; ?>
                        <?php if (! empty($inc['bypass_activated'])): ?> · <span class="warn"><?= esc(lang('Streaming.relayIncidents.bypassUsed')) ?></span><?php endif; ?>
                    </div>
                    <?php if (! empty($inc['resolution_note'])): ?><div class="counts"><?= esc((string) $inc['resolution_note']) ?></div><?php endif; ?>

                    <?php if ($iid !== '' && $open): ?>
                        <div class="actions">
                            <!-- Acknowledge (open only) -->
                            <?php if ($status === 'open'): ?>
                                <form method="post" action="<?= esc(base_url('stream-incidents/' . $iidAttr . '/acknowledge'), 'attr') ?>">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <input type="hidden" name="stream_id" value="<?= esc($streamId, 'attr') ?>">
                                    <button class="btn go" type="submit"><?= esc(lang('Streaming.relayIncidents.acknowledgeBtn')) ?></button>
                                </form>
                            <?php endif; ?>

                            <!-- Activate the direct-single-destination bypass -->
                            <form method="post" action="<?= esc(base_url('stream-incidents/' . $iidAttr . '/bypass'), 'attr') ?>"
                                  onsubmit="return confirm('<?= esc(lang('Streaming.relayIncidents.bypassConfirm'), 'attr') ?>');">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <input type="hidden" name="stream_id" value="<?= esc($streamId, 'attr') ?>">
                                <?php if ($destinations !== []): ?>
                                    <select name="destination_id" aria-label="<?= esc(lang('Streaming.relayIncidents.destinationLabel'), 'attr') ?>">
                                        <option value=""><?= esc(lang('Streaming.relayIncidents.destinationNone')) ?></option>
                                        <?php foreach ($destinations as $d): ?>
                                            <option value="<?= esc((string) ($d['id'] ?? ''), 'attr') ?>">
                                                <?= esc((string) ($d['label'] ?? $d['provider'] ?? $d['id'] ?? '')) ?> (<?= esc((string) ($d['status'] ?? '')) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <button class="btn warn" type="submit"><?= esc(lang('Streaming.relayIncidents.bypassBtn')) ?></button>
                            </form>

                            <!-- Resolve (with a note) -->
                            <form method="post" action="<?= esc(base_url('stream-incidents/' . $iidAttr . '/resolve'), 'attr') ?>"
                                  onsubmit="return confirm('<?= esc(lang('Streaming.relayIncidents.resolveConfirm'), 'attr') ?>');">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <input type="hidden" name="stream_id" value="<?= esc($streamId, 'attr') ?>">
                                <input type="text" name="note" maxlength="255" placeholder="<?= esc(lang('Streaming.relayIncidents.notePh'), 'attr') ?>">
                                <button class="btn ok" type="submit"><?= esc(lang('Streaming.relayIncidents.resolveBtn')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?= $this->endSection() ?>
