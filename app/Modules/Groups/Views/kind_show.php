<?php
/**
 * GROUP KIND detail (GET /group-kinds/{id}) — the browser face of
 * GroupKindController::show, which otherwise rendered the generic admin console.
 * Shows one kind in full: code, name, description, advisory default placement,
 * sort order and status, with the colour/icon if set. A kind classifies WHAT a
 * group is; it never changes access scope (the advisory placement is descriptive
 * only). When not found (Result::notFound) a localized not-found panel shows.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Groups.kindShow.*') with English
 * fallback; the placement + status vocabularies are localized with a raw-value
 * fallback. Code/name/description are server data shown verbatim & escaped.
 *
 * MANAGEMENT CONSOLE: when found, an edit form updates name/description/placement/
 * colour/sort order/status (the code is immutable). It is a no-JS PRG post to the
 * kind's webcsrf-guarded update route; a flashed success/error banner from the
 * previous round-trip shows at the top.
 *
 * @var array<string,mixed>|null $kind   group-kind row, or null if not found
 * @var string                   $kindId the kind id (for the update route)
 * @var string                   $csrf   webcsrf token for the edit form
 */
$kind   = $kind ?? null;
$kindId = $kindId ?? '';
$csrf   = $csrf ?? '';
$found  = is_array($kind);
$sidAttr = $kindId !== '' ? rawurlencode($kindId) : '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Groups.kindShow.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'Groups.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.kindShow.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 720px; margin: 0 auto; padding: 5vh 20px 60px; }


        .swatch { width:16px; height:16px; border-radius:4px; border:1px solid #33415580; display:inline-block; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:16px; }


        .editor { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:16px; }


        .editor label { display:block; font-size:.76rem; color:#cbd5e1; margin:0 0 4px; }


        .editor input, .editor select { width:100%; background:#0b1120; border:1px solid #334155; border-radius:8px; color:#e2e8f0; padding:8px 10px; font-size:.88rem; }


        .editor input:focus, .editor select:focus { outline:2px solid #7c3aed; border-color:#7c3aed; }


        .editor button { margin-top:12px; border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#7c3aed; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if (! $found): ?>
            <h1><?= esc(lang('Groups.kindShow.heading')) ?></h1>
            <p class="notfound"><?= esc(lang('Groups.kindShow.notFound')) ?></p>
        <?php else: ?>
            <?php
            $status = strtolower((string) ($kind['status'] ?? ''));
            $color  = (string) ($kind['color'] ?? '');
            ?>
            <h1>
                <?php if ($color !== ''): ?><span class="swatch" style="background:<?= esc($color, 'attr') ?>"></span><?php endif; ?>
                <?= esc((string) ($kind['name'] ?? '')) ?> <span class="code"><?= esc((string) ($kind['code'] ?? '')) ?></span>
            </h1>
            <div class="card">
                <div class="row"><span class="k"><?= esc(lang('Groups.kindShow.colCode')) ?></span><span class="v code"><?= esc((string) ($kind['code'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Groups.kindShow.colName')) ?></span><span class="v"><?= esc((string) ($kind['name'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Groups.kindShow.colDescription')) ?></span><span class="v"><?= esc((string) ($kind['description'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Groups.kindShow.colPlacement')) ?></span><span class="v"><?= esc($vocab('placement', (string) ($kind['default_placement'] ?? ''))) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Groups.kindShow.colSortOrder')) ?></span><span class="v"><?= esc((string) ($kind['sort_order'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Groups.kindShow.colStatus')) ?></span><span class="v"><span class="st <?= esc($status, 'attr') ?>"><?= esc($vocab('status', $status)) ?></span></span></div>
            </div>
            <?php
            $kindGroups = is_array($kind['groups'] ?? null) ? $kind['groups'] : [];
            $groupCount = (int) ($kind['group_count'] ?? count($kindGroups));
            ?>
            <div class="card">
                <div class="row" style="border-bottom:1px solid #16233a;">
                    <span class="k"><?= esc(lang('Groups.kindShow.groupsHeading')) ?></span>
                    <span class="v"><?= esc(str_replace('{0}', (string) $groupCount, lang($groupCount === 1 ? 'Groups.kinds.groupCountOne' : 'Groups.kinds.groupCount'))) ?></span>
                </div>
                <?php if ($kindGroups === []): ?>
                    <div class="row" style="border-bottom:0;"><span class="v" style="color:#94a3b8;font-style:italic;"><?= esc(lang('Groups.kindShow.groupsEmpty')) ?></span></div>
                <?php else: ?>
                    <?php foreach ($kindGroups as $g): ?>
                        <?php $g = (array) $g; $depth = max(0, (int) ($g['depth'] ?? 1) - 1); ?>
                        <div class="row" style="border-bottom:0;padding:5px 0;">
                            <span class="v" style="padding-inline-start:<?= min(8, $depth) * 16 ?>px;">
                                <a href="/groups/<?= esc(rawurlencode((string) ($g['id'] ?? '')), 'attr') ?>" style="color:#c4b5fd;text-decoration:none;"><?= esc((string) ($g['name'] ?? '')) ?></a>
                                <?php if (! empty($g['type'])): ?><span style="color:#64748b;font-size:.8rem;"> · <?= esc((string) $g['type']) ?></span><?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <p class="sub" style="color:#64748b;font-size:.82rem;margin-top:12px"><?= esc(lang('Groups.kindShow.scopeNote')) ?></p>

            <?php if ($kindId !== ''): ?>
                <?php
                $place = strtolower((string) ($kind['default_placement'] ?? 'either'));
                $sel   = static fn (string $v): string => $place === $v ? ' selected' : '';
                ?>
                <form class="editor" method="post" action="/group-kinds/<?= esc($sidAttr, 'attr') ?>">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <h2><?= esc(lang('Groups.kindShow.editHeading')) ?></h2>
                    <div class="grid">
                        <div>
                            <label for="e-name"><?= esc(lang('Groups.kindShow.colName')) ?></label>
                            <input type="text" id="e-name" name="name" required maxlength="120" value="<?= esc((string) ($kind['name'] ?? ''), 'attr') ?>">
                        </div>
                        <div>
                            <label for="e-place"><?= esc(lang('Groups.kindShow.colPlacement')) ?></label>
                            <select id="e-place" name="default_placement">
                                <option value="either"<?= $sel('either') ?>><?= esc($vocab('placement', 'either')) ?></option>
                                <option value="nested"<?= $sel('nested') ?>><?= esc($vocab('placement', 'nested')) ?></option>
                                <option value="crosscut"<?= $sel('crosscut') ?>><?= esc($vocab('placement', 'crosscut')) ?></option>
                            </select>
                        </div>
                        <div>
                            <label for="e-color"><?= esc(lang('Groups.kinds.fColor')) ?></label>
                            <input type="text" id="e-color" name="color" maxlength="9" value="<?= esc((string) ($kind['color'] ?? ''), 'attr') ?>" placeholder="#7c3aed">
                        </div>
                        <div>
                            <label for="e-sort"><?= esc(lang('Groups.kindShow.colSortOrder')) ?></label>
                            <input type="number" id="e-sort" name="sort_order" min="0" value="<?= esc((string) ($kind['sort_order'] ?? 0), 'attr') ?>">
                        </div>
                        <div>
                            <label for="e-status"><?= esc(lang('Groups.kindShow.colStatus')) ?></label>
                            <select id="e-status" name="status">
                                <option value="active"<?= $status === 'active' ? ' selected' : '' ?>><?= esc($vocab('status', 'active')) ?></option>
                                <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>><?= esc($vocab('status', 'inactive')) ?></option>
                            </select>
                        </div>
                        <div class="full">
                            <label for="e-desc"><?= esc(lang('Groups.kindShow.colDescription')) ?></label>
                            <input type="text" id="e-desc" name="description" maxlength="255" value="<?= esc((string) ($kind['description'] ?? ''), 'attr') ?>">
                        </div>
                    </div>
                    <button type="submit"><?= esc(lang('Groups.kindShow.saveBtn')) ?></button>
                </form>
                <a class="back" href="/group-kinds"><?= esc(lang('Groups.kindShow.backLink')) ?></a>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
