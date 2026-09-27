<?php
/**
 * Sponsorship CHAIN page (referrals/chain/{memberId}) — the read-only upline
 * spine from a member up to the top of the organization. This is the browser
 * face of ReferralController::chain, which otherwise only spoke JSON.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). All UI copy is localized via
 * lang('Referrals.chain.*') with English fallback; the {0} count is interpolated
 * in PHP via $li() and singular/plural is chosen in PHP (no ICU runtime dep).
 *
 * The service is PII-free by design (member IDs only, no names/emails), so ids
 * are shown verbatim and escaped. The chain is rendered nearest-first, matching
 * SponsorshipService::upline(): index 0 is the member's own sponsor, the last
 * entry is the top (national/root) leader.
 *
 * @var string                                    $memberId
 * @var list<array{level:int,member_id:string}>   $chain
 */
$memberId = $memberId ?? '';
$chain    = $chain ?? [];
$count    = count($chain);

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.chain.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 720px; margin: 0 auto; padding: 5vh 20px 60px; }


        .node { background:#0f172aee; border:1px solid #1e293b; border-radius:12px;
            padding:12px 16px; margin:0 0 10px; display:flex; align-items:center; gap:14px; }


        .id { font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.9rem; word-break:break-all; }


        .link { text-align:center; color:#334155; margin:-2px 0 8px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Referrals.chain.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Referrals.chain.sub')) ?></p>

        <?php if ($count === 0): ?>
            <div class="node me">
                <span class="badge"><?= esc(lang('Referrals.chain.member')) ?></span>
                <span class="id"><span class="idlbl"><?= esc(lang('Referrals.chain.memberIdLbl')) ?></span><?= esc($memberId) ?></span>
            </div>
            <p class="empty"><?= esc(lang('Referrals.chain.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Referrals.chain.one' : 'Referrals.chain.many', (string) $count)) ?></p>
            <ul class="spine">
                <li class="node me">
                    <span class="badge"><?= esc(lang('Referrals.chain.member')) ?></span>
                    <span class="id"><span class="idlbl"><?= esc(lang('Referrals.chain.memberIdLbl')) ?></span><?= esc($memberId) ?></span>
                </li>
                <?php foreach ($chain as $i => $node): ?>
                    <?php
                    $level    = (int) ($node['level'] ?? ($i + 1));
                    $sponsor  = (string) ($node['member_id'] ?? '');
                    $isRoot   = ($i === $count - 1);
                    $isFirst  = ($i === 0);
                    // Nearest-first badge: closest sponsor, the top of chain, else the level.
                    if ($isRoot) {
                        $badge = lang('Referrals.chain.root');
                    } elseif ($isFirst) {
                        $badge = lang('Referrals.chain.nearest');
                    } else {
                        $badge = $li('Referrals.chain.level', (string) $level);
                    }
                    ?>
                    <li class="link" aria-hidden="true">↑</li>
                    <li class="node<?= $isRoot ? ' root' : '' ?>">
                        <span class="badge"><?= esc($badge) ?></span>
                        <span class="id"><span class="idlbl"><?= esc(lang('Referrals.chain.memberIdLbl')) ?></span><?= esc($sponsor) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
