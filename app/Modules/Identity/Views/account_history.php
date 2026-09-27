<?php
/**
 * Account lifecycle history (GET /identity/accounts/{id}/history) — the browser
 * face of AccountController::history, which otherwise rendered the generic admin
 * console. Shows an account's state transitions oldest-first as a timeline, each
 * entry showing from→to status, reason, actor and any approval reference.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy via lang('Identity.accountHistory.*') with English
 * fallback; the {0} count is interpolated via $li(); the status vocabulary is
 * localized with a raw-value fallback. Ids/reasons are server data shown
 * verbatim & escaped.
 *
 * @var list<array<string,mixed>> $transitions account_state_transitions rows (oldest first)
 * @var string                    $userId
 */
$transitions = $transitions ?? [];
$userId      = $userId ?? '';
$count       = count($transitions);

include __DIR__ . '/_locale.php';

$vstatus = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Identity.accountHistory.status.' . $value);
    return (is_string($s) && ! str_contains($s, 'Identity.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.accountHistory.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 820px; margin: 0 auto; padding: 5vh 20px 60px; }


        .t { background:#0f172aee; border:1px solid #1e293b; border-inline-start:3px solid #4338ca; border-radius:0 12px 12px 0; padding:13px 16px; margin-bottom:10px; }


        .flow { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:6px; }


        .arr { color:#818cf8; }


        .when { margin-inline-start:auto; font-size:.72rem; color:#94a3b8; }


        .reason { color:#cbd5e1; font-size:.9rem; margin:0 0 4px; }


        .meta { display:flex; flex-wrap:wrap; gap:6px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Identity.accountHistory.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Identity.accountHistory.userLabel')) ?>: <span class="user"><?= esc($userId !== '' ? $userId : '—') ?></span></p>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Identity.accountHistory.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Identity.accountHistory.countOne' : 'Identity.accountHistory.count', (string) $count)) ?></p>
            <?php foreach ($transitions as $t): ?>
                <article class="t">
                    <div class="flow">
                        <?php if (! empty($t['from_status'])): ?>
                            <span class="badge"><?= esc($vstatus((string) $t['from_status'])) ?></span>
                            <span class="arr">→</span>
                        <?php endif; ?>
                        <span class="badge"><?= esc($vstatus((string) ($t['to_status'] ?? ''))) ?></span>
                        <span class="when"><?= esc((string) ($t['created_at'] ?? '—')) ?></span>
                    </div>
                    <?php if (! empty($t['reason'])): ?><p class="reason"><?= esc((string) $t['reason']) ?></p><?php endif; ?>
                    <div class="meta">
                        <?php if (! empty($t['actor_id'])): ?><span class="tag"><?= esc(lang('Identity.accountHistory.colActor')) ?>: <span class="mono"><?= esc((string) $t['actor_id']) ?></span></span><?php endif; ?>
                        <?php if (! empty($t['approval_ref'])): ?><span class="tag"><?= esc(lang('Identity.accountHistory.colApproval')) ?>: <span class="mono"><?= esc((string) $t['approval_ref']) ?></span></span><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
