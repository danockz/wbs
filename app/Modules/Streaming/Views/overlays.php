<?= $this->extend('layouts/app') ?>

<?php
/**
 * Overlay & co-host management console (SRS FR-STR-008). Server-rendered; the
 * same controller returns JSON when negotiated. Moderator-facing companion to
 * the compositor's active-overlay read.
 *
 * Security notes reflected in the UI:
 *  - Co-host join tokens are secrets: we show only whether a live token exists
 *    and when it expires, never the hash/plaintext.
 *  - Overlay config is reviewed DATA of a fixed type vocabulary, not code.
 *  - cause_progress figures are recomputed live from the ledger, so the console
 *    total matches what viewers see — a presenter cannot inject a fake amount.
 *
 * Copy is localized via lang('Streaming.*') with English fallback. Status /
 * role / overlay-type LABELS localize with raw-value fallback; colours and
 * money formatting stay code-driven so translation never affects styling or
 * numbers. Available role/type vocabularies are localized per-item then joined.
 *
 * @var array<string,mixed> $result   payload from OverlayService::console
 * @var string              $streamId
 * @var string              $csrf
 */
$stream   = $result['stream'] ?? [];
$cohosts  = $result['cohosts'] ?? [];
$overlays = $result['overlays'] ?? [];
$types    = $result['overlay_types'] ?? [];
$roles    = $result['cohost_roles'] ?? [];
$streamId = $streamId ?? (string) ($stream['id'] ?? '');
$csrf     = $csrf ?? '';
$sidAttr  = rawurlencode((string) $streamId);
$roster   = is_array($roster ?? null) ? $roster : [];
// Users already invited/joined as co-hosts shouldn't be offered again.
$cohostIds = [];
foreach ($cohosts as $ch) {
    $uid = (string) ($ch['user_id'] ?? '');
    if ($uid !== '') {
        $cohostIds[$uid] = true;
    }
}
$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;
$flashTok = function_exists('session') ? session('cohost_token') : null;

$statusCol = static fn (string $s): string => match ($s) {
    'joined', 'live'         => '#4ade80',
    'removed', 'left', 'ended' => '#f87171',
    'invited', 'draft'       => '#fbbf24',
    default                  => '#a5b4fc',
};

$money = static function (?int $minor, ?string $ccy): string {
    if ($minor === null) {
        return '—';
    }
    return trim(($ccy ? $ccy . ' ' : '') . number_format($minor / 100, 2));
};

$li = static fn (string $key, string $arg): string => str_replace('{0}', $arg, lang($key));
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Streaming.status.' . $s);

    return $t === 'Streaming.status.' . $s ? $s : $t;
};
$accessLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Streaming.access.' . $s);

    return $t === 'Streaming.access.' . $s ? $s : $t;
};
$roleLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Streaming.role.' . $s);

    return $t === 'Streaming.role.' . $s ? $s : $t;
};
$typeLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Streaming.overlayType.' . $s);

    return $t === 'Streaming.overlayType.' . $s ? $s : $t;
};
// Localize each entry of an available-vocabulary list, then join for display.
$joinRoles = static fn (array $list): string => implode(', ', array_map($roleLbl, array_map('strval', $list)));
$joinTypes = static fn (array $list): string => implode(', ', array_map($typeLbl, array_map('strval', $list)));
?>

<?= $this->section('content') ?>
    <h1><?= esc($stream['title'] ?? lang('Streaming.streamFallback')) ?></h1>
    <div class="sub">
        <?= esc(lang('Streaming.overlaysCohosts')) ?> ·
        <span class="pill" style="color:<?= $statusCol((string) ($stream['status'] ?? '')) ?>;"><?= esc($statusLbl((string) ($stream['status'] ?? ''))) ?></span>
        <span class="pill"><?= esc($accessLbl((string) ($stream['access_policy'] ?? ''))) ?></span>
    </div>

    <?php if ($flashOk !== null && $flashOk !== ''): ?>
        <div class="card" style="border-color:#22c55e55;background:#052e1b;color:#bbf7d0"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== null && $flashErr !== ''): ?>
        <div class="card" style="border-color:#ef444455;background:#3f1d1d;color:#fecaca"><?= esc($flashErr) ?></div>
    <?php endif; ?>
    <?php if ($flashTok !== null && $flashTok !== ''): ?>
        <div class="card" style="border-color:#38bdf855;background:#082f49;color:#bae6fd">
            <strong><?= esc(lang('Streaming.overlaysConsole.tokenOnce')) ?></strong>
            <div class="counts" style="font-family:ui-monospace,Menlo,monospace;word-break:break-all;color:#e0f2fe;margin-top:6px"><?= esc((string) $flashTok) ?></div>
        </div>
    <?php endif; ?>

    

    <?php if ($streamId !== ''): ?>
    <div class="oc">
    <?php endif; ?>

    <h2><?= esc(lang('Streaming.cohostsHeading')) ?></h2>

    <?php if ($streamId !== ''): ?>
        <details class="creator">
            <summary><?= esc(lang('Streaming.overlaysConsole.inviteHeading')) ?></summary>
            <form class="fields" method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/cohosts'), 'attr') ?>">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div>
                    <label><?= esc(lang('Streaming.overlaysConsole.userIdLabel')) ?></label>
                    <?php
                    $cohostCandidates = [];
                    foreach ($roster as $u) {
                        $uid = (string) ($u['id'] ?? '');
                        if ($uid !== '' && ! isset($cohostIds[$uid])) {
                            $cohostCandidates[] = $u;
                        }
                    }
                    ?>
                    <?php if ($cohostCandidates !== []): ?>
                        <select name="user_id" required>
                            <option value=""><?= esc(lang('Streaming.overlaysConsole.userIdNone')) ?></option>
                            <?php foreach ($cohostCandidates as $u): ?>
                                <?php $uid = (string) $u['id']; $un = trim((string) ($u['display_name'] ?? '')); ?>
                                <option value="<?= esc($uid, 'attr') ?>"><?= esc($un !== '' ? $un : $uid) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="user_id" required maxlength="64" placeholder="<?= esc(lang('Streaming.overlaysConsole.userIdPh'), 'attr') ?>">
                    <?php endif; ?>
                </div>
                <div>
                    <label><?= esc(lang('Streaming.overlaysConsole.roleLabel')) ?></label>
                    <select name="role">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= esc((string) $r, 'attr') ?>"><?= esc($roleLbl((string) $r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn go" type="submit"><?= esc(lang('Streaming.overlaysConsole.inviteBtn')) ?></button>
            </form>
        </details>
    <?php endif; ?>
    <?php if ($cohosts === []): ?>
        <div class="empty"><?= esc($li('Streaming.noCohosts', $joinRoles($roles))) ?></div>
    <?php else: ?>
        <?php foreach ($cohosts as $ch): ?>
            <div class="card">
                <div class="row">
                    <span class="author">
                        <?= esc($ch['user_id'] ?? '') ?>
                        <span class="pill"><?= esc($roleLbl((string) ($ch['role'] ?? 'cohost'))) ?></span>
                    </span>
                    <span class="pill" style="color:<?= $statusCol((string) ($ch['status'] ?? '')) ?>;"><?= esc($statusLbl((string) ($ch['status'] ?? ''))) ?></span>
                </div>
                <div class="counts">
                    <?php if (! empty($ch['token_active'])): ?>
                        <span style="color:#4ade80;"><?= esc(lang('Streaming.tokenActive')) ?></span> · <?= esc($li('Streaming.tokenExpires', (string) ($ch['token_expires_at'] ?? ''))) ?>
                    <?php elseif (! empty($ch['token_expires_at'])): ?>
                        <span class="warn"><?= esc(lang('Streaming.tokenExpired')) ?></span>
                    <?php else: ?>
                        <span class="muted"><?= esc(lang('Streaming.noToken')) ?></span>
                    <?php endif; ?>
                    <?php if (! empty($ch['joined_at'])): ?> · <?= esc($li('Streaming.joinedAt', (string) $ch['joined_at'])) ?><?php endif; ?>
                </div>
                <?php if ($streamId !== '' && (string) ($ch['status'] ?? '') !== 'removed'): ?>
                    <div class="rowacts">
                        <form method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/cohosts/token'), 'attr') ?>">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="user_id" value="<?= esc((string) ($ch['user_id'] ?? ''), 'attr') ?>">
                            <button class="btn ghost" type="submit"><?= esc(lang('Streaming.overlaysConsole.issueTokenBtn')) ?></button>
                        </form>
                        <form method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/cohosts/' . rawurlencode((string) ($ch['user_id'] ?? '')) . '/remove'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('Streaming.overlaysConsole.removeConfirm'), 'attr') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="user_id" value="<?= esc((string) ($ch['user_id'] ?? ''), 'attr') ?>">
                            <button class="btn danger" type="submit"><?= esc(lang('Streaming.overlaysConsole.removeBtn')) ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2><?= esc(lang('Streaming.overlaysHeading')) ?></h2>

    <?php if ($streamId !== ''): ?>
        <details class="creator">
            <summary><?= esc(lang('Streaming.overlaysConsole.overlayHeading')) ?></summary>
            <form class="fields" method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/overlays'), 'attr') ?>">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div>
                    <label><?= esc(lang('Streaming.overlaysConsole.overlayTypeLabel')) ?></label>
                    <select name="overlay_type">
                        <?php foreach ($types as $t): ?>
                            <option value="<?= esc((string) $t, 'attr') ?>"><?= esc($typeLbl((string) $t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?= esc(lang('Streaming.overlaysConsole.field1Label')) ?></label>
                    <input type="text" name="config[title]" maxlength="120" placeholder="<?= esc(lang('Streaming.overlaysConsole.field1Ph'), 'attr') ?>">
                </div>
                <div>
                    <label><?= esc(lang('Streaming.overlaysConsole.field2Label')) ?></label>
                    <input type="text" name="config[subtitle]" maxlength="160" placeholder="<?= esc(lang('Streaming.overlaysConsole.field2Ph'), 'attr') ?>">
                </div>
                <div>
                    <label><?= esc(lang('Streaming.overlaysConsole.quoteLabel')) ?></label>
                    <input type="text" name="config[quote]" maxlength="500" placeholder="<?= esc(lang('Streaming.overlaysConsole.quotePh'), 'attr') ?>">
                </div>
                <div>
                    <label><?= esc(lang('Streaming.overlaysConsole.attributionLabel')) ?></label>
                    <input type="text" name="config[attribution]" maxlength="120" placeholder="<?= esc(lang('Streaming.overlaysConsole.attributionPh'), 'attr') ?>">
                </div>
                <div>
                    <label><?= esc(lang('Streaming.overlaysConsole.causeIdLabel')) ?></label>
                    <input type="text" name="config[cause_id]" maxlength="64" placeholder="<?= esc(lang('Streaming.overlaysConsole.causeIdPh'), 'attr') ?>">
                </div>
                <button class="btn go" type="submit"><?= esc(lang('Streaming.overlaysConsole.overlayBtn')) ?></button>
            </form>
            <div class="counts" style="margin-top:8px"><?= esc(lang('Streaming.overlaysConsole.overlayHint')) ?></div>
        </details>
    <?php endif; ?>

    <?php if ($overlays === []): ?>
        <div class="empty"><?= esc($li('Streaming.noOverlays', $joinTypes($types))) ?></div>
    <?php else: ?>
        <?php foreach ($overlays as $o): ?>
            <?php $cfg = $o['config'] ?? []; ?>
            <div class="card">
                <div class="row">
                    <span class="author">
                        <?= esc($typeLbl((string) ($o['overlay_type'] ?? ''))) ?>
                        <span class="muted"><?= esc($li('Streaming.versionTag', (string) ((int) ($o['version'] ?? 1)))) ?></span>
                    </span>
                    <?php if (! empty($o['visible'])): ?>
                        <span class="pill" style="color:#4ade80;"><?= esc(lang('Streaming.live')) ?></span>
                    <?php else: ?>
                        <span class="pill"><?= esc(lang('Streaming.hidden')) ?></span>
                    <?php endif; ?>
                </div>

                <div class="counts">
                    <?php if (($o['overlay_type'] ?? '') === 'lower_third'): ?>
                        <strong><?= esc($cfg['title'] ?? '') ?></strong>
                        <?php if (! empty($cfg['subtitle'])): ?> — <?= esc($cfg['subtitle']) ?><?php endif; ?>
                    <?php elseif (($o['overlay_type'] ?? '') === 'quote_card'): ?>
                        “<?= esc($cfg['quote'] ?? '') ?>”
                        <?php if (! empty($cfg['attribution'])): ?> — <?= esc($cfg['attribution']) ?><?php endif; ?>
                    <?php elseif (($o['overlay_type'] ?? '') === 'cause_progress'): ?>
                        <?= esc($cfg['label'] ?? ($cfg['cause_name'] ?? lang('Streaming.causeFallback'))) ?>:
                        <strong><?= esc($money($cfg['raised_minor'] ?? null, $cfg['currency'] ?? null)) ?></strong>
                        <?php if (($cfg['target_minor'] ?? null) !== null): ?>
                            / <?= esc($money($cfg['target_minor'], $cfg['currency'] ?? null)) ?>
                            <?php if (($cfg['percent'] ?? null) !== null): ?>
                                <span class="pill"><?= (int) $cfg['percent'] ?>%</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <span class="muted"><?= esc(lang('Streaming.liveFromLedger')) ?></span>
                    <?php endif; ?>
                </div>
                <div class="time"><?= esc($li('Streaming.updatedAt', (string) ($o['updated_at'] ?? ''))) ?></div>
                <?php if ($streamId !== '' && ! empty($o['overlay_id'])): ?>
                    <div class="rowacts">
                        <form method="post" action="<?= esc(base_url('streams/overlays/' . rawurlencode((string) $o['overlay_id']) . '/visibility'), 'attr') ?>">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="stream_id" value="<?= esc((string) $streamId, 'attr') ?>">
                            <input type="hidden" name="visible" value="<?= ! empty($o['visible']) ? '0' : '1' ?>">
                            <button class="btn <?= ! empty($o['visible']) ? 'ghost' : 'ok' ?>" type="submit">
                                <?= esc(! empty($o['visible']) ? lang('Streaming.overlaysConsole.hideBtn') : lang('Streaming.overlaysConsole.showBtn')) ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($streamId !== ''): ?>
    </div>
    <?php endif; ?>

    <div class="meta">
        <?= esc($li('Streaming.consoleNote', (string) ($stream['id'] ?? ''))) ?>
    </div>
<?= $this->endSection() ?>
