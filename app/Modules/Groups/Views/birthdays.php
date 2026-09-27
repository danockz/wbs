<?= $this->extend('layouts/app') ?>
<?php
/**
 * Birthday hub — month+day only. Never year, never age.
 *
 * @var array{
 *   timezone:string,
 *   today:string,
 *   mine:?array<string,mixed>,
 *   today_list:list<array<string,mixed>>,
 *   peers:list<array<string,mixed>>,
 *   leaders:list<array<string,mixed>>
 * } $hub
 */
$hub     = is_array($hub ?? null) ? $hub : [];
$enabled = (bool) ($hub['enabled'] ?? false);
$mine    = is_array($hub['mine'] ?? null) ? $hub['mine'] : null;
$today   = is_array($hub['today_list'] ?? null) ? $hub['today_list'] : [];
$peers   = is_array($hub['peers'] ?? null) ? $hub['peers'] : [];
$leaders = is_array($hub['leaders'] ?? null) ? $hub['leaders'] : [];
$empty   = $enabled && $mine === null && $today === [] && $peers === [] && $leaders === [];
$t       = static fn (string $k): string => (string) lang('Groups.birthdays.' . $k);

$itemRow = static function (array $it) use ($t): string {
    $name = esc((string) ($it['display_name'] ?? ''));
    $md   = esc((string) ($it['month_day'] ?? ''));
    $days = (int) ($it['days_remaining'] ?? 0);
    $kind = (string) ($it['kind'] ?? '');
    if ($days === 0) {
        $when = $t('today');
    } elseif ($days === 1) {
        $when = $t('inOneDay');
    } else {
        $when = str_replace('{0}', (string) $days, $t('inDays'));
    }
    $count = '';
    if ($kind === 'leader') {
        $cu = (int) ($it['count_up'] ?? 0);
        if ($cu > 0) {
            $win = (int) ($it['window'] ?? 30);
            $count = ' · ' . esc(str_replace(['{0}', '{1}'], [(string) $cu, (string) $win], $t('countUp')));
        }
        $g = trim((string) ($it['group_name'] ?? ''));
        if ($g !== '') {
            $count .= ' · ' . esc($g);
        }
    }

    return '<li class="flex items-baseline justify-between gap-3 py-2 border-b border-[color-mix(in_oklab,var(--color-ink)_8%,transparent)] last:border-0">'
        . '<span><span class="font-medium">' . $name . '</span>'
        . '<span class="text-sm text-[color-mix(in_oklab,var(--color-ink)_55%,transparent)]"> · ' . $md . $count . '</span></span>'
        . '<span class="text-sm tabular-nums">' . esc($when) . '</span></li>';
};
?>

<?= $this->section('content') ?>
<section class="space-y-6">
  <header>
    <h1><?= esc($t('heading')) ?></h1>
    <p class="sub"><?= esc($t('sub')) ?></p>
  </header>

  <?php if (! $enabled): ?>
    <p><?= esc($t('disabledTitle')) ?></p>
    <p class="sub"><?= esc($t('disabledSub')) ?></p>
  <?php elseif ($empty): ?>
    <p><?= esc($t('empty')) ?></p>
  <?php endif; ?>

  <?php if ($mine !== null): ?>
    <section>
      <h2><?= esc($t('mine')) ?></h2>
      <ul><?= $itemRow($mine) ?></ul>
    </section>
  <?php endif; ?>

  <?php if ($today !== []): ?>
    <section>
      <h2><?= esc($t('todayHeading')) ?></h2>
      <ul>
        <?php foreach ($today as $it): ?>
          <?= $itemRow($it) ?>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if ($peers !== []): ?>
    <section>
      <h2><?= esc($t('peersHeading')) ?></h2>
      <ul>
        <?php foreach ($peers as $it): ?>
          <?= $itemRow($it) ?>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if ($leaders !== []): ?>
    <section>
      <h2><?= esc($t('leadersHeading')) ?></h2>
      <ul>
        <?php foreach ($leaders as $it): ?>
          <?= $itemRow($it) ?>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
</section>
<?= $this->endSection() ?>
