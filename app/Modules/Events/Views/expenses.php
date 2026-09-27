<?php
/**
 * EXPENSE APPROVALS launcher (GET /events/expenses) — the browser face of
 * ExpenseController::approvalsForm and the landing page for the Events → Expense
 * approvals menu item (previously a 404). Shows the org-wide submitted-expense
 * queue; each row carries an inline approve/reject form that POSTs to
 * /events/expenses (webcsrf-guarded), which dispatches to ExpenseService with the
 * maker-checker rules intact. The page re-renders after each decision.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.expenses.*') with English
 * fallback. The CSRF token issued by the controller is echoed into hidden _csrf.
 *
 * @var string                     $csrf     CSRF token (also an HttpOnly cookie)
 * @var list<array<string,mixed>>  $expenses submitted expenses awaiting a decision
 * @var string                     $error    optional error from a failed decision
 * @var array<string,mixed>        $old      optional previously-submitted values
 */
$csrf     = $csrf ?? '';
$error    = $error ?? '';
$old      = $old ?? [];
$expenses = $expenses ?? [];

include __DIR__ . '/_locale.php';

// Presentation helpers: time_ago (submission age) and redact_id (submitter id)
// are procedural view helpers (app/Helpers/{time,redactor}_helper.php); the
// controller registers them via BaseController::$helpers. Guard-load them so this
// SELF-CONTAINED page still renders under a headless test harness.
if (! function_exists('time_ago')) {
    require_once dirname(__DIR__, 3) . '/Helpers/time_helper.php';
}
if (! function_exists('redact_id')) {
    require_once dirname(__DIR__, 3) . '/Helpers/redactor_helper.php';
}

$money = static function (array $r, string $key = 'amount_minor'): string {
    $minor = $r[$key] ?? null;
    if ($minor === null || $minor === '') {
        return '—';
    }
    return number_format(((int) $minor) / 100, 2) . ' ' . (string) ($r['currency'] ?? '');
};
?>

<?php ob_start(); ?>
<?= esc(lang('Events.expenses.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 960px; margin: 0 auto; padding: 5vh 20px 60px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; margin-bottom:14px; }


        .meta { color:#94a3b8; font-size:.82rem; margin:4px 0 12px; }


        .desc { margin:0 0 12px; }


        button { padding:9px 16px; border:0; border-radius:8px; cursor:pointer; font-weight:700; font-size:.9rem; color:#fff; }


        .approve { background:#16a34a; }

 .approve:hover { background:#15803d; }


        .reject { background:#b91c1c; }

 .reject:hover { background:#991b1b; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.expenses.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.expenses.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <?php if ($expenses === []): ?>
            <div class="empty"><?= esc(lang('Events.expenses.empty')) ?></div>
        <?php else: ?>
            <?php foreach ($expenses as $x): ?>
                <?php
                $xid    = (string) ($x['id'] ?? '');
                $status = strtolower((string) ($x['status'] ?? ''));
                $age    = time_ago($x['created_at'] ?? null, '');
                ?>
            <article class="card">
                <div class="head">
                    <span class="evt"><?= esc((string) ($x['event_title'] ?? $x['event_id'] ?? '')) ?></span>
                    <span class="amt"><?= esc($status === 'approved' ? $money($x, 'approved_amount_minor') : $money($x)) ?></span>
                </div>
                <div class="meta">
                    <span class="pill st-<?= esc($status, 'attr') ?>"><?= esc((string) ($x['status'] ?? '')) ?></span>
                    &middot; <?= esc(lang('Events.expenses.colCategory')) ?>: <?= esc((string) ($x['category'] ?? '—')) ?>
                    &middot; <?= esc(lang('Events.expenses.colSubmitter')) ?>: <?= esc(redact_id((string) ($x['submitted_by'] ?? ''))) ?>
                    <?php if ($age !== ''): ?>&middot; <span title="<?= esc((string) ($x['created_at'] ?? ''), 'attr') ?>"><?= esc($age) ?></span><?php endif; ?>
                </div>
                <p class="desc"><?= esc((string) ($x['description'] ?? '')) ?></p>

                <?php if ($status === 'approved'): ?>
                <form method="post" action="<?= esc(base_url('events/expenses'), 'attr') ?>" class="controls">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <input type="hidden" name="expense_id" value="<?= esc($xid, 'attr') ?>">
                    <div class="grow">
                        <label for="ref-<?= esc($xid, 'attr') ?>"><?= esc(lang('Events.expenses.paymentRefLabel')) ?></label>
                        <input id="ref-<?= esc($xid, 'attr') ?>" name="payment_reference" required value="<?= esc((string) ($old['expense_id'] ?? '') === $xid ? (string) ($old['payment_reference'] ?? '') : '', 'attr') ?>">
                    </div>
                    <div class="grow">
                        <label for="note-<?= esc($xid, 'attr') ?>"><?= esc(lang('Events.expenses.noteLabel')) ?></label>
                        <input id="note-<?= esc($xid, 'attr') ?>" name="note">
                    </div>
                    <button class="reimburse" type="submit" name="action" value="reimburse"><?= esc(lang('Events.expenses.reimburse')) ?></button>
                </form>
                <?php else: ?>
                <form method="post" action="<?= esc(base_url('events/expenses'), 'attr') ?>" class="controls">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <input type="hidden" name="expense_id" value="<?= esc($xid, 'attr') ?>">
                    <div class="grow">
                        <label for="amt-<?= esc($xid, 'attr') ?>"><?= esc(lang('Events.expenses.approvedAmountLabel')) ?></label>
                        <input id="amt-<?= esc($xid, 'attr') ?>" name="approved_amount_minor" type="number" min="0" placeholder="<?= esc((string) ($x['amount_minor'] ?? ''), 'attr') ?>">
                    </div>
                    <div class="grow">
                        <label for="note-<?= esc($xid, 'attr') ?>"><?= esc(lang('Events.expenses.noteLabel')) ?></label>
                        <input id="note-<?= esc($xid, 'attr') ?>" name="note">
                    </div>
                    <button class="approve" type="submit" name="action" value="approve"><?= esc(lang('Events.expenses.approve')) ?></button>
                    <button class="reject" type="submit" name="action" value="reject"><?= esc(lang('Events.expenses.reject')) ?></button>
                </form>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
