<?= $this->extend('layouts/app') ?>

<?php
/**
 * Authenticated group detail (SRS FR-GRP-*): the group's ancestry chain and its
 * descendant subtree. Server-rendered; the same controller returns JSON when
 * negotiated. PII-free — group id/name/depth only.
 *
 * The page also carries a small GOVERNANCE CONSOLE: a "set classification (kind)"
 * form and an "edit public profile" form (theme + join policy + presentational/
 * contact fields). Both are no-JS PRG posts to webcsrf-guarded routes using the
 * `_csrf` field; on return a flashed banner shows. Governance-gated server-side.
 *
 * @var array<string,mixed>      $result  {group_id, ancestors:[...], descendants:[...]}
 * @var string                   $title
 * @var string                   $groupId the group id (for the write routes)
 * @var list<array<string,mixed>> $kinds  active group-kind rows (for the picker)
 * @var list<string>             $themes  allowed public hero themes
 * @var string                   $csrf    webcsrf token for the inline forms
 */
$gid         = (string) ($result['group_id'] ?? '');
$ancestors   = $result['ancestors'] ?? [];
$descendants = $result['descendants'] ?? [];
$groupId     = $groupId ?? $gid;
$kinds       = $kinds ?? [];
$themes      = $themes ?? ['aurora', 'sunrise', 'forest', 'slate'];
$csrf        = $csrf ?? '';
$sidAttr     = $groupId !== '' ? rawurlencode($groupId) : '';
$nameOf      = static fn (array $g): string => (string) ($g['name'] ?? $g['id'] ?? $g['group_id'] ?? lang('Groups.detail.groupFallback'));

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Groups.detail.title')) ?></h1>
    <div class="sub"><?= esc($gid) ?></div>

    <?php if ($flashOk !== ''): ?><div class="card" style="border-color:#166534;color:#86efac"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="card" style="border-color:#7f1d1d;color:#fca5a5"><?= esc($flashErr) ?></div><?php endif; ?>

    <h2><?= esc(lang('Groups.detail.ancestry')) ?></h2>
    <?php if ($ancestors === []): ?>
        <div class="empty"><?= esc(lang('Groups.detail.topLevel')) ?></div>
    <?php else: ?>
        <div class="card">
            <?php foreach ($ancestors as $i => $a): ?>
                <?= $i > 0 ? ' <span class="muted">→</span> ' : '' ?><span class="pill"><?= esc($nameOf((array) $a)) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2><?= esc(str_replace('{0}', (string) count($descendants), lang('Groups.detail.descendants'))) ?></h2>
    <?php if ($descendants === []): ?>
        <div class="empty"><?= esc(lang('Groups.detail.noSubgroups')) ?></div>
    <?php else: ?>
        <?php foreach ($descendants as $d): ?>
            <?php $d = (array) $d; ?>
            <div class="card">
                <div class="row">
                    <span class="author" style="padding-left:<?= min(6, (int) ($d['depth'] ?? 0)) * 14 ?>px;"><?= esc($nameOf($d)) ?></span>
                    <?php if (isset($d['depth'])): ?><span class="time"><?= esc(str_replace('{0}', (string) (int) $d['depth'], lang('Groups.detail.depth'))) ?></span><?php endif; ?>
                </div>
                <?php if (! empty($d['kind_code']) || ! empty($d['member_count'])): ?>
                    <div class="counts">
                        <?php if (! empty($d['kind_code'])): ?><?= esc($d['kind_code']) ?><?php endif; ?>
                        <?php if (! empty($d['member_count'])): ?> · <?= esc(str_replace('{0}', (string) (int) $d['member_count'], lang('Groups.detail.members'))) ?><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($groupId !== ''): ?>
        <h2><?= esc(lang('Groups.detail.manageHeading')) ?></h2>

        <form class="card" method="post" action="/groups/<?= esc($sidAttr, 'attr') ?>/kind" style="display:grid;gap:8px;max-width:460px">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <strong><?= esc(lang('Groups.detail.kindHeading')) ?></strong>
            <label for="d-kind" class="muted" style="font-size:.85rem"><?= esc(lang('Groups.detail.kindLabel')) ?></label>
            <select id="d-kind" name="kind_code">
                <option value=""><?= esc(lang('Groups.detail.kindNone')) ?></option>
                <?php foreach ($kinds as $k): ?>
                    <option value="<?= esc((string) ($k['code'] ?? ''), 'attr') ?>"><?= esc((string) ($k['name'] ?? $k['code'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><?= esc(lang('Groups.detail.kindBtn')) ?></button>
        </form>

        <form class="card" method="post" action="/groups/<?= esc($sidAttr, 'attr') ?>/profile" style="display:grid;gap:8px;max-width:460px">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <strong><?= esc(lang('Groups.detail.profileHeading')) ?></strong>
            <label for="d-theme" class="muted" style="font-size:.85rem"><?= esc(lang('Groups.detail.themeLabel')) ?></label>
            <select id="d-theme" name="hero_theme">
                <?php foreach ($themes as $t): ?><option value="<?= esc($t, 'attr') ?>"><?= esc($t) ?></option><?php endforeach; ?>
            </select>
            <label for="d-join" class="muted" style="font-size:.85rem"><?= esc(lang('Groups.detail.joinLabel')) ?></label>
            <select id="d-join" name="join_policy">
                <option value="open"><?= esc(lang('Groups.detail.joinOpen')) ?></option>
                <option value="approval"><?= esc(lang('Groups.detail.joinApproval')) ?></option>
            </select>
            <label for="d-tag" class="muted" style="font-size:.85rem"><?= esc(lang('Groups.detail.taglineLabel')) ?></label>
            <input type="text" id="d-tag" name="tagline" maxlength="255">
            <label for="d-email" class="muted" style="font-size:.85rem"><?= esc(lang('Groups.detail.emailLabel')) ?></label>
            <input type="email" id="d-email" name="contact_email" maxlength="255">
            <button type="submit"><?= esc(lang('Groups.detail.profileBtn')) ?></button>
        </form>
    <?php endif; ?>
<?= $this->endSection() ?>
