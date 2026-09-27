<?php
/**
 * GROUP KINDS catalogue (GET /group-kinds) — the browser face of
 * GroupKindController::index, which otherwise rendered the generic admin console.
 * A kind classifies WHAT a group is (department / activity team / ministry /
 * committee) independently of placement; it never changes access scope. Lists the
 * kinds in sort order, each showing code, name, advisory default placement and
 * status, with an optional colour swatch.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Groups.kinds.*') with English
 * fallback; the {0} count is interpolated via $li(); the placement + status
 * vocabularies are localized with a raw-value fallback. Codes/names are server
 * data shown verbatim & escaped.
 *
 * MANAGEMENT CONSOLE: a create form (new kind: code/name/description/placement/
 * colour/sort order) and a per-row edit link, plus a flashed success/error banner
 * from the previous PRG round-trip. The create form is a no-JS PRG post to a
 * webcsrf-guarded route.
 *
 * @var list<array<string,mixed>> $kinds group-kind rows
 * @var string                    $csrf  webcsrf token for the inline forms
 */
$kinds = $kinds ?? [];
$csrf  = $csrf ?? '';
$count = count($kinds);

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Groups.kinds.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'Groups.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.kinds.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        .item { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:13px 16px; margin-bottom:10px; display:flex; flex-wrap:wrap; gap:8px 12px; align-items:baseline; }


        .swatch { width:12px; height:12px; border-radius:3px; border:1px solid #33415580; display:inline-block; }


        .name { font-weight:600; }


        .edit { margin-inline-start:6px; font-size:.74rem; color:#c4b5fd; text-decoration:underline; }


        .creator { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; margin:0 0 22px; }


        .creator label { display:block; font-size:.76rem; color:#cbd5e1; margin:0 0 4px; }


        .creator input, .creator select { width:100%; background:#0b1120; border:1px solid #334155; border-radius:8px; color:#e2e8f0; padding:8px 10px; font-size:.88rem; }


        .creator input:focus, .creator select:focus { outline:2px solid #7c3aed; border-color:#7c3aed; }


        .creator button { margin-top:12px; border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#7c3aed; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.kinds.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.kinds.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <form class="creator" method="post" action="/group-kinds">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h2><?= esc(lang('Groups.kinds.createHeading')) ?></h2>
            <div class="grid">
                <div>
                    <label for="k-code"><?= esc(lang('Groups.kinds.fCode')) ?></label>
                    <input type="text" id="k-code" name="code" required maxlength="64" placeholder="<?= esc(lang('Groups.kinds.fCodePh'), 'attr') ?>">
                </div>
                <div>
                    <label for="k-name"><?= esc(lang('Groups.kinds.fName')) ?></label>
                    <input type="text" id="k-name" name="name" required maxlength="120" placeholder="<?= esc(lang('Groups.kinds.fNamePh'), 'attr') ?>">
                </div>
                <div>
                    <label for="k-place"><?= esc(lang('Groups.kinds.placementLabel')) ?></label>
                    <select id="k-place" name="default_placement">
                        <option value="either"><?= esc($vocab('placement', 'either')) ?></option>
                        <option value="nested"><?= esc($vocab('placement', 'nested')) ?></option>
                        <option value="crosscut"><?= esc($vocab('placement', 'crosscut')) ?></option>
                    </select>
                </div>
                <div>
                    <label for="k-color"><?= esc(lang('Groups.kinds.fColor')) ?></label>
                    <input type="text" id="k-color" name="color" maxlength="9" placeholder="#7c3aed">
                </div>
                <div>
                    <label for="k-sort"><?= esc(lang('Groups.kinds.fSort')) ?></label>
                    <input type="number" id="k-sort" name="sort_order" value="0" min="0">
                </div>
                <div class="full">
                    <label for="k-desc"><?= esc(lang('Groups.kinds.fDescription')) ?></label>
                    <input type="text" id="k-desc" name="description" maxlength="255" placeholder="<?= esc(lang('Groups.kinds.fDescriptionPh'), 'attr') ?>">
                </div>
            </div>
            <button type="submit"><?= esc(lang('Groups.kinds.createBtn')) ?></button>
        </form>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Groups.kinds.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Groups.kinds.countOne' : 'Groups.kinds.count', (string) $count)) ?></p>
            <?php foreach ($kinds as $k): ?>
                <?php
                $status = strtolower((string) ($k['status'] ?? ''));
                $color  = (string) ($k['color'] ?? '');
                $kid    = (string) ($k['id'] ?? '');
                ?>
                <article class="item">
                    <?php if ($color !== ''): ?><span class="swatch" style="background:<?= esc($color, 'attr') ?>"></span><?php endif; ?>
                    <span class="name"><?= esc((string) ($k['name'] ?? '—')) ?></span>
                    <span class="code"><?= esc((string) ($k['code'] ?? '')) ?></span>
                    <span class="place"><?= esc(lang('Groups.kinds.placementLabel')) ?>: <?= esc($vocab('placement', (string) ($k['default_placement'] ?? ''))) ?></span>
                    <?php $gc = (int) ($k['group_count'] ?? 0); ?>
                    <span class="place" title="<?= esc(lang('Groups.kinds.groupsUsing'), 'attr') ?>"><?= esc(str_replace('{0}', (string) $gc, lang($gc === 1 ? 'Groups.kinds.groupCountOne' : 'Groups.kinds.groupCount'))) ?></span>
                    <span class="st <?= esc($status, 'attr') ?>"><?= esc($vocab('status', $status)) ?></span>
                    <?php if ($kid !== ''): ?><a class="edit" href="/group-kinds/<?= esc(rawurlencode($kid), 'attr') ?>"><?= esc(lang('Groups.kinds.editLink')) ?></a><?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
