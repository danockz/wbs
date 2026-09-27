<?php
/**
 * GROUP NOTIFICATION CREDENTIALS (GET /notifications/credentials) — the browser
 * face of GroupCredentialController::index.
 *
 * The question this page answers is the one the pipeline asks at send time: whose
 * provider account does THIS body's SMS go out on? Each hierarchical group
 * supplies its own account (adapter, sender identity, encrypted write-only
 * secrets) and decides whether its subtree may send on it — one subgroup, the
 * whole subtree, or a hand-picked list — so the page has three parts:
 *
 *  1. the EFFECTIVE CHAIN for a selected group, computed by the same resolver a
 *     real send uses (`SmsProviderChain::planFor()`), including accounts shared
 *     down from an ancestor and marked as such. When it is empty the page says so
 *     plainly, because that is a refusal, not a fallback: no body ever sends on
 *     credentials it did not provide or was granted;
 *  2. the accounts this leader MANAGES, each with a write-only secret form (the
 *     stored value is never echoed — only which slots exist) and its sharing
 *     panel: who may send on it, how far below them that reaches, and a revoke;
 *  3. an "add an account" form for any group in the leader's own scope.
 *
 * Provider testing, approval and activation stay on the Integrations connections
 * page (linked below) so the connection lifecycle has one home; nothing here
 * duplicates that logic.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). Copy is localized via
 * lang('Notifications.credentials.*') with a humanized raw-value fallback for
 * server vocabularies (status, scope_mode, capability). NO secret material is
 * present in the payload.
 *
 * @var list<array<string,mixed>>            $connections accounts this actor manages
 * @var array<string,list<array<string,mixed>>> $grants    connectionId => grants
 * @var list<array<string,mixed>>            $groups      groups in the actor's scope
 * @var array<string,string>                 $groupNames  id => display name
 * @var string                               $selected    group the chain is shown for
 * @var list<array<string,mixed>>            $plan        effective chain for $selected
 * @var list<array<string,mixed>>            $adapters    sms-capable catalogue adapters
 * @var list<string>                         $scopes      ScopeMode vocabulary
 * @var list<string>                         $capabilities
 * @var array<string,list<string>>           $slots       adapter => secret slot names
 * @var string                               $csrf
 */
$connections  = $connections ?? [];
$grants       = $grants ?? [];
$groups       = $groups ?? [];
$groupNames   = $groupNames ?? [];
$selected     = $selected ?? '';
$plan         = $plan ?? [];
$adapters     = $adapters ?? [];
$scopes       = $scopes ?? ['self', 'self_and_descendants', 'descendants_only', 'groups'];
$capabilities = $capabilities ?? ['sms.send'];
$slots        = $slots ?? [];
$csrf         = $csrf ?? '';

include __DIR__ . '/_locale.php';

$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;

/** Localize a server vocabulary token, falling back to a humanized raw value. */
$lbl = static function (string $prefix, string $value) use ($li): string {
    if ($value === '') {
        return '';
    }
    $key = 'Notifications.credentials.' . $prefix . '.' . $value;
    $v   = lang($key);

    return $v === $key ? ucwords(str_replace('_', ' ', $value)) : $v;
};
$groupName = static fn (string $id): string => $groupNames[$id] ?? ($id === '' ? '' : $id);
$indent    = static function (array $g): string {
    $depth = max(0, (int) ($g['depth'] ?? 0));

    return str_repeat('— ', $depth);
};

$statusColor = static fn (string $s): string => match ($s) {
    'active'           => '#22c55e',
    'pending_approval' => '#f59e0b',
    'tested'           => '#38bdf8',
    'draft'            => '#94a3b8',
    'disabled'         => '#ef4444',
    'revoked'          => '#ef4444',
    default            => '#94a3b8',
};
$count = count($connections);
?>

<?php ob_start(); ?>
<?= esc(lang('Notifications.credentials.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        
        h2 { font-size:1.02rem; margin:0 0 6px; }

        select:focus, input:focus { outline:none; border-color:#38bdf8; }

        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:14px; }

        .meta { font-size:.74rem; color:#94a3b8; margin:6px 0 2px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
    <h1><?= esc(lang('Notifications.credentials.heading')) ?></h1>
    <p class="sub"><?= esc(lang('Notifications.credentials.sub')) ?></p>

    <?php if ($flashOk !== null && $flashOk !== ''): ?>
        <div class="flash ok"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== null && $flashErr !== ''): ?>
        <div class="flash err"><?= esc($flashErr) ?></div>
    <?php endif; ?>

    <!-- ── 1. Effective chain for one group ─────────────────────────────── -->
    <section class="panel">
        <h2><?= esc(lang('Notifications.credentials.planHeading')) ?></h2>
        <p class="hint" style="margin-top:0"><?= esc(lang('Notifications.credentials.planSub')) ?></p>

        <form class="picker" method="get" action="/notifications/credentials">
            <div>
                <label for="grp"><?= esc(lang('Notifications.credentials.groupLabel')) ?></label>
                <select id="grp" name="group" onchange="this.form.submit()">
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= esc((string) ($g['id'] ?? ''), 'attr') ?>" <?= ((string) ($g['id'] ?? '') === $selected) ? 'selected' : '' ?>>
                            <?= esc($indent($g) . $groupName((string) ($g['id'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button class="btn ghost" type="submit"><?= esc(lang('Notifications.credentials.showBtn')) ?></button></noscript>
        </form>

        <?php if ($plan === []): ?>
            <div class="panel warn" style="margin:0">
                <b><?= esc(lang('Notifications.credentials.planEmpty')) ?></b>
                <div class="hint"><?= esc(lang('Notifications.credentials.planFailClosed')) ?></div>
            </div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th><?= esc(lang('Notifications.credentials.colOrder')) ?></th>
                    <th><?= esc(lang('Notifications.credentials.colProvider')) ?></th>
                    <th><?= esc(lang('Notifications.credentials.colOwner')) ?></th>
                    <th><?= esc(lang('Notifications.credentials.colSender')) ?></th>
                    <th><?= esc(lang('Notifications.credentials.colUsable')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($plan as $i => $hop): ?>
                    <?php
                    $via   = (string) ($hop['via'] ?? '');
                    $owner = (string) ($hop['owner_group_id'] ?? '');
                    $usable = (bool) ($hop['usable'] ?? false);
                    ?>
                    <tr>
                        <td><?= (int) $i + 1 ?></td>
                        <td><?= esc((string) ($hop['provider'] ?? '')) ?></td>
                        <td>
                            <span class="chip <?= $via === 'own' ? 'own' : 'granted' ?>"><?= esc($lbl('via', $via)) ?></span>
                            <?= esc($owner === '' ? lang('Notifications.credentials.orgWide') : $groupName($owner)) ?>
                            <?php if ($via === 'granted'): ?>
                                <div class="hint" style="margin-top:3px"><?= esc($lbl('scope', (string) ($hop['scope_mode'] ?? 'self'))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= esc((string) ($hop['sender_id'] ?? '—')) ?></td>
                        <td>
                            <span class="<?= $usable ? 'ok-dot' : 'no-dot' ?>">
                                <?= esc($usable ? lang('Notifications.credentials.usable') : lang('Notifications.credentials.unusable')) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="hint"><?= esc(lang('Notifications.credentials.planHint')) ?></div>
        <?php endif; ?>
    </section>

    <!-- ── 2. Accounts this leader manages ──────────────────────────────── -->
    <h2><?= esc(lang('Notifications.credentials.accountsHeading')) ?></h2>
    <?php if ($connections === []): ?>
        <p class="empty"><?= esc(lang('Notifications.credentials.accountsEmpty')) ?></p>
    <?php else: ?>
        <p class="count"><?= esc($li($count === 1 ? 'Notifications.credentials.countOne' : 'Notifications.credentials.count', (string) $count)) ?></p>
        <?php foreach ($connections as $c): ?>
            <?php
            $id      = (string) ($c['id'] ?? '');
            $idAttr  = esc(rawurlencode($id), 'attr');
            $code    = (string) ($c['adapter_code'] ?? '');
            $dn      = (string) ($c['display_name'] ?? $code);
            $status  = (string) ($c['status'] ?? '');
            $owner   = (string) ($c['group_id'] ?? '');
            $sender  = (string) ($c['sender_identity'] ?? '');
            $sColor  = $statusColor($status);
            $myGrants = $grants[$id] ?? [];
            $slotList = $slots[$code] ?? ['api_key'];
            $isActive = $status === 'active';
            // Non-secret wire config: the only per-account preference the chain
            // reads is which provider this body tries first.
            $rawSet   = $c['settings'] ?? null;
            $settings = is_array($rawSet)
                ? $rawSet
                : (is_string($rawSet) && $rawSet !== '' ? (json_decode($rawSet, true) ?: []) : []);
            $order    = trim((string) ($settings['provider_order'] ?? ''));
            ?>
            <article class="card">
                <div class="top">
                    <span class="dn"><?= esc($dn) ?></span>
                    <span class="chip" style="color:<?= esc($sColor, 'attr') ?>;border-color:<?= esc($sColor, 'attr') ?>55;margin-inline-start:auto">
                        <?= esc($lbl('status', $status)) ?>
                    </span>
                </div>
                <div class="code"><?= esc($code) ?></div>
                <div class="meta">
                    <b><?= esc(lang('Notifications.credentials.ownerLabel')) ?>:</b>
                    <?= esc($owner === '' ? lang('Notifications.credentials.orgWide') : $groupName($owner)) ?>
                    <?php if ($sender !== ''): ?>
                        · <b><?= esc(lang('Notifications.credentials.senderLabel')) ?>:</b> <?= esc($sender) ?>
                    <?php endif; ?>
                </div>
                <div class="meta">
                    <b><?= esc(lang('Notifications.credentials.slotsLabel')) ?>:</b>
                    <?= esc(implode(', ', $slotList)) ?>
                    <span class="hint" style="display:inline"> — <?= esc(lang('Notifications.credentials.writeOnly')) ?></span>
                </div>
                <?php if ($order !== ''): ?>
                    <div class="meta">
                        <b><?= esc(lang('Notifications.credentials.orderCurrent')) ?>:</b>
                        <?= esc($order) ?>
                    </div>
                <?php endif; ?>

                <!-- Write-only secret: stored, never echoed back -->
                <?php if (in_array($status, ['draft', 'tested', 'pending_approval', 'active'], true)): ?>
                    <form class="form" method="post" action="/notifications/credentials/connections/<?= $idAttr ?>/secrets">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <div>
                            <label for="slot-<?= $idAttr ?>"><?= esc(lang('Notifications.credentials.slotLabel')) ?></label>
                            <select id="slot-<?= $idAttr ?>" name="slot">
                                <?php foreach ($slotList as $slotName): ?>
                                    <option value="<?= esc($slotName, 'attr') ?>"><?= esc(str_replace('_', ' ', $slotName)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="sec-<?= $idAttr ?>"><?= esc(lang('Notifications.credentials.secretLabel')) ?></label>
                            <input id="sec-<?= $idAttr ?>" type="password" name="secret" required autocomplete="off"
                                   placeholder="<?= esc(lang('Notifications.credentials.secretPh'), 'attr') ?>">
                        </div>
                        <div>
                            <button class="btn ghost" type="submit"><?= esc(lang('Notifications.credentials.storeBtn')) ?></button>
                        </div>
                        <div class="full hint"><?= esc(lang('Notifications.credentials.rotateHint')) ?></div>
                    </form>
                <?php endif; ?>

                <!-- Sharing: who may send on this account, and how far down -->
                <div class="share">
                    <b><?= esc(lang('Notifications.credentials.sharedHeading')) ?></b>
                    <?php if ($myGrants === []): ?>
                        <div class="hint"><?= esc(lang('Notifications.credentials.noGrants')) ?></div>
                    <?php else: ?>
                        <table>
                            <thead>
                            <tr>
                                <th><?= esc(lang('Notifications.credentials.colGrantee')) ?></th>
                                <th><?= esc(lang('Notifications.credentials.colReach')) ?></th>
                                <th><?= esc(lang('Notifications.credentials.colCapability')) ?></th>
                                <th><?= esc(lang('Notifications.credentials.colWindow')) ?></th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($myGrants as $g): ?>
                                <?php
                                $gid    = (string) ($g['id'] ?? '');
                                $mode   = (string) ($g['scope_mode'] ?? 'self');
                                $set    = $g['groups'] ?? [];
                                $cross  = ! empty($g['include_crosscut']);
                                $from   = (string) ($g['starts_at'] ?? '');
                                $until  = (string) ($g['expires_at'] ?? '');
                                $revoked = (string) ($g['status'] ?? '') !== 'active';
                                ?>
                                <tr>
                                    <td><?= esc($groupName((string) ($g['grantee_group_id'] ?? ''))) ?></td>
                                    <td>
                                        <?= esc($lbl('scope', $mode)) ?>
                                        <?php if ($mode === 'groups' && is_array($set) && $set !== []): ?>
                                            <div class="hint" style="margin-top:3px">
                                                <?= esc(implode(', ', array_map($groupName, $set))) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($cross): ?>
                                            <div class="hint" style="margin-top:3px"><?= esc(lang('Notifications.credentials.crosscutOn')) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= esc((string) ($g['capability'] ?? '')) ?></td>
                                    <td>
                                        <?= esc($from === '' ? '—' : substr($from, 0, 10)) ?> →
                                        <?= esc($until === '' ? lang('Notifications.credentials.noEnd') : substr($until, 0, 10)) ?>
                                        <?php if ($revoked): ?>
                                            <div class="hint" style="margin-top:3px"><?= esc($lbl('status', (string) ($g['status'] ?? ''))) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (! $revoked): ?>
                                            <form method="post" action="/notifications/credentials/grants/<?= esc(rawurlencode($gid), 'attr') ?>/revoke"
                                                  onsubmit="return confirm('<?= esc(lang('Notifications.credentials.revokeConfirm'), 'attr') ?>');">
                                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                                <input type="hidden" name="connection_id" value="<?= $idAttr ?>">
                                                <button class="btn danger" type="submit"><?= esc(lang('Notifications.credentials.revokeBtn')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <!-- Share with part of my subtree -->
                    <?php if ($isActive): ?>
                        <form class="form" method="post" action="/notifications/credentials/connections/<?= $idAttr ?>/grants">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <div>
                                <label for="gr-<?= $idAttr ?>"><?= esc(lang('Notifications.credentials.granteeLabel')) ?></label>
                                <select id="gr-<?= $idAttr ?>" name="grantee_group_id" required>
                                    <?php foreach ($groups as $g): ?>
                                        <option value="<?= esc((string) ($g['id'] ?? ''), 'attr') ?>">
                                            <?= esc($indent($g) . $groupName((string) ($g['id'] ?? ''))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="cap-<?= $idAttr ?>"><?= esc(lang('Notifications.credentials.capabilityLabel')) ?></label>
                                <select id="cap-<?= $idAttr ?>" name="capability">
                                    <?php foreach ($capabilities as $cap): ?>
                                        <option value="<?= esc($cap, 'attr') ?>"><?= esc($cap) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="full">
                                <label><?= esc(lang('Notifications.credentials.scopeLabel')) ?></label>
                                <div class="radios">
                                    <?php foreach ($scopes as $i => $mode): ?>
                                        <label>
                                            <input type="radio" name="scope_mode" value="<?= esc($mode, 'attr') ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                            <?= esc($lbl('scope', $mode)) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="hint"><?= esc(lang('Notifications.credentials.scopeHint')) ?></div>
                            </div>
                            <div class="full">
                                <label for="set-<?= $idAttr ?>"><?= esc(lang('Notifications.credentials.groupsLabel')) ?></label>
                                <select id="set-<?= $idAttr ?>" name="groups[]" multiple size="4">
                                    <?php foreach ($groups as $g): ?>
                                        <option value="<?= esc((string) ($g['id'] ?? ''), 'attr') ?>">
                                            <?= esc($indent($g) . $groupName((string) ($g['id'] ?? ''))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hint"><?= esc(lang('Notifications.credentials.groupsHint')) ?></div>
                            </div>
                            <div class="full">
                                <label style="display:flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0;font-size:.8rem;color:#cbd5e1">
                                    <input type="checkbox" name="include_crosscut" value="1" style="width:auto">
                                    <?= esc(lang('Notifications.credentials.crosscutLabel')) ?>
                                </label>
                                <div class="hint"><?= esc(lang('Notifications.credentials.crosscutHint')) ?></div>
                            </div>
                            <div>
                                <label for="st-<?= $idAttr ?>"><?= esc(lang('Notifications.credentials.startsLabel')) ?></label>
                                <input id="st-<?= $idAttr ?>" type="date" name="starts_at">
                            </div>
                            <div>
                                <label for="ex-<?= $idAttr ?>"><?= esc(lang('Notifications.credentials.expiresLabel')) ?></label>
                                <input id="ex-<?= $idAttr ?>" type="date" name="expires_at">
                            </div>
                            <div class="full">
                                <button class="btn go" type="submit"><?= esc(lang('Notifications.credentials.grantBtn')) ?></button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="hint"><?= esc(lang('Notifications.credentials.grantNeedsActive')) ?></div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- ── 3. Add an account for a group I lead ─────────────────────────── -->
    <details class="reg">
        <summary><?= esc(lang('Notifications.credentials.addHeading')) ?></summary>
        <?php if ($adapters === [] || $groups === []): ?>
            <div style="padding:4px 16px 18px" class="hint"><?= esc(lang('Notifications.credentials.addUnavailable')) ?></div>
        <?php else: ?>
            <form class="form" style="padding:4px 16px 18px" method="post" action="/notifications/credentials/connections">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div>
                    <label for="newgrp"><?= esc(lang('Notifications.credentials.groupPickLabel')) ?></label>
                    <select id="newgrp" name="group_id" required>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= esc((string) ($g['id'] ?? ''), 'attr') ?>">
                                <?= esc($indent($g) . $groupName((string) ($g['id'] ?? ''))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="newadp"><?= esc(lang('Notifications.credentials.adapterLabel')) ?></label>
                    <select id="newadp" name="adapter_code" required>
                        <?php foreach ($adapters as $a): ?>
                            <option value="<?= esc((string) ($a['code'] ?? ''), 'attr') ?>">
                                <?= esc((string) ($a['display_name'] ?? $a['code'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="newdn"><?= esc(lang('Notifications.credentials.displayNameLabel')) ?></label>
                    <input id="newdn" name="display_name" maxlength="150"
                           placeholder="<?= esc(lang('Notifications.credentials.displayNamePh'), 'attr') ?>">
                </div>
                <div>
                    <label for="newsender"><?= esc(lang('Notifications.credentials.senderLabel')) ?></label>
                    <input id="newsender" name="sender_identity" maxlength="11"
                           placeholder="<?= esc(lang('Notifications.credentials.senderPh'), 'attr') ?>">
                </div>
                <div>
                    <label for="neworder"><?= esc(lang('Notifications.credentials.orderLabel')) ?></label>
                    <input id="neworder" name="provider_order" maxlength="120"
                           placeholder="<?= esc(lang('Notifications.credentials.orderPh'), 'attr') ?>">
                    <span class="hint"><?= esc(lang('Notifications.credentials.orderHint')) ?></span>
                </div>
                <div class="full hint"><?= esc(lang('Notifications.credentials.addHint')) ?></div>
                <div class="full">
                    <button class="btn go" type="submit"><?= esc(lang('Notifications.credentials.addBtn')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </details>

    <p class="hint">
        <?= esc(lang('Notifications.credentials.lifecycleHint')) ?>
        <a class="lnk" href="/integrations/connections"><?= esc(lang('Notifications.credentials.lifecycleLink')) ?></a>
    </p>
</main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
