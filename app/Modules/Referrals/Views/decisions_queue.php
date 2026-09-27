<?php
/**
 * Owning mentor's queue — pending self-declarations to confirm or reject
 * (FR-REF-3b maker-checker). Self-contained page (own <html>, inline styles,
 * no JS, CSP-clean); includes _locale.php for locale-aware <html lang dir>.
 *
 * No TTL: a declaration stays pending until a human decides. Confirming makes
 * it count toward the contact's integration; rejecting keeps it as history.
 *
 * @var list<array<string,mixed>> $pending
 * @var string                    $csrf
 */
$pending = $pending ?? [];
$csrf    = $csrf ?? '';

include __DIR__ . '/_locale.php';

$typeLabel = static function (string $t): string {
    $v = lang('Referrals.decision.types.' . $t);

    return $v === 'Referrals.decision.types.' . $t ? ucwords(str_replace('_', ' ', $t)) : $v;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.integration.queue.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 860px; margin: 0 auto; padding: 5vh 20px 60px; }


        .name { font-weight:700; color:#e2e8f0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="wrap">
        <h1><?= esc(lang('Referrals.integration.queue.heading')) ?></h1>
        <div class="sub"><?= esc(lang('Referrals.integration.queue.sub')) ?></div>

        <div class="panel">
            <?php if ($pending === []): ?>
                <div class="empty"><?= esc(lang('Referrals.integration.queue.empty')) ?></div>
            <?php else: ?>
                <table>
                    <tr>
                        <th><?= esc(lang('Referrals.integration.queue.contactLbl')) ?></th>
                        <th><?= esc(lang('Referrals.integration.queue.decisionLbl')) ?></th>
                        <th><?= esc(lang('Referrals.integration.queue.dateLbl')) ?></th>
                        <th><?= esc(lang('Referrals.integration.queue.noteLbl')) ?></th>
                        <th></th>
                    </tr>
                    <?php foreach ($pending as $d): ?>
                        <tr>
                            <td class="name"><?= esc((string) ($d['contact_name'] ?? '')) ?></td>
                            <td><?= esc($typeLabel((string) ($d['decision_type'] ?? ''))) ?></td>
                            <td><?= esc((string) ($d['decision_date'] ?? '')) ?></td>
                            <td class="muted"><?= esc((string) ($d['note'] ?? '')) ?></td>
                            <td>
                                <div class="acts">
                                    <form method="post" action="/me/integration-decisions/confirm/<?= esc((string) ($d['id'] ?? '')) ?>">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                        <button class="btn ok" type="submit"><?= esc(lang('Referrals.integration.queue.confirm')) ?></button>
                                    </form>
                                    <form method="post" action="/me/integration-decisions/reject/<?= esc((string) ($d['id'] ?? '')) ?>">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                        <button class="btn no" type="submit"><?= esc(lang('Referrals.integration.queue.reject')) ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
