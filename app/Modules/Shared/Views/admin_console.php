<?= $this->extend('layouts/app') ?>

<?php
/**
 * Reusable ADMIN CONSOLE page (SRS FR-ARC-002). A branded, titled back-office
 * view for the many admin read endpoints (AccessControl roles/rules/policies,
 * break-glass, assignments, Admin settings, Gamification config, …) whose record
 * shapes are configuration-defined rather than fixed.
 *
 * Unlike the generic data-page fallback, this is an INTENTIONAL admin view:
 * controllers pass a real $title/$subtitle, so the page reads as a console
 * screen, not a safety net. It still renders any Result shape — list -> table,
 * record -> key/value, problem -> message — via the same recursive renderer.
 *
 * Self-contained + CSP-safe (styling from layouts/app; no external assets).
 * Callers pass the Result envelope so failures (not-found / denied) render too:
 *   viewData: ['result' => $result->toArray(), 'ok' => $result->ok,
 *              'title' => '…', 'subtitle' => '…']
 *
 * @var array<string,mixed> $result    Result::toArray() output
 * @var bool                $ok        whether the Result succeeded
 * @var string              $title
 * @var string              $subtitle  optional context line
 */
$payload = $result ?? [];
$isOk    = $ok ?? true;

// Localized string with graceful fallback. Guards function_exists('lang') so the
// view still renders in CLI/test harnesses where the framework isn't booted, and
// falls back to the English literal when a translation key is missing.
$t = static function (string $key, string $fallback): string {
    if (function_exists('lang')) {
        $s = lang($key);
        if (is_string($s) && $s !== '' && $s !== $key) {
            return $s;
        }
    }

    return $fallback;
};

$label = static function (string $k): string {
    $k = preg_replace('/(?<!^)[A-Z]/', ' $0', $k) ?? $k;

    return ucfirst(trim(str_replace(['_', '-'], ' ', $k)));
};

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

    if ($isList) {
        $items = array_map(static fn ($v) => '<li style="margin:2px 0;">' . $render($v, $depth + 1) . '</li>', $value);

        return '<ul style="margin:4px 0 0;padding-left:18px;">' . implode('', $items) . '</ul>';
    }

    $html = '<div class="grid" style="grid-template-columns:1fr;gap:0;">';
    foreach ($value as $k => $v) {
        $html .= '<div class="row" style="padding:8px 0;border-bottom:1px solid #101a2e;align-items:flex-start;">'
            . '<span class="k" style="min-width:180px;color:#64748b;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;padding-top:2px;">' . esc($label((string) $k)) . '</span>'
            . '<span style="flex:1;text-align:right;word-break:break-word;">' . $render($v, $depth + 1) . '</span>'
            . '</div>';
    }

    return $html . '</div>';
};

$data  = $isOk ? ($payload['data'] ?? null) : null;
$meta  = $payload['meta'] ?? null;
$count = is_array($data) && array_is_list($data) ? count($data) : null;
?>

<?= $this->section('content') ?>
    <h1><?= esc($title ?? $t('AdminConsole.consoleTitle', 'Admin console')) ?></h1>
    <div class="sub">
        <span class="pill" style="color:#22d3ee;"><?= esc($t('AdminConsole.badge', 'admin')) ?></span>
        <?php if (! empty($subtitle)): ?> · <?= esc($subtitle) ?><?php endif; ?>
        <?php if ($count !== null): ?> · <?= $count ?> <?= esc($count === 1 ? $t('AdminConsole.record', 'record') : $t('AdminConsole.records', 'records')) ?><?php endif; ?>
    </div>

    <?php if (! $isOk): ?>
        <div class="card">
            <div class="row"><span class="author warn"><?= esc($payload['title'] ?? $t('AdminConsole.error', 'Error')) ?></span><span class="time"><?= esc(str_replace('{0}', (string) (int) ($payload['status'] ?? 0), $t('AdminConsole.status', 'status {0}'))) ?></span></div>
            <div class="counts"><?= esc($payload['detail'] ?? $t('AdminConsole.requestFailed', 'Request could not be completed.')) ?></div>
            <?php if (! empty($payload['errors'])): ?><div style="margin-top:10px;"><?= $render($payload['errors']) ?></div><?php endif; ?>
        </div>
    <?php elseif ($data === null || $data === []): ?>
        <div class="empty"><?= esc($t('AdminConsole.noRecords', 'No records.')) ?></div>
    <?php else: ?>
        <div class="card"><?= $render($data) ?></div>
        <?php if (! empty($meta)): ?>
            <h2><?= esc($t('AdminConsole.meta', 'Meta')) ?></h2>
            <div class="card"><?= $render($meta) ?></div>
        <?php endif; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
