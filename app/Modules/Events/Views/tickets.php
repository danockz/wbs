<?php
/**
 * TICKET PURCHASE page (GET /events/{id}/tickets) — the ATTENDEE-FACING face of
 * TicketingController::tickets, which otherwise left the buy flow as a JSON-only
 * two-step hold→checkout API. Lists the event's on-sale ticket types with
 * remaining availability and turns each available tier into a no-JS PRG purchase
 * form (POST /events/{id}/tickets → one-step hold+checkout). Also shows the
 * buyer's already-purchased tickets and a live-hold reminder.
 *
 * Signed-out viewers get a sign-in prompt instead of forms; a closed/off-sale
 * event shows a closed note. Each form carries the `_csrf` field (WebCsrfFilter).
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic) and $li() for {0} interpolation. All copy is localized via
 * lang('Events.tickets.*') with English fallback. Money is minor units / 100.
 * No inline JS / on* handlers — CSP-safe (dev host has CSPEnabled=true).
 *
 * @var string                          $eventId     the event id (for the write routes)
 * @var list<array<string,mixed>>       $ticketTypes on-sale ticket types (+availability)
 * @var array{id:string,quantity:int,expires_at:string}|null $hold buyer's live hold
 * @var list<array<string,mixed>>       $myTickets   buyer's paid ticket lines
 * @var bool                            $signedIn    viewer is authenticated
 * @var bool                            $onSale      event is published + reg open
 * @var string                          $csrf        webcsrf token for the buy forms
 */
$ticketTypes = $ticketTypes ?? [];
$hold        = $hold ?? null;
$myTickets   = $myTickets ?? [];
$signedIn    = $signedIn ?? false;
$onSale      = $onSale ?? false;
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
<?= esc(lang('Events.tickets.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 820px; margin: 0 auto; padding: 5vh 20px 60px; }


        .event { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#c4b5fd; border:1px solid #6d28d9; border-radius:6px; padding:2px 8px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        .note { border-radius:10px; padding:12px 16px; margin:0 0 16px; font-size:.9rem; background:#0f172aee; border:1px solid #1e293b; color:#cbd5e1; }


        .desc { color:#94a3b8; font-size:.86rem; margin:6px 0 0; }


        input:focus, select:focus { outline:2px solid #7c3aed; border-color:#7c3aed; }


        button { border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#7c3aed; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.tickets.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.tickets.eventLabel')) ?>: <span class="event"><?= esc($eventId !== '' ? $eventId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($hold !== null): ?>
            <div class="note hold"><?= esc($li('Events.tickets.holdNote', (string) ($hold['quantity'] ?? 1))) ?></div>
        <?php endif; ?>

        <h2><?= esc(lang('Events.tickets.onSaleHeading')) ?></h2>

        <?php if (! $onSale): ?>
            <div class="note"><?= esc(lang('Events.tickets.closedNote')) ?></div>
        <?php elseif ($ticketTypes === []): ?>
            <p class="empty"><?= esc(lang('Events.tickets.noTypes')) ?></p>
        <?php else: ?>
            <?php foreach ($ticketTypes as $t): ?>
                <?php
                $isFree    = (bool) ($t['is_free'] ?? false);
                $available = (bool) ($t['available'] ?? false);
                $remaining = $t['remaining'] ?? null; // null = uncapped
                $soldOut   = $remaining !== null && (int) $remaining <= 0;
                ?>
                <div class="tier<?= $available ? '' : ' gone' ?>">
                    <div class="top">
                        <h3><?= esc((string) ($t['name'] ?? '—')) ?></h3>
                        <span class="price"><?= $isFree ? esc(lang('Events.tickets.free')) : esc($money($t['price_minor'] ?? 0, $t['currency'] ?? '')) ?></span>
                    </div>
                    <?php if (! empty($t['description'])): ?>
                        <p class="desc"><?= esc((string) $t['description']) ?></p>
                    <?php endif; ?>
                    <?php if ($soldOut): ?>
                        <div class="avail out"><?= esc(lang('Events.tickets.soldOut')) ?></div>
                    <?php elseif ($remaining !== null): ?>
                        <div class="avail"><?= esc($li('Events.tickets.remaining', (string) (int) $remaining)) ?></div>
                    <?php else: ?>
                        <div class="avail"><?= esc(lang('Events.tickets.unlimited')) ?></div>
                    <?php endif; ?>

                    <?php if ($available && $signedIn): ?>
                        <form class="buy" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/tickets">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="ticket_type_id" value="<?= esc((string) ($t['id'] ?? ''), 'attr') ?>">
                            <div>
                                <label for="qty-<?= esc((string) ($t['id'] ?? ''), 'attr') ?>"><?= esc(lang('Events.tickets.quantityLbl')) ?></label>
                                <input class="qty" id="qty-<?= esc((string) ($t['id'] ?? ''), 'attr') ?>" type="number" name="quantity" value="1" min="1" max="20" inputmode="numeric">
                            </div>
                            <?php if (! $isFree): ?>
                                <div>
                                    <label for="promo-<?= esc((string) ($t['id'] ?? ''), 'attr') ?>"><?= esc(lang('Events.tickets.promoLbl')) ?></label>
                                    <input class="promo" id="promo-<?= esc((string) ($t['id'] ?? ''), 'attr') ?>" type="text" name="promo_code" maxlength="64" placeholder="<?= esc(lang('Events.tickets.promoPlaceholder'), 'attr') ?>">
                                </div>
                            <?php endif; ?>
                            <button type="submit"><?= esc($isFree ? lang('Events.tickets.getFreeBtn') : lang('Events.tickets.buyBtn')) ?></button>
                        </form>
                    <?php elseif ($available && ! $signedIn): ?>
                        <div class="avail" style="margin-top:10px;color:#cbd5e1;"><?= esc(lang('Events.tickets.signInPrompt')) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (! $signedIn): ?>
                <div class="note"><?= esc(lang('Events.tickets.signInPrompt')) ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($signedIn): ?>
            <h2><?= esc(lang('Events.tickets.myTicketsHeading')) ?></h2>
            <?php if ($myTickets === []): ?>
                <p class="empty"><?= esc(lang('Events.tickets.noMyTickets')) ?></p>
            <?php else: ?>
                <table>
                    <thead><tr>
                        <th><?= esc(lang('Events.tickets.onSaleHeading')) ?></th>
                        <th class="num"><?= esc(lang('Events.tickets.quantityLbl')) ?></th>
                        <th><?= esc(lang('Events.tickets.priceLbl')) ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($myTickets as $m): ?>
                            <tr>
                                <td><?= esc((string) ($m['ticket_name'] ?? '—')) ?></td>
                                <td class="num"><?= esc((string) ($m['quantity'] ?? 1)) ?></td>
                                <td><?= esc($money($m['unit_price_minor'] ?? 0, $m['currency'] ?? '')) ?></td>
                                <td><span class="badge paid"><?= esc(lang('Events.tickets.ticketStatusPaid')) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
