<?= $this->extend('layouts/app') ?>

<?php
/**
 * Community feed (SRS FR-COM-001). Rendered server-side; the same controller
 * returns JSON when negotiated. Post bodies were sanitized at write time into
 * body_html (allowlist), so they are output without re-escaping; all other
 * fields are escaped.
 *
 * Copy is localized via lang('Community.*') with English as the guaranteed
 * fallback; count/plural chosen in PHP (no ICU {} runtime dependency). Post
 * author id, timestamps, title and the sanitized body_html stay VERBATIM (data,
 * not UI copy). The visibility LABEL localizes but falls back to the raw stored
 * value for unknown values; reaction/comment counts are rendered as-is.
 *
 * The feed also carries the member WRITE affordances — compose a post, comment,
 * react, report — plus a moderator hide/lock/delete control (only when
 * $canModerate). Each form POSTs to a webcsrf-guarded route; the controller PRG-
 * redirects back here with a localized flash. No-JS friendly.
 *
 * @var array<string,mixed> $result       service payload: {posts:[], count:int}
 * @var string              $csrf         webcsrf double-submit token
 * @var bool                $canModerate  viewer holds community.moderate (one PDP call)
 * @var bool                $isAuthed     viewer is authenticated (may author/react)
 */
$posts       = $result['posts'] ?? [];
$csrf        = $csrf ?? '';
$canModerate = $canModerate ?? false;
$isAuthed    = $isAuthed ?? false;
$flashOk     = function_exists('session') ? session('success') : null;
$flashErr    = function_exists('session') ? session('error') : null;
$visibilities  = ['group', 'hierarchy', 'public', 'private'];
$modActions    = ['hide', 'lock', 'unlock', 'restore', 'delete', 'escalate'];
$reasonCodes   = ['spam', 'harassment', 'inappropriate', 'misinformation', 'other'];
// Localized enum label with raw-value fallback.
$visibilityLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Community.visibility.' . $s);

    return $t === 'Community.visibility.' . $s ? $s : $t;
};
$enumLbl = static function (string $group, string $key): string {
    if ($key === '') {
        return '';
    }
    $t = lang("Community.$group.$key");

    return $t === "Community.$group.$key" ? ucfirst(str_replace('_', ' ', $key)) : $t;
};
?>

<?= $this->section('content') ?>
    <style>


        .cf-flash { border-radius:10px; padding:11px 14px; margin:0 0 16px; font-size:.9rem; }


        .cf-flash.ok { background:#052e1b; border:1px solid #22c55e55; color:#bbf7d0; }


        .cf-flash.err { background:#3f1d1d; border:1px solid #ef444455; color:#fecaca; }


        .cf-field label { display:block; font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin-bottom:4px; }


        .cf-btn { background:#0284c7; border:1px solid #0ea5e9; color:#fff; border-radius:8px; padding:8px 14px; font-size:.82rem; font-weight:600; cursor:pointer; }


        .cf-btn:hover { background:#0369a1; }


        .cf-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px; padding-top:10px; border-top:1px solid #1e293b; }


        .cf-actions form { display:flex; gap:5px; align-items:center; flex-wrap:wrap; }


        .cf-actions .cf-inline input[type=text] { width:auto; }
</style>

    <h1><?= esc(lang('Community.title')) ?></h1>
    <div class="sub"><?= count($posts) ?> <?= esc(count($posts) === 1 ? lang('Community.post') : lang('Community.posts')) ?> <?= esc(lang('Community.visibleToYou')) ?></div>

    <?php if ($flashOk !== null && $flashOk !== ''): ?>
        <div class="cf-flash ok"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== null && $flashErr !== ''): ?>
        <div class="cf-flash err"><?= esc($flashErr) ?></div>
    <?php endif; ?>

    <?php if ($isAuthed): ?>
        <details class="cf-compose">
            <summary><?= esc(lang('Community.admin.composeHeading')) ?></summary>
            <form method="post" action="/community/posts">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div class="cf-field">
                    <label><?= esc(lang('Community.admin.titleLabel')) ?></label>
                    <input type="text" name="title" maxlength="200" placeholder="<?= esc(lang('Community.admin.titlePh'), 'attr') ?>">
                </div>
                <div class="cf-field">
                    <label><?= esc(lang('Community.admin.bodyLabel')) ?></label>
                    <textarea name="body" rows="3" required placeholder="<?= esc(lang('Community.admin.bodyPh'), 'attr') ?>"></textarea>
                </div>
                <div class="cf-field">
                    <label><?= esc(lang('Community.admin.visibilityLabel')) ?></label>
                    <select name="visibility">
                        <?php foreach ($visibilities as $vis): ?>
                            <option value="<?= esc($vis, 'attr') ?>" <?= $vis === 'group' ? 'selected' : '' ?>><?= esc($visibilityLbl($vis)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cf-field">
                    <button class="cf-btn" type="submit"><?= esc(lang('Community.admin.postBtn')) ?></button>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <?php if ($posts === []): ?>
        <div class="empty"><?= esc(lang('Community.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($posts as $p): ?>
            <?php $pid = (string) ($p['id'] ?? ''); ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc($p['author_id'] ?? lang('Community.memberFallback')) ?></span>
                    <span class="time"><?= esc($p['created_at'] ?? '') ?></span>
                </div>
                <?php if (! empty($p['title'])): ?>
                    <div style="font-weight:700;margin:6px 0;"><?= esc($p['title']) ?></div>
                <?php endif; ?>
                <div style="margin-top:6px;line-height:1.5;"><?= $p['body_html'] ?? '' ?></div>
                <div class="counts">
                    <span class="pill"><?= esc($visibilityLbl((string) ($p['visibility'] ?? 'group'))) ?></span>
                    &nbsp;♥ <?= (int) ($p['reaction_count'] ?? 0) ?>
                    &nbsp;💬 <?= (int) ($p['comment_count'] ?? 0) ?>
                    <?php if (! empty($p['pinned'])): ?> &nbsp;<?= esc(lang('Community.pinned')) ?><?php endif; ?>
                </div>

                <?php if ($isAuthed && $pid !== ''): ?>
                    <?php $penc = rawurlencode($pid); ?>
                    <div class="cf-actions">
                        <!-- React -->
                        <form method="post" action="/community/posts/<?= esc($penc, 'attr') ?>/reactions">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="reaction" value="like">
                            <button class="cf-btn ghost" type="submit">♥ <?= esc(lang('Community.admin.reactBtn')) ?></button>
                        </form>
                        <!-- Comment -->
                        <form class="cf-inline" method="post" action="/community/posts/<?= esc($penc, 'attr') ?>/comments">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="text" name="body" required placeholder="<?= esc(lang('Community.admin.commentPh'), 'attr') ?>">
                            <button class="cf-btn ghost" type="submit"><?= esc(lang('Community.admin.commentBtn')) ?></button>
                        </form>
                        <!-- Report -->
                        <form class="cf-inline" method="post" action="/community/reports"
                              onsubmit="return confirm('<?= esc(lang('Community.admin.reportConfirm'), 'attr') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="subject_type" value="post">
                            <input type="hidden" name="subject_id" value="<?= esc($pid, 'attr') ?>">
                            <select name="reason_code" aria-label="<?= esc(lang('Community.admin.reasonLabel'), 'attr') ?>">
                                <?php foreach ($reasonCodes as $rc): ?>
                                    <option value="<?= esc($rc, 'attr') ?>"><?= esc($enumLbl('reason', $rc)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="cf-btn danger" type="submit"><?= esc(lang('Community.admin.reportBtn')) ?></button>
                        </form>
                    </div>

                    <?php if ($canModerate): ?>
                        <div class="cf-mod">
                            <form class="cf-inline" method="post" action="/community/moderate"
                                  onsubmit="return confirm('<?= esc(lang('Community.admin.moderateConfirm'), 'attr') ?>');">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <input type="hidden" name="subject_type" value="post">
                                <input type="hidden" name="subject_id" value="<?= esc($pid, 'attr') ?>">
                                <select name="action" aria-label="<?= esc(lang('Community.admin.actionLabel'), 'attr') ?>">
                                    <?php foreach ($modActions as $ma): ?>
                                        <option value="<?= esc($ma, 'attr') ?>"><?= esc($enumLbl('action', $ma)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="reason" required placeholder="<?= esc(lang('Community.admin.moderateReasonPh'), 'attr') ?>">
                                <button class="cf-btn danger" type="submit"><?= esc(lang('Community.admin.moderateBtn')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
