<?= $this->extend('layouts/app') ?>

<?php
/**
 * Generic "data page" — the universal browser fallback (SRS FR-ARC-001/002).
 *
 * When a browser (HTML-preferring client) hits any endpoint that did NOT supply
 * its own bespoke view, BaseController renders the service Result through this
 * template instead of dumping raw JSON. API clients (Accept: application/json,
 * ?format=json, XHR) are never routed here — they still receive JSON.
 *
 * It renders whatever shape the Result carries — scalar, list, or nested map —
 * as readable cards/tables. Self-contained + CSP-safe (styling from layouts/app,
 * no external assets). It is a safety net; high-traffic pages get real views.
 *
 * @var array<string,mixed> $result  Result::toArray() output ({data,...} or problem+detail)
 * @var string              $title
 * @var bool                $ok
 */
$payload = $result ?? [];
$isOk    = $ok ?? true;

/** Human label from a snake/camel key. */
$label = static function (string $k): string {
    $k = preg_replace('/(?<!^)[A-Z]/', ' $0', $k) ?? $k;

    return ucfirst(trim(str_replace(['_', '-'], ' ', $k)));
};

/** Render any value recursively as HTML. Depth-guarded. */
$render = static function ($value, int $depth = 0) use (&$render, $label): string {
    if ($depth > 6) {
        return '<span class="muted">…</span>';
    }
    if ($value === null) {
        return '<span class="muted">—</span>';
    }
    if (is_bool($value)) {
        return '<span class="pill">' . ($value ? 'true' : 'false') . '</span>';
    }
    if (is_scalar($value)) {
        return esc((string) $value);
    }
    if (! is_array($value)) {
        return esc(gettype($value));
    }
    if ($value === []) {
        return '<span class="muted">empty</span>';
    }

    // List of rows -> table when the first row is an assoc array.
    $isList = array_is_list($value);
    if ($isList && is_array($value[0] ?? null) && $value[0] !== [] && ! array_is_list($value[0])) {
        $cols = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                foreach (array_keys($row) as $k) {
                    $cols[$k] = true;
                }
            }
        }
        $cols = array_keys($cols);
        $html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.85rem;">';
        $html .= '<thead><tr>';
        foreach ($cols as $c) {
            $html .= '<th style="text-align:left;padding:8px 10px;border-bottom:1px solid #1e293b;color:#818cf8;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;">' . esc($label((string) $c)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($value as $row) {
            $html .= '<tr>';
            foreach ($cols as $c) {
                $cell = is_array($row) ? ($row[$c] ?? null) : null;
                $html .= '<td style="padding:8px 10px;border-bottom:1px solid #101a2e;vertical-align:top;">' . $render($cell, $depth + 1) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    // Plain list of scalars.
    if ($isList) {
        $items = array_map(static fn ($v) => '<li style="margin:2px 0;">' . $render($v, $depth + 1) . '</li>', $value);

        return '<ul style="margin:4px 0 0;padding-left:18px;">' . implode('', $items) . '</ul>';
    }

    // Associative map -> key/value rows.
    $html = '<div class="grid" style="grid-template-columns:1fr;gap:0;">';
    foreach ($value as $k => $v) {
        $html .= '<div class="row" style="padding:8px 0;border-bottom:1px solid #101a2e;align-items:flex-start;">'
            . '<span class="k" style="min-width:160px;color:#64748b;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;padding-top:2px;">' . esc($label((string) $k)) . '</span>'
            . '<span style="flex:1;text-align:right;word-break:break-word;">' . $render($v, $depth + 1) . '</span>'
            . '</div>';
    }

    return $html . '</div>';
};

// Split the envelope: success carries `data` (+ optional meta); a problem carries
// title/detail/status.
$data   = $isOk ? ($payload['data'] ?? null) : null;
$meta   = $payload['meta'] ?? null;
$errors = $payload['errors'] ?? null;

// FR-ARC-002 sweep: a browser must never see raw machine keys here
// (`contact.name_required`, `NAME_REQUIRED`) — humanize the problem fields at
// render time. API clients never reach this view (respondWith's wantsJson
// short-circuit returns JSON first); OK payloads are left untouched.
// Self-require the helper: standalone test harnesses render this view without
// a composer autoloader.
if (! class_exists(\WBS\Shared\Support\Messages::class, false)) {
    require_once dirname(__DIR__) . '/Support/Messages.php';
}
if (! $isOk) {
    foreach (['title', 'detail'] as $f) {
        if (is_string($payload[$f] ?? null) && $payload[$f] !== '') {
            $payload[$f] = \WBS\Shared\Support\Messages::humanize($payload[$f]);
        }
    }
}
?>

<?= $this->section('content') ?>
    <h1><?= esc($title ?? lang('App.dpResult')) ?></h1>

    <?php if (! $isOk): ?>
        <div class="sub"><span class="warn"><?= esc($payload['title'] ?? lang('App.dpError')) ?></span> · status <?= (int) ($payload['status'] ?? 0) ?></div>
        <div class="card">
            <div class="counts"><?= esc($payload['detail'] ?? lang('App.dpRequestFailed')) ?></div>
        </div>
        <?php if (! empty($errors)): ?>
            <h2><?= esc(lang('App.dpDetails')) ?></h2>
            <div class="card"><?= $render($errors) ?></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="sub"><?= esc(lang('App.dpServerResponse')) ?></div>
        <div class="card">
            <?php if ($data === null): ?>
                <div class="counts"><span style="color:#4ade80;">✓</span> <?= esc(lang('App.dpSuccessNoData')) ?></div>
            <?php else: ?>
                <?= $render($data) ?>
            <?php endif; ?>
        </div>
        <?php if (! empty($meta)): ?>
            <h2><?= esc(lang('App.dpMeta')) ?></h2>
            <div class="card"><?= $render($meta) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="meta"><?= esc(lang('App.dpGenericNote')) ?> <code>?format=json</code></div>
<?= $this->endSection() ?>
