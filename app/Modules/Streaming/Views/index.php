<?= $this->extend('layouts/app') ?>

<?php
/**
 * Streams index (SRS FR-STR-001). Server-rendered; JSON when negotiated. Live
 * streams sort first. Each links to its organizer dashboard.
 *
 * Copy is localized via lang('Streaming.*') with English as the guaranteed
 * fallback. Status LABELS are localized (falling back to the raw stored value
 * for unknown statuses); status COLOUR stays code-driven so translation never
 * affects styling. Numbers/plurals are selected in PHP so no ext-intl needed.
 *
 * @var array<string,mixed> $result  {streams:[...]}
 * @var string              $csrf
 */
$streams = $result['streams'] ?? [];
$csrf    = $csrf ?? '';
$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;
$col     = static fn (string $s): string => match ($s) {
    'live'             => '#4ade80',
    'ended', 'canceled' => '#f87171',
    'scheduled'        => '#38bdf8',
    default            => '#a5b4fc',
};
// {0}-style interpolation helper for the file-catalog strings.
$li = static fn (string $key, string $arg): string => str_replace('{0}', $arg, lang($key));
// Localized enum label with raw-value fallback.
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Streaming.status.' . $s);

    return $t === 'Streaming.status.' . $s ? $s : $t;
};
$accessLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Streaming.access.' . $s);

    return $t === 'Streaming.access.' . $s ? $s : $t;
};
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Streaming.title')) ?></h1>
    <div class="sub"><?= count($streams) ?> <?= esc(count($streams) === 1 ? lang('Streaming.stream') : lang('Streaming.streams')) ?> · <?= esc(lang('Streaming.liveFirst')) ?></div>

    <?php if ($flashOk !== null && $flashOk !== ''): ?>
        <div class="card" style="border-color:#22c55e55;background:#052e1b;color:#bbf7d0"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== null && $flashErr !== ''): ?>
        <div class="card" style="border-color:#ef444455;background:#3f1d1d;color:#fecaca"><?= esc($flashErr) ?></div>
    <?php endif; ?>

    <style>


        .lc select, .lc input { width:100%; background:#0b1120; border:1px solid #334155; border-radius:8px; color:#e2e8f0; padding:9px 10px; font-size:.86rem; }


        .lc .btn { border-radius:8px; padding:8px 14px; font-size:.82rem; font-weight:600; cursor:pointer; border:1px solid #0ea5e9; background:#0369a1; color:#fff; }
</style>

    <div class="lc">
        <details class="create">
            <summary><?= esc(lang('Streaming.lifecycle.createHeading')) ?></summary>
            <form class="create" method="post" action="<?= esc(base_url('streams'), 'attr') ?>">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div class="full">
                    <label><?= esc(lang('Streaming.lifecycle.titleLabel')) ?></label>
                    <input name="title" maxlength="200" required placeholder="<?= esc(lang('Streaming.lifecycle.titlePh'), 'attr') ?>">
                </div>
                <div>
                    <label><?= esc(lang('Streaming.lifecycle.accessLabel')) ?></label>
                    <select name="access_policy">
                        <option value="restricted"><?= esc($accessLbl('restricted')) ?></option>
                        <option value="public"><?= esc($accessLbl('public')) ?></option>
                    </select>
                </div>
                <div>
                    <label><?= esc(lang('Streaming.lifecycle.scheduledLabel')) ?></label>
                    <input type="datetime-local" name="scheduled_at">
                </div>
                <div class="full">
                    <button class="btn" type="submit"><?= esc(lang('Streaming.lifecycle.createBtn')) ?></button>
                </div>
            </form>
        </details>
    </div>

    <?php if ($streams === []): ?>
        <div class="empty"><?= esc(lang('Streaming.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($streams as $s): ?>
            <a class="card" style="display:block;" href="/streams/<?= esc($s['id'] ?? '') ?>/dashboard">
                <div class="row">
                    <span class="author">
                        <?php if (($s['status'] ?? '') === 'live'): ?><span style="color:#4ade80;">● </span><?php endif; ?>
                        <?= esc($s['title'] ?? lang('Streaming.streamFallback')) ?>
                    </span>
                    <span class="pill" style="color:<?= $col((string) ($s['status'] ?? '')) ?>;"><?= esc($statusLbl((string) ($s['status'] ?? ''))) ?></span>
                </div>
                <div class="counts">
                    <span class="pill"><?= esc($accessLbl((string) ($s['access_policy'] ?? 'restricted'))) ?></span>
                    <?php if (! empty($s['started_at'])): ?> · <?= esc($li('Streaming.started', (string) $s['started_at'])) ?><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
