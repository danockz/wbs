<?php
/**
 * MEMBERSHIP CONFLICT RULES console (GET /memberships/conflicts) — the browser
 * face of GroupMembershipController::listConflicts, which otherwise rendered the
 * generic admin console. A conflict rule declares that a person may not hold two
 * membership TYPES at once within a given SCOPE (global / same group / same
 * branch); the roster add path enforces them. This page lists the rules and turns
 * into a console with a "define rule" form.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Groups.conflicts.*') with English
 * fallback; the {0} count is interpolated via $li(); the scope vocabulary is
 * localized with a raw-value fallback. Types are server data shown verbatim.
 *
 * The define form is a no-JS PRG post to the webcsrf-guarded route using the
 * `_csrf` field (matches WebCsrfFilter). A flashed banner from the previous PRG
 * round-trip shows at the top.
 *
 * @var list<array<string,mixed>> $conflicts conflict-rule rows
 * @var list<string>              $types      allowed membership types (type_a/type_b)
 * @var list<string>              $scopes     allowed scopes
 * @var string                    $csrf       webcsrf token for the inline form
 */
$conflicts = $conflicts ?? [];
$types     = $types ?? ['member', 'leader', 'activity', 'department', 'team', 'guest'];
$scopes    = $scopes ?? ['global', 'same_group', 'same_branch'];
$csrf      = $csrf ?? '';
$count     = count($conflicts);

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$vscope = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Groups.conflicts.scope.' . $value);
    return (is_string($s) && ! str_contains($s, 'Groups.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.conflicts.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 820px; margin: 0 auto; padding: 5vh 20px 60px; }


        .item { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:13px 16px; margin-bottom:10px; display:flex; flex-wrap:wrap; gap:8px 12px; align-items:baseline; }


        .pair { font-weight:600; }


        .reason { flex-basis:100%; color:#94a3b8; font-size:.85rem; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.conflicts.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.conflicts.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <form class="definer" method="post" action="/memberships/conflicts">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h2><?= esc(lang('Groups.conflicts.defineHeading')) ?></h2>
            <p class="hint"><?= esc(lang('Groups.conflicts.defineHint')) ?></p>
            <div class="grid">
                <div>
                    <label for="c-a"><?= esc(lang('Groups.conflicts.fTypeA')) ?></label>
                    <select id="c-a" name="type_a" required>
                        <?php foreach ($types as $t): ?><option value="<?= esc($t, 'attr') ?>"><?= esc($t) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="c-b"><?= esc(lang('Groups.conflicts.fTypeB')) ?></label>
                    <select id="c-b" name="type_b" required>
                        <?php foreach ($types as $t): ?><option value="<?= esc($t, 'attr') ?>"<?= $t === 'leader' ? ' selected' : '' ?>><?= esc($t) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="c-scope"><?= esc(lang('Groups.conflicts.fScope')) ?></label>
                    <select id="c-scope" name="scope">
                        <?php foreach ($scopes as $s): ?><option value="<?= esc($s, 'attr') ?>"><?= esc($vscope($s)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="full">
                    <label for="c-reason"><?= esc(lang('Groups.conflicts.fReason')) ?></label>
                    <input type="text" id="c-reason" name="reason" maxlength="255" placeholder="<?= esc(lang('Groups.conflicts.fReasonPh'), 'attr') ?>">
                </div>
            </div>
            <button type="submit"><?= esc(lang('Groups.conflicts.defineBtn')) ?></button>
        </form>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Groups.conflicts.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Groups.conflicts.countOne' : 'Groups.conflicts.count', (string) $count)) ?></p>
            <?php foreach ($conflicts as $c): ?>
                <article class="item">
                    <span class="pair"><?= esc((string) ($c['type_a'] ?? '—')) ?> <span class="vs"><?= esc(lang('Groups.conflicts.versus')) ?></span> <?= esc((string) ($c['type_b'] ?? '—')) ?></span>
                    <span class="scope"><?= esc($vscope((string) ($c['scope'] ?? ''))) ?></span>
                    <?php if (! empty($c['reason'])): ?><span class="reason"><?= esc((string) $c['reason']) ?></span><?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
