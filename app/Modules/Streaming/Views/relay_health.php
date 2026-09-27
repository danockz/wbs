<?= $this->extend('layouts/app') ?>

<?php
/**
 * Relay health snapshot (GET /streaming/{id}/relay/health) — the browser face of
 * StreamRelayController::health, which otherwise rendered the generic admin
 * console. Shows the current relay state, degraded-since time, the latest health
 * sample, any open incidents, and the manual BYPASS procedure (summary + numbered
 * steps + candidate destinations) so an organizer can keep broadcasting when the
 * relay fails. Not-found panel when the stream is unknown.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Streaming.relayHealth.*') with English fallback; the relay-state
 * vocabulary localizes with a raw-value fallback. The bypass summary/steps are
 * operational instructions returned by the service and shown verbatim.
 *
 * @var array<string,mixed>|null $health health payload, or null if not found
 * @var string                   $title
 */
$h     = is_array($health ?? null) ? $health : null;
$title = $title ?? lang('Streaming.relayHealth.title');
$vstate = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') { return '—'; }
    $s = lang('Streaming.relayHealth.state.' . $value);
    return (is_string($s) && ! str_contains($s, 'Streaming.')) ? $s : $value;
};
$incidents = $h !== null && is_array($h['open_incidents'] ?? null) ? $h['open_incidents'] : [];
$bypass    = $h !== null && is_array($h['bypass_procedure'] ?? null) ? $h['bypass_procedure'] : [];
$latest    = $h !== null && is_array($h['latest_sample'] ?? null) ? $h['latest_sample'] : null;
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Streaming.relayHealth.title')) ?></h1>
    <?php if ($h === null): ?>
        <div class="empty"><?= esc(lang('Streaming.relayHealth.notFound')) ?></div>
    <?php else: ?>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Streaming.relayHealth.stateLabel')) ?></div><div class="v"><?= esc($vstate((string) ($h['relay_state'] ?? ''))) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.relayHealth.degradedSince')) ?></div><div class="v"><?= esc((string) ($h['degraded_since'] ?? '—')) ?></div></div>
            <?php if ($latest !== null): ?>
                <div class="stat"><div class="k"><?= esc(lang('Streaming.relayHealth.latestLatency')) ?></div><div class="v"><?= esc((string) ($latest['latency_ms'] ?? '—')) ?></div></div>
                <div class="stat"><div class="k"><?= esc(lang('Streaming.relayHealth.latestStatus')) ?></div><div class="v"><?= esc((string) ($latest['status'] ?? '—')) ?></div></div>
            <?php endif; ?>
        </div>

        <h2><?= esc(lang('Streaming.relayHealth.openIncidents')) ?></h2>
        <?php if ($incidents === []): ?>
            <div class="empty"><?= esc(lang('Streaming.relayHealth.noIncidents')) ?></div>
        <?php else: ?>
            <?php foreach ($incidents as $inc): ?>
                <div class="card">
                    <div class="row">
                        <span class="author"><?= esc((string) ($inc['cause'] ?? '—')) ?></span>
                        <span class="pill"><?= esc((string) ($inc['severity'] ?? '—')) ?></span>
                    </div>
                    <div class="meta">
                        <?= esc(lang('Streaming.relayHealth.incidentStatus')) ?>: <?= esc((string) ($inc['status'] ?? '—')) ?>
                        <?php if (! empty($inc['detected_at'])): ?> · <?= esc((string) $inc['detected_at']) ?><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (! empty($bypass['summary'])): ?>
            <h2><?= esc(lang('Streaming.relayHealth.bypassProcedure')) ?></h2>
            <div class="card">
                <p><?= esc((string) $bypass['summary']) ?></p>
                <?php if (! empty($bypass['steps']) && is_array($bypass['steps'])): ?>
                    <ol>
                        <?php foreach ($bypass['steps'] as $step): ?><li><?= esc((string) $step) ?></li><?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
