<?= $this->extend('layouts/app') ?>

<?php
/**
 * Real-time engagement metrics (GET /streaming/{id}/engagement/realtime) — the
 * browser face of EngagementController::realtime, which otherwise rendered the
 * generic admin console. Shows the live window's concurrent viewers, chat and
 * reaction counts, a reaction breakdown and the engagement score, with an HONEST
 * degradation banner (FR-STR-013): when the relay is degraded or an incident is
 * open the numbers are marked estimated/partial. Not-found panel when the stream
 * is unknown.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Streaming.realtime.*') with English fallback; the exactness/relay-state
 * vocabularies localize with a raw-value fallback. Numbers render as-is.
 *
 * @var array<string,mixed>|null $metrics realTimeMetrics payload, or null if not found
 * @var string                   $title
 */
$m     = is_array($metrics ?? null) ? $metrics : null;
$title = $title ?? lang('Streaming.realtime.title');
$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') { return '—'; }
    $s = lang('Streaming.realtime.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'Streaming.')) ? $s : $value;
};
$breakdown = $m !== null && is_array($m['reaction_breakdown'] ?? null) ? $m['reaction_breakdown'] : [];
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Streaming.realtime.title')) ?></h1>
    <?php if ($m === null): ?>
        <div class="empty"><?= esc(lang('Streaming.realtime.notFound')) ?></div>
    <?php else: ?>
        <div class="sub"><?= esc(str_replace('{0}', (string) (int) ($m['window_minutes'] ?? 0), lang('Streaming.realtime.window'))) ?></div>

        <?php if (! empty($m['metrics_degraded'])): ?>
            <div class="card" style="border-color:#a16207;color:#fbbf24"><?= esc(lang('Streaming.realtime.degradedBanner')) ?></div>
        <?php endif; ?>

        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Streaming.realtime.concurrent')) ?></div><div class="v"><?= esc((string) (int) ($m['concurrent_viewers'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.realtime.chat')) ?></div><div class="v"><?= esc((string) (int) ($m['chat_last_window'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.realtime.reactions')) ?></div><div class="v"><?= esc((string) (int) ($m['reactions_last_window'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.realtime.score')) ?></div><div class="v"><?= esc((string) (int) ($m['engagement_score'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.realtime.relayState')) ?></div><div class="v"><?= esc($vocab('relay', (string) ($m['relay_state'] ?? ''))) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.realtime.exactnessLabel')) ?></div><div class="v"><?= esc($vocab('exactness', (string) ($m['metrics_exactness'] ?? ''))) ?></div></div>
        </div>

        <h2><?= esc(lang('Streaming.realtime.reactionBreakdown')) ?></h2>
        <?php if ($breakdown === []): ?>
            <div class="empty"><?= esc(lang('Streaming.realtime.noReactions')) ?></div>
        <?php else: ?>
            <?php foreach ($breakdown as $r): ?>
                <div class="card">
                    <div class="row">
                        <span class="author"><?= esc((string) ($r['reaction_type'] ?? '—')) ?></span>
                        <span class="pill"><?= esc((string) (int) ($r['n'] ?? 0)) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
