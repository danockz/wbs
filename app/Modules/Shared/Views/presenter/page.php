<?php

declare(strict_types=1);

/**
 * SHARED LOCALE-AWARE PAGE PRESENTER (WBS\Shared\Views\presenter\page).
 *
 * The single, self-contained rendering shell that turns a declarative $page
 * spec into a full, house-style HTML page — the SAME chrome the hand-written
 * bespoke views use (dark theme, cards, tables, chips, empty states, write
 * forms), but driven by data so dozens of endpoints that previously fell
 * through to the generic data_page.php now get a real, translated page each.
 *
 * SELF-CONTAINED: renders its own <html lang dir> (RTL-correct for Arabic) via
 * the sibling _locale.php. EVERY user-facing string flows through lang() — the
 * per-view title/heading/sub live under Pages.views.<id>.*, shared column and
 * chrome labels under Pages.common.*, both with humanized fallbacks so a missing
 * key degrades gracefully instead of leaking a raw "Pages.x" token. No external
 * assets (CSP-safe). No JS. Write forms post name="_csrf" to guarded routes.
 *
 * @var array<string,mixed> $page  The declarative spec (see keys consumed below).
 * @var string              $csrf  Web CSRF token for any write forms.
 */

$page = $page ?? [];
$csrf = $csrf ?? ($page['csrf'] ?? '');

require __DIR__ . '/_locale.php';

$id     = (string) ($page['id'] ?? 'generic');
$accent = (string) ($page['accent'] ?? '#38bdf8');
$base   = 'Pages.views.' . $id . '.';

/** lang() with an explicit fallback that never leaks the raw key. */
$T = static function (string $key, string $fallback = ''): string {
    $v = lang($key);

    return (is_string($v) && $v !== $key) ? $v : $fallback;
};
/** Resolve a label given either a full lang key or a Pages.common.<k> short key. */
$L = static function (?string $key, string $fallback = '') use ($T): string {
    if ($key === null || $key === '') {
        return $fallback;
    }
    $full = str_contains($key, '.') ? $key : ('Pages.common.' . $key);

    return $T($full, $fallback !== '' ? $fallback : ucwords(str_replace(['_', '-'], ' ', $key)));
};
$humanize = static fn (string $s): string => ucwords(str_replace(['_', '-'], ' ', $s));
$esc      = static fn ($s, string $c = 'html'): string => function_exists('esc')
    ? (string) esc((string) $s, $c)
    : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$url = static function (string $path) use ($esc): string {
    $u = function_exists('base_url') ? (string) base_url($path) : ('/' . ltrim($path, '/'));

    return $esc($u, 'attr');
};
$fmtDate = static function (mixed $raw): string {
    $raw = (string) ($raw ?? '');
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);

    return $ts === false ? $raw : date('Y-m-d H:i', $ts);
};
/** Dot-path getter over a row: 'a.b' => $row['a']['b']. */
$dig = static function (array $row, string $path) {
    $cur = $row;
    foreach (explode('.', $path) as $seg) {
        if (! is_array($cur) || ! array_key_exists($seg, $cur)) {
            return null;
        }
        $cur = $cur[$seg];
    }

    return $cur;
};

$title   = $T($base . 'title', $T($base . 'heading', $humanize($id)));
$heading = $T($base . 'heading', $title);
$sub     = $T($base . 'sub', '');
// A page may supply a runtime sub-caption (already localized) that overrides the
// static Pages.views.<id>.sub — e.g. a caption that depends on request state.
if (isset($page['subOverride']) && is_string($page['subOverride']) && $page['subOverride'] !== '') {
    $sub = $page['subOverride'];
}
$rows    = is_array($page['rows'] ?? null) ? $page['rows'] : [];
$columns = is_array($page['columns'] ?? null) ? $page['columns'] : [];
$forms   = is_array($page['forms'] ?? null) ? $page['forms'] : [];
$facts   = is_array($page['facts'] ?? null) ? $page['facts'] : [];
$count   = $page['count'] ?? (($rows !== []) ? count($rows) : null);
$emptyTx = $T($base . 'empty', $L('Pages.common.empty', 'Nothing to show yet.'));

$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;

/** Chip color for a fixed vocabulary; falls back to a neutral slate. */
$chipColor = static function (string $val, array $map): string {
    return $map[$val] ?? ($map['_default'] ?? '#94a3b8');
};

/** Render one cell per its column spec. */
$cell = function (array $col, array $row) use ($dig, $esc, $L, $fmtDate, $chipColor, $humanize, $url): string {
    $type = (string) ($col['type'] ?? 'text');
    $raw  = $dig($row, (string) ($col['key'] ?? ''));

    if ($type === 'num') {
        return $raw === null || $raw === '' ? '<span class="muted">—</span>' : $esc((string) $raw);
    }
    if ($type === 'date') {
        $d = $fmtDate($raw);

        return $d === '' ? '<span class="muted">—</span>' : $esc($d);
    }
    if ($type === 'bool') {
        $on = ! empty($raw);
        $lbl = $on ? $L('Pages.common.yes', 'Yes') : $L('Pages.common.no', 'No');
        $c   = $on ? '#22c55e' : '#94a3b8';

        return '<span class="chip" style="color:' . $c . ';border-color:' . $c . '55">' . $esc($lbl) . '</span>';
    }
    if ($type === 'code') {
        return $raw === null || $raw === '' ? '<span class="muted">—</span>' : '<code>' . $esc((string) $raw) . '</code>';
    }
    if ($type === 'chip') {
        $val = (string) ($raw ?? '');
        if ($val === '') {
            return '<span class="muted">—</span>';
        }
        // Localized label: Pages.common.vocab.<val> then humanized fallback.
        $lbl = $L('Pages.common.vocab.' . $val, $humanize($val));
        $c   = $chipColor($val, (array) ($col['colors'] ?? []));

        return '<span class="chip" style="color:' . $esc($c, 'attr') . ';border-color:' . $esc($c, 'attr') . '55">' . $esc($lbl) . '</span>';
    }
    if ($type === 'link') {
        $val = (string) ($raw ?? '');
        if ($val === '') {
            return '<span class="muted">—</span>';
        }
        $tpl  = (string) ($col['href'] ?? '#');
        $href = str_replace('{id}', rawurlencode($val), $tpl);
        $text = $col['textKey'] ?? null;
        $show = $text !== null ? $L($text) : $val;

        return '<a class="dl" href="' . $url($href) . '">' . $esc($show) . '</a>';
    }
    // strong (primary label), optionally a link to the row's own detail page
    // when the column declares rowHref '…/{id}' and an idKey to read from the row.
    if ($type === 'strong') {
        if ($raw === null || $raw === '') {
            return '<span class="muted">—</span>';
        }
        $rowHref = (string) ($col['rowHref'] ?? '');
        if ($rowHref !== '') {
            $idVal = (string) ($dig($row, (string) ($col['idKey'] ?? 'id')) ?? '');
            if ($idVal !== '') {
                $href = str_replace('{id}', rawurlencode($idVal), $rowHref);

                return '<a class="dl report" href="' . $url($href) . '">' . $esc((string) $raw) . '</a>';
            }
        }

        return '<span class="report">' . $esc((string) $raw) . '</span>';
    }

    // default text
    return $raw === null || $raw === '' ? '<span class="muted">—</span>' : $esc((string) $raw);
};
?>

<?php ob_start(); ?>
<?= $esc($title) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php include __DIR__ . '/../_shell_open.php'; ?>

<main class="wrap" style="--wbs-accent: <?= $esc($accent, 'attr') ?>">
        <?php if (! empty($page['back'])): ?>
            <a class="backlink" href="<?= $url((string) $page['back']) ?>">&larr; <?= $esc($L('Pages.common.back', 'Back')) ?></a>
        <?php endif; ?>
        <?php
        // Optional header CTA linking to a bespoke capture/create form (declared
        // by the spec's newHref/newLabelKey). Kept a plain link — the target page
        // owns the actual write form + CSRF.
        $newHref  = (string) ($page['newHref'] ?? '');
        $newLabel = $newHref !== '' ? $L((string) ($page['newLabelKey'] ?? 'Pages.common.new'), 'New') : '';
        ?>
        <div class="pagehead">
            <h1><?= $esc($heading) ?></h1>
            <?php if ($newHref !== ''): ?>
                <a class="cta" href="<?= $url($newHref) ?>"><?= $esc($newLabel) ?></a>
            <?php endif; ?>
        </div>
        <?php if ($sub !== ''): ?><p class="sub"><?= $esc($sub) ?></p><?php endif; ?>

        <?php if ($flashOk !== null && $flashOk !== ''): ?>
            <div class="flash ok"><?= $esc($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?>
            <div class="flash err"><?= $esc($flashErr) ?></div>
        <?php endif; ?>

        <?php
        // Optional declarative FILTER bar: a plain GET form of <select>/<input>
        // controls that reload the same list with query-string filters (the
        // controller reads them and narrows the service call). Read-only, no CSRF.
        // Current values come from $page['filterValues'] so selections persist.
        $filters      = is_array($page['filters'] ?? null) ? $page['filters'] : [];
        $filterValues = is_array($page['filterValues'] ?? null) ? $page['filterValues'] : [];
        ?>
        <?php if ($filters !== []): ?>
            <form method="get" class="filterbar">
                <?php foreach ($filters as $flt): ?>
                    <?php
                    $fname = (string) ($flt['name'] ?? '');
                    if ($fname === '') { continue; }
                    $fcur  = (string) ($filterValues[$fname] ?? '');
                    $flbl  = $L((string) ($flt['labelKey'] ?? ''), (string) ($flt['label'] ?? $fname));
                    $opts  = is_array($flt['options'] ?? null) ? $flt['options'] : [];
                    ?>
                    <div class="fltfld">
                        <label for="flt_<?= $esc($fname, 'attr') ?>"><?= $esc($flbl) ?></label>
                        <?php if ($opts !== []): ?>
                            <select id="flt_<?= $esc($fname, 'attr') ?>" name="<?= $esc($fname, 'attr') ?>">
                                <option value="">— <?= $esc($L((string) ($flt['anyLabelKey'] ?? ''), (string) ($flt['anyLabel'] ?? 'Any'))) ?> —</option>
                                <?php foreach ($opts as $opt): ?>
                                    <?php $ov = (string) ($opt['value'] ?? ''); ?>
                                    <option value="<?= $esc($ov, 'attr') ?>"<?= $fcur === $ov && $fcur !== '' ? ' selected' : '' ?>><?= $esc($L((string) ($opt['labelKey'] ?? ''), (string) ($opt['label'] ?? $ov))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input id="flt_<?= $esc($fname, 'attr') ?>" name="<?= $esc($fname, 'attr') ?>" value="<?= $esc($fcur, 'attr') ?>" placeholder="<?= $esc((string) ($flt['placeholder'] ?? ''), 'attr') ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php foreach ((array) ($page['filterHidden'] ?? []) as $hk => $hv): ?>
                    <input type="hidden" name="<?= $esc((string) $hk, 'attr') ?>" value="<?= $esc((string) $hv, 'attr') ?>">
                <?php endforeach; ?>
                <div class="fltfld">
                    <button class="btn" type="submit"><?= $esc($L('Pages.common.applyFilters', 'Apply')) ?></button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($facts !== []): ?>
            <div class="facts">
                <?php foreach ($facts as $f): ?>
                    <div class="fact">
                        <span class="k"><?= $esc($L((string) ($f['labelKey'] ?? ''), (string) ($f['label'] ?? ''))) ?></span>
                        <span class="v"><?= $esc((string) ($f['value'] ?? '—')) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($forms as $form): ?>
            <?php
            $fields    = is_array($form['fields'] ?? null) ? $form['fields'] : [];
            $summary   = $L((string) ($form['summaryKey'] ?? ''), (string) ($form['summary'] ?? 'Form'));
            $submitLbl = $L((string) ($form['submitKey'] ?? ''), (string) ($form['submit'] ?? 'Submit'));
            ?>
            <details class="card"<?= ! empty($form['open']) ? ' open' : '' ?>>
                <summary><?= $esc($summary) ?></summary>
                <form class="form" method="post" action="<?= $url((string) ($form['action'] ?? '#')) ?>">
                    <input type="hidden" name="_csrf" value="<?= $esc($csrf, 'attr') ?>">
                    <?php foreach ($fields as $fld): ?>
                        <?php
                        $ftype = (string) ($fld['type'] ?? 'text');
                        $fname = (string) ($fld['name'] ?? '');
                        $flbl  = $L((string) ($fld['labelKey'] ?? ''), (string) ($fld['label'] ?? $humanize($fname)));
                        $req   = ! empty($fld['required']) ? ' required' : '';
                        $full  = ! empty($fld['full']) ? ' full' : '';
                        // Declarative HTML5 validation/UX attributes (audit §4):
                        // maxlength, pattern, min, max, step, inputmode, placeholder,
                        // autocomplete. Rendered when present so capture is validated
                        // client-side with zero JS.
                        $attr = '';
                        foreach (['maxlength' => 'maxlength', 'minlength' => 'minlength', 'pattern' => 'pattern',
                                  'min' => 'min', 'max' => 'max', 'step' => 'step', 'inputmode' => 'inputmode',
                                  'placeholder' => 'placeholder', 'autocomplete' => 'autocomplete'] as $k => $htmlAttr) {
                            if (isset($fld[$k]) && $fld[$k] !== '') {
                                $attr .= ' ' . $htmlAttr . '="' . $esc((string) $fld[$k], 'attr') . '"';
                            }
                        }
                        ?>
                        <?php if ($ftype === 'checkbox'): ?>
                            <div class="chk<?= $full ?>">
                                <input type="checkbox" id="f_<?= $esc($fname, 'attr') ?>" name="<?= $esc($fname, 'attr') ?>" value="1">
                                <label for="f_<?= $esc($fname, 'attr') ?>"><?= $esc($flbl) ?></label>
                            </div>
                        <?php else: ?>
                            <div class="<?= trim($full) ?>">
                                <label for="f_<?= $esc($fname, 'attr') ?>"><?= $esc($flbl) ?></label>
                                <?php if ($ftype === 'select'): ?>
                                    <select id="f_<?= $esc($fname, 'attr') ?>" name="<?= $esc($fname, 'attr') ?>"<?= $req ?>>
                                        <?php foreach ((array) ($fld['options'] ?? []) as $opt): ?>
                                            <?php
                                            $ov = is_array($opt) ? (string) ($opt['value'] ?? '') : (string) $opt;
                                            $ol = is_array($opt)
                                                ? $L((string) ($opt['labelKey'] ?? ''), (string) ($opt['label'] ?? $humanize($ov)))
                                                : $humanize((string) $opt);
                                            ?>
                                            <option value="<?= $esc($ov, 'attr') ?>"><?= $esc($ol) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($ftype === 'textarea'): ?>
                                    <textarea id="f_<?= $esc($fname, 'attr') ?>" name="<?= $esc($fname, 'attr') ?>" rows="3"<?= $req ?><?= $attr ?>></textarea>
                                <?php else: ?>
                                    <input type="<?= $esc($ftype, 'attr') ?>" id="f_<?= $esc($fname, 'attr') ?>" name="<?= $esc($fname, 'attr') ?>"<?= $req ?><?= $attr ?>>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="full">
                        <button class="btn" type="submit"><?= $esc($submitLbl) ?></button>
                    </div>
                </form>
            </details>
        <?php endforeach; ?>

        <?php
        // Single-record DETAIL mode: render a key/value card from $page['record']
        // using the declared $page['detail'] field specs (localized labels).
        $record = is_array($page['record'] ?? null) ? $page['record'] : null;
        $detail = is_array($page['detail'] ?? null) ? $page['detail'] : [];
        ?>
        <?php if ($record !== null && $detail !== []): ?>
            <div class="card" style="padding:6px 16px;">
                <?php foreach ($detail as $col): ?>
                    <?php $col = is_array($col) ? $col : ['key' => (string) $col]; ?>
                    <div class="row" style="display:flex;gap:16px;padding:10px 0;border-bottom:1px solid #101a2e;align-items:flex-start;">
                        <span style="min-width:180px;color:#64748b;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;padding-top:3px;"><?= $esc($L((string) ($col['labelKey'] ?? ''), (string) ($col['label'] ?? $humanize((string) ($col['key'] ?? ''))))) ?></span>
                        <span style="flex:1;word-break:break-word;"><?= $cell($col, $record) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
            // Detail-level action buttons: each POSTs to a webcsrf-guarded route
            // (state transitions / deletes) and carries the CSRF token. '{id}' in
            // the action is filled from the record's id field.
            $detailActions = is_array($page['detailActions'] ?? null) ? $page['detailActions'] : [];
            $recId         = (string) ($dig($record, (string) ($page['recordIdKey'] ?? 'id')) ?? '');
            ?>
            <?php if ($detailActions !== [] && $recId !== ''): ?>
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin:16px 0 22px;">
                    <?php foreach ($detailActions as $act): ?>
                        <?php
                        $actUrl   = str_replace('{id}', rawurlencode($recId), (string) ($act['action'] ?? '#'));
                        $actLbl   = $L((string) ($act['labelKey'] ?? ''), (string) ($act['label'] ?? 'Go'));
                        $danger   = ! empty($act['danger']);
                        ?>
                        <form class="inline" method="post" action="<?= $url($actUrl) ?>">
                            <input type="hidden" name="_csrf" value="<?= $esc($csrf, 'attr') ?>">
                            <?php foreach ((array) ($act['hidden'] ?? []) as $hk => $hv): ?>
                                <input type="hidden" name="<?= $esc((string) $hk, 'attr') ?>" value="<?= $esc((string) $hv, 'attr') ?>">
                            <?php endforeach; ?>
                            <button class="<?= $danger ? 'rowbtn' : 'btn' ?>" type="submit"><?= $esc($actLbl) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php
            // Detail-level GET links (e.g. an "Edit" link to a bespoke config
            // form). Unlike detailActions these are plain anchors — the target
            // page owns the write form + CSRF. '{id}' is filled from the record.
            $detailLinks = is_array($page['detailLinks'] ?? null) ? $page['detailLinks'] : [];
            ?>
            <?php if ($detailLinks !== [] && $recId !== ''): ?>
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin:16px 0 22px;">
                    <?php foreach ($detailLinks as $lnk): ?>
                        <?php
                        $lnkUrl = str_replace('{id}', rawurlencode($recId), (string) ($lnk['href'] ?? '#'));
                        $lnkLbl = $L((string) ($lnk['labelKey'] ?? ''), (string) ($lnk['label'] ?? 'Open'));
                        ?>
                        <a class="btn" href="<?= $url($lnkUrl) ?>"><?= $esc($lnkLbl) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($record !== null && $detail !== []): ?>
            <?php /* detail already rendered above; nothing more to show */ ?>
        <?php elseif ($rows === [] && $columns !== []): ?>
            <p class="empty"><?= $esc($emptyTx) ?></p>
        <?php elseif ($columns !== []): ?>
            <?php if ($count !== null): ?>
                <p class="count"><?php
                    $one = ((int) $count) === 1;
                    $ck  = $base . ($one ? 'countOne' : 'count');
                    $ct  = $T($ck, '');
                    if ($ct === '') {
                        $ck = 'Pages.common.' . ($one ? 'countOne' : 'count');
                        $ct = $T($ck, '{0}');
                    }
                    echo $esc(str_replace('{0}', (string) $count, $ct));
                ?></p>
            <?php endif; ?>
            <?php
            // Progressive enhancement opt-in (audit §5): when the spec sets
            // 'enhance' => true, wrap the table so /assets/js/table-enhance.js can
            // add instant client-side sort + filter. The table is fully usable
            // without JS; this only adds behaviour when JS is present.
            $enhance = ! empty($page['enhance']);
            // Map presentation column type → a stable sort type for the client.
            $sortType = static function (string $t): string {
                if (in_array($t, ['num'], true)) {
                    return 'num';
                }
                if (in_array($t, ['date'], true)) {
                    return 'date';
                }

                return 'text';
            };
            ?>
            <?php if ($enhance): ?>
            <div class="tbl-tools" data-enhance="table" data-filter-min="8"
                 data-filter-label="<?= $esc($L('Pages.common.filter', 'Filter…'), 'attr') ?>"
                 data-export="<?= $esc($L('Pages.common.exportCsv', 'Export CSV'), 'attr') ?>"
                 data-export-name="<?= $esc((string) ($page['id'] ?? 'export'), 'attr') ?>"
                 data-copy="<?= $esc($L('Pages.common.copy', 'Copy'), 'attr') ?>"
                 data-copy-done="<?= $esc($L('Pages.common.copied', 'Copied'), 'attr') ?>"
                 data-columns="<?= $esc($L('Pages.common.columns', 'Columns'), 'attr') ?>"
                 data-density="<?= $esc($L('Pages.common.density', 'Compact'), 'attr') ?>">
            <?php endif; ?>
            <table>
                <thead><tr>
                    <?php foreach ($columns as $col): ?>
                        <?php
                        $cType = (string) ($col['type'] ?? '');
                        $isNum = $cType === 'num';
                        ?>
                        <th<?= $isNum ? ' class="num"' : '' ?><?= $enhance ? ' data-sort="' . $esc($sortType($cType), 'attr') . '"' : '' ?>><?= $esc($L((string) ($col['labelKey'] ?? ''), (string) ($col['label'] ?? $humanize((string) ($col['key'] ?? ''))))) ?></th>
                    <?php endforeach; ?>
                    <?php if (! empty($page['rowActions'])): ?>
                        <th<?= $enhance ? ' data-no-export' : '' ?>><?= $esc($L('Pages.common.colActions', 'Actions')) ?></th>
                    <?php endif; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $row = is_array($row) ? $row : ['value' => $row]; ?>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                                <?php
                                $cType = (string) ($col['type'] ?? '');
                                $isNum = $cType === 'num';
                                // Stable, locale-free sort key from the raw value so
                                // the client sorts on data, not formatted display text.
                                $sortAttr = '';
                                if ($enhance) {
                                    $rawv = $dig($row, (string) ($col['key'] ?? ''));
                                    if (is_scalar($rawv)) {
                                        $sortAttr = ' data-sort-value="' . $esc((string) $rawv, 'attr') . '"';
                                    }
                                }
                                ?>
                                <td<?= $isNum ? ' class="num"' : '' ?><?= $sortAttr ?>><?= $cell($col, $row) ?></td>
                            <?php endforeach; ?>
                            <?php if (! empty($page['rowActions'])): ?>
                                <td>
                                    <?php foreach ((array) $page['rowActions'] as $act): ?>
                                        <?php
                                        $idVal   = (string) ($dig($row, (string) ($page['rowIdKey'] ?? 'id')) ?? '');
                                        $actTpl  = (string) ($act['action'] ?? '#');
                                        $actUrl  = str_replace('{id}', rawurlencode($idVal), $actTpl);
                                        $actLbl  = $L((string) ($act['labelKey'] ?? ''), (string) ($act['label'] ?? 'Go'));
                                        ?>
                                        <form class="inline" method="post" action="<?= $url($actUrl) ?>">
                                            <input type="hidden" name="_csrf" value="<?= $esc($csrf, 'attr') ?>">
                                            <button class="rowbtn" type="submit"><?= $esc($actLbl) ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($enhance): ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="empty"><?= $esc($emptyTx) ?></p>
        <?php endif; ?>
    </main>
    <?php if (! empty($page['enhance'])): ?>
    <!-- Progressive enhancement only: same-origin, no deps, CSP script-src 'self'. Page works without it. -->
    <script src="/assets/js/table-enhance.js" defer></script>
    <?php endif; ?>

<?php include __DIR__ . '/../_shell_close.php'; ?>
