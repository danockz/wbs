<?php
/**
 * TICKETING console (GET /events/{id}/ticketing) — the browser face of
 * TicketingController::console, which otherwise left ticket-type and promo-code
 * creation as JSON-only endpoints. Lists the event's ticket types and promo codes
 * and turns each section into a management console with a no-JS PRG create form.
 *
 *   - create ticket type → POST .../ticket-types
 *   - create promo code  → POST .../promo-codes
 * Each form carries the `_csrf` field (matches WebCsrfFilter).
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.ticketing.*') with English
 * fallback. Prices are minor units shown as-is; ids/names are escaped.
 *
 * @var list<array<string,mixed>> $ticketTypes event_ticket_types rows
 * @var list<array<string,mixed>> $promoCodes  event_promo_codes rows
 * @var string                    $eventId     the event id (for the write routes)
 * @var string                    $csrf        webcsrf token for the inline forms
 */
$ticketTypes = $ticketTypes ?? [];
$promoCodes  = $promoCodes ?? [];
$eventId     = $eventId ?? '';
$csrf        = $csrf ?? '';
$sidAttr     = $eventId !== '' ? rawurlencode($eventId) : '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$money = static function (mixed $minor, mixed $ccy): string {
    return number_format(((int) $minor) / 100, 2) . ' ' . strtoupper((string) ($ccy ?: ''));
};
?>

<?php ob_start(); ?>
<?= esc(lang('Events.ticketing.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 940px; margin: 0 auto; padding: 5vh 20px 60px; }


        .event { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#c4b5fd; border:1px solid #6d28d9; border-radius:6px; padding:2px 8px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        input:focus, select:focus { outline:2px solid #7c3aed; border-color:#7c3aed; }


        button { margin-top:12px; border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#7c3aed; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.ticketing.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.ticketing.eventLabel')) ?>: <span class="event"><?= esc($eventId !== '' ? $eventId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <!-- Ticket types -->
        <h2><?= esc(lang('Events.ticketing.typesHeading')) ?></h2>
        <?php if ($ticketTypes === []): ?>
            <p class="empty"><?= esc(lang('Events.ticketing.noTypes')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.ticketing.colName')) ?></th>
                    <th class="num"><?= esc(lang('Events.ticketing.colPrice')) ?></th>
                    <th class="num"><?= esc(lang('Events.ticketing.colQty')) ?></th>
                    <th><?= esc(lang('Events.ticketing.colStatus')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($ticketTypes as $t): ?>
                        <tr>
                            <td><?= esc((string) ($t['name'] ?? '—')) ?></td>
                            <td class="num"><?= esc($money($t['price_minor'] ?? 0, $t['currency'] ?? '')) ?></td>
                            <td class="num"><?= esc($t['quantity_total'] !== null ? (string) $t['quantity_total'] : lang('Events.ticketing.unlimited')) ?></td>
                            <td><?= esc((string) ($t['status'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/ticket-types">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h3><?= esc(lang('Events.ticketing.addTypeHeading')) ?></h3>
            <div class="grid">
                <div><label for="t-name"><?= esc(lang('Events.ticketing.colName')) ?></label><input id="t-name" name="name" required maxlength="120"></div>
                <div><label for="t-price"><?= esc(lang('Events.ticketing.fPriceMinor')) ?></label><input type="number" min="0" id="t-price" name="price_minor" value="0"></div>
                <div><label for="t-ccy"><?= esc(lang('Events.ticketing.fCurrency')) ?></label><input id="t-ccy" name="currency" value="GHS" maxlength="3"></div>
                <div><label for="t-qty"><?= esc(lang('Events.ticketing.fQtyTotal')) ?></label><input type="number" min="0" id="t-qty" name="quantity_total"></div>
                <div><label for="t-limit"><?= esc(lang('Events.ticketing.fPerUserLimit')) ?></label><input type="number" min="0" id="t-limit" name="per_user_limit"></div>
                <div class="full"><label for="t-desc"><?= esc(lang('Events.ticketing.fDescription')) ?></label><input id="t-desc" name="description" maxlength="255"></div>
            </div>
            <button type="submit"><?= esc(lang('Events.ticketing.addTypeBtn')) ?></button>
        </form>

        <!-- Promo codes -->
        <h2><?= esc(lang('Events.ticketing.promosHeading')) ?></h2>
        <?php if ($promoCodes === []): ?>
            <p class="empty"><?= esc(lang('Events.ticketing.noPromos')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.ticketing.colCode')) ?></th>
                    <th><?= esc(lang('Events.ticketing.colKind')) ?></th>
                    <th class="num"><?= esc(lang('Events.ticketing.colValue')) ?></th>
                    <th><?= esc(lang('Events.ticketing.colStatus')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($promoCodes as $p): ?>
                        <?php
                        $kind = (string) ($p['kind'] ?? 'percent');
                        $val  = $kind === 'percent'
                            ? (((int) ($p['percent_bps'] ?? 0)) / 100) . '%'
                            : $money($p['amount_minor'] ?? 0, '');
                        ?>
                        <tr>
                            <td class="code"><?= esc((string) ($p['code'] ?? '—')) ?></td>
                            <td><?= esc($kind) ?></td>
                            <td class="num"><?= esc($val) ?></td>
                            <td><?= esc((string) ($p['status'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/promo-codes">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h3><?= esc(lang('Events.ticketing.addPromoHeading')) ?></h3>
            <p class="empty" style="font-style:normal;color:#94a3b8"><?= esc(lang('Events.ticketing.promoHint')) ?></p>
            <div class="grid">
                <div><label for="p-code"><?= esc(lang('Events.ticketing.colCode')) ?></label><input id="p-code" name="code" required maxlength="40"></div>
                <div><label for="p-kind"><?= esc(lang('Events.ticketing.colKind')) ?></label>
                    <select id="p-kind" name="kind">
                        <option value="percent"><?= esc(lang('Events.ticketing.kindPercent')) ?></option>
                        <option value="amount"><?= esc(lang('Events.ticketing.kindAmount')) ?></option>
                    </select>
                </div>
                <div><label for="p-bps"><?= esc(lang('Events.ticketing.fPercentBps')) ?></label><input type="number" min="0" max="10000" id="p-bps" name="percent_bps"></div>
                <div><label for="p-amt"><?= esc(lang('Events.ticketing.fAmountMinor')) ?></label><input type="number" min="0" id="p-amt" name="amount_minor"></div>
                <div><label for="p-max"><?= esc(lang('Events.ticketing.fMaxRedemptions')) ?></label><input type="number" min="0" id="p-max" name="max_redemptions"></div>
            </div>
            <button type="submit"><?= esc(lang('Events.ticketing.addPromoBtn')) ?></button>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
