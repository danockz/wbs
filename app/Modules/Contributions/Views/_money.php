<?php
/**
 * Shared money helper for the Contributions views. Included once per view to
 * define money() in the view's local scope. All amounts are stored in MINOR
 * units (e.g. cents/pesewas); this formats them for display with a currency tag.
 *
 * Not a page — a plain include. Usage:  <?php include __DIR__ . '/_money.php'; ?>
 */
if (! isset($money)) {
    $money = static function (?int $minor, ?string $currency = null): string {
        $minor ??= 0;
        $amount = number_format($minor / 100, 2);

        return trim(($currency ? esc($currency) . ' ' : '') . $amount);
    };
}
