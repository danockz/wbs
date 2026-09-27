<?php
/**
 * CONNECTOR-PROFILE authoring console (GET /integrations/profiles) — the browser
 * face of ProfileController::index, which previously exposed create as a
 * JSON-only endpoint with no page.
 *
 * It drives the FR-INT-002/004 no-code profile lifecycle: a "define a profile"
 * form (canonical operation / protocol family / HTTP method / approved host /
 * signature algorithm — all constrained to the reviewed enums the controller
 * passes in) and every profile shown with the stage-appropriate advance / revoke
 * controls. Each control POSTs to a webcsrf-guarded route; the controller PRG-
 * redirects back here with a localized flash. No-JS friendly (each action is its
 * own form) and CSP-safe (no inline on* handlers).
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Integrations.profiles.*') with English fallback; the {0} count / next-
 * status are interpolated in PHP via $li(). `status` is localized with a raw-
 * value fallback. NO request/response mapping blob is shown (the list omits it);
 * mappings are authored via the JSON API — this console covers routing + the
 * certification lifecycle.
 *
 * @var list<array<string,mixed>> $profiles   annotated with next_status/is_terminal
 * @var list<string>              $operations canonical operations enum
 * @var list<string>              $families   protocol families enum
 * @var list<string>              $methods    HTTP methods enum
 * @var list<string>              $algos      signature algorithms enum
 * @var string                    $csrf
 */
$profiles   = $profiles ?? [];
$operations = $operations ?? [];
$families   = $families ?? [];
$methods    = $methods ?? [];
$algos      = $algos ?? [];
$csrf       = $csrf ?? '';
$count      = count($profiles);

include __DIR__ . '/_locale.php';

$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;

$statusColor = static fn (string $s): string => match ($s) {
    'active'           => '#22c55e',
    'approved'         => '#4ade80',
    'finance_review'   => '#f59e0b',
    'security_review'  => '#fb923c',
    'sandbox_verified' => '#38bdf8',
    'draft'            => '#94a3b8',
    'revoked', 'deprecated' => '#ef4444',
    default            => '#94a3b8',
};
$prettyLbl = static function (string $s): string {
    return $s === '' ? '' : ucwords(str_replace('_', ' ', $s));
};
$none = lang('Integrations.profiles.none');
?>

<?php ob_start(); ?>
<?= esc(lang('Integrations.profiles.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1080px; margin: 0 auto; padding: 5vh 20px 60px; }


        select:focus, input:focus { outline:none; border-color:#a78bfa; }


        .actions form { display:inline-flex; gap:6px; align-items:center; margin:0 6px 4px 0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap" style="--wbs-accent: #a78bfa">
        <h1><?= esc(lang('Integrations.profiles.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Integrations.profiles.sub')) ?></p>
        <p class="count"><?= esc($li($count === 1 ? 'Integrations.profiles.countOne' : 'Integrations.profiles.count', (string) $count)) ?></p>

        <?php if ($flashOk): ?><div class="flash ok"><?= esc((string) $flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr): ?><div class="flash err"><?= esc((string) $flashErr) ?></div><?php endif; ?>

        <!-- Define a profile -->
        <details class="reg">
            <summary><?= esc(lang('Integrations.profiles.defineHeading')) ?></summary>
            <p class="rsub"><?= esc(lang('Integrations.profiles.defineSub')) ?></p>
            <form class="form" method="post" action="/integrations/profiles">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div>
                    <label for="f-code"><?= esc(lang('Integrations.profiles.codeLbl')) ?></label>
                    <input id="f-code" name="code" required maxlength="80" placeholder="<?= esc(lang('Integrations.profiles.codePlaceholder'), 'attr') ?>">
                </div>
                <div>
                    <label for="f-family"><?= esc(lang('Integrations.profiles.familyLbl')) ?></label>
                    <select id="f-family" name="family" required>
                        <?php foreach ($families as $f): ?>
                            <option value="<?= esc((string) $f, 'attr') ?>"><?= esc((string) $f) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="f-op"><?= esc(lang('Integrations.profiles.opLbl')) ?></label>
                    <select id="f-op" name="canonical_op" required>
                        <?php foreach ($operations as $o): ?>
                            <option value="<?= esc((string) $o, 'attr') ?>"><?= esc((string) $o) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="f-method"><?= esc(lang('Integrations.profiles.methodLbl')) ?></label>
                    <select id="f-method" name="http_method">
                        <option value=""><?= esc($none) ?></option>
                        <?php foreach ($methods as $m): ?>
                            <option value="<?= esc((string) $m, 'attr') ?>"><?= esc((string) $m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="f-host"><?= esc(lang('Integrations.profiles.hostLbl')) ?></label>
                    <input id="f-host" name="approved_host" required maxlength="191" placeholder="<?= esc(lang('Integrations.profiles.hostPlaceholder'), 'attr') ?>">
                </div>
                <div>
                    <label for="f-algo"><?= esc(lang('Integrations.profiles.signatureLbl')) ?></label>
                    <select id="f-algo" name="signature_algo">
                        <option value=""><?= esc($none) ?></option>
                        <?php foreach ($algos as $a): ?>
                            <option value="<?= esc((string) $a, 'attr') ?>"><?= esc((string) $a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="f-owner"><?= esc(lang('Integrations.profiles.ownerLbl')) ?></label>
                    <input id="f-owner" name="owner_id" maxlength="36" placeholder="<?= esc(lang('Integrations.profiles.ownerPlaceholder'), 'attr') ?>">
                </div>
                <div class="full">
                    <button class="btn go" type="submit"><?= esc(lang('Integrations.profiles.createBtn')) ?></button>
                </div>
            </form>
        </details>

        <?php if ($profiles === []): ?>
            <p class="empty"><?= esc(lang('Integrations.profiles.empty')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Integrations.profiles.colCode')) ?></th>
                    <th><?= esc(lang('Integrations.profiles.colVersion')) ?></th>
                    <th><?= esc(lang('Integrations.profiles.colFamily')) ?></th>
                    <th><?= esc(lang('Integrations.profiles.colOp')) ?></th>
                    <th><?= esc(lang('Integrations.profiles.colMethod')) ?></th>
                    <th><?= esc(lang('Integrations.profiles.colHost')) ?></th>
                    <th><?= esc(lang('Integrations.profiles.colSignature')) ?></th>
                    <th><?= esc(lang('Integrations.profiles.colStatus')) ?></th>
                    <th><?= esc(lang('Integrations.profiles.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($profiles as $p): ?>
                        <?php
                        $status = (string) ($p['status'] ?? '');
                        $next   = $p['next_status'] ?? null;
                        $terminal = (bool) ($p['is_terminal'] ?? false);
                        $pid    = (string) ($p['id'] ?? '');
                        $pa     = esc($pid, 'attr');
                        ?>
                        <tr>
                            <td class="code"><?= esc((string) ($p['code'] ?? '—')) ?></td>
                            <td>v<?= esc((string) ($p['version'] ?? 1)) ?></td>
                            <td><?= esc((string) ($p['family'] ?? '—')) ?></td>
                            <td><?= esc((string) ($p['canonical_op'] ?? '—')) ?></td>
                            <td><?= esc((string) ($p['http_method'] ?? '') !== '' ? (string) $p['http_method'] : $none) ?></td>
                            <td class="host"><?= esc((string) ($p['approved_host'] ?? '—')) ?></td>
                            <td><?= esc((string) ($p['signature_algo'] ?? '') !== '' ? (string) $p['signature_algo'] : $none) ?></td>
                            <td><span class="pill" style="color:<?= $statusColor($status) ?>;border-color:<?= $statusColor($status) ?>55;"><?= esc($prettyLbl($status)) ?></span></td>
                            <td class="actions">
                                <?php if (! $terminal && $next !== null): ?>
                                    <form method="post" action="/integrations/profiles/<?= $pa ?>/advance">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <input type="hidden" name="to_status" value="<?= esc((string) $next, 'attr') ?>">
                                        <button class="btn go" type="submit"><?= esc($li('Integrations.profiles.advanceBtn', $prettyLbl((string) $next))) ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (! $terminal): ?>
                                    <form method="post" action="/integrations/profiles/<?= $pa ?>/revoke">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <button class="btn ghost" type="submit"><?= esc(lang('Integrations.profiles.revokeBtn')) ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="empty"><?= esc(lang('Integrations.profiles.terminalNote')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
