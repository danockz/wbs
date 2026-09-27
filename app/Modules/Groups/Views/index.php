<?= $this->extend('layouts/app') ?>

<?php
/**
 * Group hierarchy browser (GET /groups) — two organizations of the SAME groups,
 * switchable with the toggle:
 *   - by=hierarchy (default): pre-order org tree (National → … → Cell), indented
 *     by depth, each row showing its classification (kind) badge, with per-node
 *     actions: open (detail), edit core fields, and — for an empty leaf — delete.
 *   - by=location: the same groups nested by their Geo chain
 *     (Country → State/Region → City), each group linking to its detail page.
 *
 * The hierarchy list arrives path-ordered from GroupService::listForOrg (a
 * pre-order walk), so a depth-based indent renders the tree. A leaf (no group
 * has it as parent) may be hard-deleted; the guarded service re-checks
 * members/children/cross-cuts, so the delete control is a UX hint only.
 *
 * UI copy is localized via lang('Groups.index.*') / lang('Groups.directory.*')
 * with English fallback; group names/types/kind labels are data shown verbatim.
 *
 * @var array<string,mixed> $result {groups:[...]} | {sections:[...]}
 * @var string              $by     'hierarchy' | 'location'
 * @var string              $title
 * @var string              $csrf   webcsrf token for the delete PRG forms
 */
$by       = ($by ?? 'hierarchy') === 'location' ? 'location' : 'hierarchy';
$groups   = $result['groups'] ?? [];
$sections = $result['sections'] ?? [];
$csrf     = $csrf ?? '';

// Which ids are parents? Used to show delete only on leaves (hierarchy view).
$parentIds = [];
foreach ($groups as $g) {
    $pid = (string) ($g['parent_id'] ?? '');
    if ($pid !== '') {
        $parentIds[$pid] = true;
    }
}

$count = $by === 'location'
    ? (int) ($result['count'] ?? array_sum(array_map(static fn ($s) => (int) ($s['count'] ?? 0), $sections)))
    : count($groups);

// Geo node label with localized fallback for the two sentinel levels.
$geoLabel = static function (array $node): string {
    $label = trim((string) ($node['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return (string) (($node['level'] ?? '') === 'country'
        ? lang('Groups.directory.unlocated')
        : lang('Groups.directory.unspecified'));
};

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Groups.index.heading')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, lang('Groups.index.count'))) ?></div>

    <?php if ($flashOk !== ''): ?><div class="card" style="border-color:#166534;color:#86efac"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="card" style="border-color:#7f1d1d;color:#fca5a5"><?= esc($flashErr) ?></div><?php endif; ?>

    <div style="margin:14px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a class="pill" href="/groups/create"><?= esc(lang('Groups.index.createBtn')) ?></a>
        <a class="pill" href="/groups/move"><?= esc(lang('Groups.index.moveBtn')) ?></a>
        <span style="flex:1"></span>
        <span class="muted" style="font-size:.82rem;"><?= esc(lang('Groups.index.viewBy')) ?></span>
        <a class="pill" href="/groups?by=hierarchy" style="<?= $by === 'hierarchy' ? 'background:#1d4ed8;color:#fff;' : '' ?>"><?= esc(lang('Groups.index.byHierarchy')) ?></a>
        <a class="pill" href="/groups?by=location" style="<?= $by === 'location' ? 'background:#0891b2;color:#fff;' : '' ?>"><?= esc(lang('Groups.index.byLocation')) ?></a>
    </div>

    <?php if ($by === 'location'): ?>
        <?php if ($sections === []): ?>
            <div class="empty"><?= esc(lang('Groups.index.empty')) ?></div>
        <?php else: ?>
            <?php foreach ($sections as $c): ?>
                <h2 style="margin-top:22px;">🌍 <?= esc($geoLabel($c)) ?> <span class="muted" style="font-size:.8rem;">· <?= (int) ($c['count'] ?? 0) ?></span></h2>
                <?php foreach (($c['children'] ?? []) as $st): ?>
                    <?php $stLabel = $geoLabel($st); ?>
                    <?php if (trim((string) ($st['label'] ?? '')) !== '' || count($c['children']) > 1): ?>
                        <div class="muted" style="margin:8px 0 4px;padding-inline-start:8px;border-inline-start:3px solid #1e40af;">📍 <?= esc($stLabel) ?></div>
                    <?php endif; ?>
                    <?php foreach (($st['children'] ?? []) as $ci): ?>
                        <?php if (trim((string) ($ci['label'] ?? '')) !== ''): ?>
                            <div class="muted" style="margin:6px 0 2px;padding-inline-start:20px;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;"><?= esc($geoLabel($ci)) ?></div>
                        <?php endif; ?>
                        <?php foreach (($ci['groups'] ?? []) as $g): ?>
                            <?php $g = (array) $g; $sid = rawurlencode((string) ($g['id'] ?? '')); ?>
                            <div class="card" style="margin-inline-start:20px;">
                                <div class="row">
                                    <span class="author">
                                        <a href="/groups/<?= esc($sid, 'attr') ?>" style="color:inherit;text-decoration:none;"><?= esc((string) ($g['name'] ?? '')) ?></a>
                                        <?php if (! empty($g['kind_name'])): ?><span class="pill" style="font-size:.7rem;margin-inline-start:6px;"><?= esc((string) $g['kind_name']) ?></span><?php endif; ?>
                                        <?php if (! empty($g['type'])): ?><span class="muted" style="font-size:.78rem;"> · <?= esc((string) $g['type']) ?></span><?php endif; ?>
                                    </span>
                                    <span class="time"><a href="/groups/<?= esc($sid, 'attr') ?>/edit"><?= esc(lang('Groups.index.edit')) ?></a></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($groups === []): ?>
            <div class="empty"><?= esc(lang('Groups.index.empty')) ?></div>
        <?php else: ?>
            <?php foreach ($groups as $g): ?>
                <?php
                $g      = (array) $g;
                $gid    = (string) ($g['id'] ?? '');
                $sid    = rawurlencode($gid);
                $name   = trim((string) ($g['name'] ?? '')) !== '' ? (string) $g['name'] : $gid;
                $depth  = max(0, (int) ($g['depth'] ?? 1) - 1);
                $indent = min(8, $depth) * 18;
                $type   = (string) ($g['type'] ?? '');
                $kind   = (string) ($g['kind_name'] ?? '');
                $isLeaf = ! isset($parentIds[$gid]);
                ?>
                <div class="card">
                    <div class="row">
                        <span class="author" style="padding-left:<?= $indent ?>px;">
                            <a href="/groups/<?= esc($sid, 'attr') ?>" style="color:inherit;text-decoration:none;"><?= esc($name) ?></a>
                            <?php if ($kind !== ''): ?><span class="pill" style="font-size:.7rem;margin-inline-start:6px;"><?= esc($kind) ?></span><?php endif; ?>
                            <?php if ($type !== ''): ?><span class="muted" style="font-size:.8rem;">· <?= esc($type) ?></span><?php endif; ?>
                        </span>
                        <span class="time" style="display:flex;gap:10px;align-items:center;">
                            <a href="/groups/<?= esc($sid, 'attr') ?>/edit"><?= esc(lang('Groups.index.edit')) ?></a>
                            <a href="/groups/<?= esc($sid, 'attr') ?>"><?= esc(lang('Groups.index.open')) ?></a>
                            <?php if ($isLeaf): ?>
                                <form method="post" action="/groups/<?= esc($sid, 'attr') ?>/delete" style="display:inline;"
                                      onsubmit="return confirm('<?= esc(lang('Groups.index.deleteConfirm'), 'attr') ?>');">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <button type="submit" style="background:none;border:0;color:#fca5a5;cursor:pointer;padding:0;font:inherit;"><?= esc(lang('Groups.index.delete')) ?></button>
                                </form>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
