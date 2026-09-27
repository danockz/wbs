<?= $this->extend('layouts/app') ?>

<?php
/**
 * Events index (SRS FR-EVT-001). Server-rendered; JSON when negotiated. Lists an
 * organization's events newest-start-first, each linking to its overview page.
 *
 * Localized via lang('Events.*') (see app/Modules/Events/Language/<locale>/).
 * Numbers are interpolated in PHP and singular/plural is chosen here, so it
 * renders correctly with or without ext-intl. Enum labels fall back to the raw
 * stored value when a translation key is missing.
 *
 * @var array<string,mixed> $result   {events:[...]}
 * @var string              $archived active|archived|all — the L4 list filter
 */
$events = $result['events'] ?? [];
$view   = $archived ?? 'active';
$col    = static fn (string $s): string => match ($s) {
    'published'               => '#4ade80',
    'cancelled'               => '#f87171',
    'completed'               => '#38bdf8',
    'completed_no_attendance' => '#fbbf24',
    default                   => '#a5b4fc',
};
// Enum label with graceful fallback to the raw value.
$enum = static function (string $group, string $value): string {
    if ($value === '') {
        return '';
    }
    $label = lang('Events.' . $group . '.' . $value);

    return (is_string($label) && $label !== 'Events.' . $group . '.' . $value) ? $label : $value;
};
$count = count($events);
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Events.title')) ?></h1>
    <div class="sub"><?= $count ?> <?= esc($count === 1 ? lang('Events.event') : lang('Events.events')) ?></div>

    <?php // L4 — active / archived filter tabs (plain links, no-JS / CSP-safe). ?>
    <div class="row" style="gap:8px;margin:8px 0 14px;flex-wrap:wrap;">
        <?php foreach (['active' => 'filterActive', 'archived' => 'filterArchived', 'all' => 'filterAll'] as $key => $lk): ?>
            <?php $on = $view === $key; ?>
            <a href="/events?archived=<?= esc($key, 'attr') ?>"
               class="pill"
               style="text-decoration:none;padding:6px 12px;border-radius:999px;border:1px solid <?= $on ? '#6366f1' : '#334155' ?>;background:<?= $on ? '#312e81' : 'transparent' ?>;color:<?= $on ? '#e0e7ff' : '#94a3b8' ?>;font-weight:<?= $on ? '600' : '400' ?>;">
                <?= esc(lang('Events.archive.' . $lk)) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($events === []): ?>
        <div class="empty"><?= esc($view === 'archived' ? lang('Events.archive.emptyArchive') : lang('Events.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($events as $e): ?>
            <?php $rowArchived = ($e['archived_at'] ?? null) !== null && (string) $e['archived_at'] !== ''; ?>
            <a class="card" style="display:block;<?= $rowArchived ? 'opacity:0.65;' : '' ?>" href="/events/<?= esc($e['id'] ?? '') ?>">
                <div class="row">
                    <span class="author"><?= esc($e['title'] ?? lang('Events.eventFallback')) ?></span>
                    <span>
                        <?php if ($rowArchived): ?><span class="pill" style="color:#94a3b8;border:1px solid #334155;padding:2px 8px;border-radius:999px;margin-right:6px;"><?= esc(lang('Events.archive.badge')) ?></span><?php endif; ?>
                        <span class="pill" style="color:<?= $col((string) ($e['status'] ?? '')) ?>;"><?= esc($enum('status', (string) ($e['status'] ?? ''))) ?></span>
                    </span>
                </div>
                <div class="counts">
                    <?php if (! empty($e['starts_at'])): ?><?= esc(str_replace('{0}', (string) $e['starts_at'], lang('Events.startsAtUtc'))) ?><?php endif; ?>
                    · <?= esc(lang('Events.capacity')) ?> <?= ($e['capacity'] ?? null) !== null ? (int) $e['capacity'] : esc(lang('Events.unlimited')) ?>
                    <?php if (! empty($e['mode'])): ?> · <?= esc($enum('mode', (string) $e['mode'])) ?><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
