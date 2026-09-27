<?php
/**
 * MEDIA review CONSOLE (GET /events/{id}/media/review) — the browser face of
 * MediaController::listForReview. Authorized full listing (any state) for
 * organizers/logistics: one card per media item, newest first, showing
 * caption/alt, mime + size, scan state, review state and visibility.
 *
 * Now also a write console: a register-by-reference form (POST
 * /events/{id}/media) and per-item approve / reject controls (POST
 * /media/{id}/review). Approval carries a visibility choice; the service only
 * lets a CLEAN item be approved (the approve button is disabled until then).
 * Object refs are opaque (access-controlled) and shown as the stored ref.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.mediaReview.*') with
 * English fallback; the {0} count is interpolated via $li(); the scan/review/
 * visibility vocabularies are localized with a raw-value fallback. All fields are
 * server data shown verbatim & escaped. Write controls only render when a `$csrf`
 * token is present (i.e. served through renderForm), so the read-only render path
 * is unchanged.
 *
 * @var list<array<string,mixed>> $media   media rows awaiting/undergoing review
 * @var string                    $eventId the event id (for the register route)
 * @var string                    $csrf    webcsrf token for the inline forms
 */
$media   = $media ?? [];
$count   = count($media);
$eventId = $eventId ?? '';
$csrf    = $csrf ?? '';
$sidAttr = $eventId !== '' ? rawurlencode($eventId) : '';
$canWrite = $csrf !== '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Events.mediaReview.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'Events.')) ? $s : $value;
};
$size = static function ($bytes): string {
    if ($bytes === null || $bytes === '') {
        return '—';
    }
    $b = (int) $bytes;
    if ($b < 1024) {
        return $b . ' B';
    }
    if ($b < 1048576) {
        return round($b / 1024, 1) . ' KB';
    }
    return round($b / 1048576, 1) . ' MB';
};
?>

<?php ob_start(); ?>
<?= esc(lang('Events.mediaReview.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        .item { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:12px; }


        .cap { font-weight:600; }


        .meta { display:flex; flex-wrap:wrap; gap:6px; }


        input:focus, select:focus { outline:2px solid #0891b2; border-color:#0891b2; }


        button { border:0; border-radius:8px; padding:8px 15px; font-size:.85rem; font-weight:600; cursor:pointer; }


        button.primary { background:#0891b2; color:#fff; }


        button.approve { background:#166534; color:#dcfce7; }


        button.reject { background:#7f1d1d; color:#fecaca; }


        .note { color:#64748b; font-size:.72rem; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.mediaReview.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.mediaReview.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($canWrite): ?>
            <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/media">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h3><?= esc(lang('Events.mediaReview.registerHeading')) ?></h3>
                <p class="note"><?= esc(lang('Events.mediaReview.registerHint')) ?></p>
                <div class="grid">
                    <div class="full"><label for="m-ref"><?= esc(lang('Events.mediaReview.fObjectRef')) ?></label><input id="m-ref" name="object_ref" required maxlength="255"></div>
                    <div><label for="m-mime"><?= esc(lang('Events.mediaReview.fMime')) ?></label><input id="m-mime" name="mime_type" maxlength="120" placeholder="image/jpeg"></div>
                    <div><label for="m-size"><?= esc(lang('Events.mediaReview.fByteSize')) ?></label><input type="number" min="0" id="m-size" name="byte_size"></div>
                    <div class="full"><label for="m-cap"><?= esc(lang('Events.mediaReview.fCaption')) ?></label><input id="m-cap" name="caption" maxlength="255"></div>
                    <div class="full"><label for="m-alt"><?= esc(lang('Events.mediaReview.fAltText')) ?></label><input id="m-alt" name="alt_text" maxlength="255"></div>
                </div>
                <button type="submit" class="primary" style="margin-top:12px"><?= esc(lang('Events.mediaReview.registerBtn')) ?></button>
            </form>
            <h2><?= esc(lang('Events.mediaReview.queueHeading')) ?></h2>
        <?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Events.mediaReview.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Events.mediaReview.countOne' : 'Events.mediaReview.count', (string) $count)) ?></p>
            <?php foreach ($media as $m): ?>
                <?php
                $scan = strtolower((string) ($m['scan_state'] ?? ''));
                $rev  = strtolower((string) ($m['review_state'] ?? ''));
                $vis  = strtolower((string) ($m['visibility'] ?? ''));
                $mid  = (string) ($m['id'] ?? '');
                $midAttr = $mid !== '' ? esc(rawurlencode($mid), 'attr') : '';
                ?>
                <article class="item">
                    <div class="top">
                        <span class="cap"><?= esc((string) ($m['caption'] ?? $m['alt_text'] ?? lang('Events.mediaReview.untitled'))) ?></span>
                        <span class="ref"><?= esc((string) ($m['object_ref'] ?? '—')) ?></span>
                    </div>
                    <div class="meta">
                        <span class="tag"><?= esc((string) ($m['mime_type'] ?? '—')) ?></span>
                        <span class="tag"><?= esc($size($m['byte_size'] ?? null)) ?></span>
                        <span class="tag <?= esc($scan, 'attr') ?>"><?= esc(lang('Events.mediaReview.scanLabel')) ?>: <?= esc($vocab('scan', $scan)) ?></span>
                        <span class="tag <?= esc($rev, 'attr') ?>"><?= esc(lang('Events.mediaReview.reviewLabel')) ?>: <?= esc($vocab('review', $rev)) ?></span>
                        <span class="tag <?= esc($vis, 'attr') ?>"><?= esc(lang('Events.mediaReview.visibilityLabel')) ?>: <?= esc($vocab('visibility', $vis)) ?></span>
                    </div>
                    <?php if ($canWrite && $midAttr !== '' && $rev !== 'rejected'): ?>
                        <div class="actions">
                            <form class="inline" method="post" action="/media/<?= $midAttr ?>/review">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <input type="hidden" name="decision" value="approve">
                                <div>
                                    <label for="v-<?= esc($mid, 'attr') ?>"><?= esc(lang('Events.mediaReview.visibilityLabel')) ?></label>
                                    <select id="v-<?= esc($mid, 'attr') ?>" name="visibility">
                                        <option value="group"><?= esc($vocab('visibility', 'group')) ?></option>
                                        <option value="public"><?= esc($vocab('visibility', 'public')) ?></option>
                                        <option value="private"><?= esc($vocab('visibility', 'private')) ?></option>
                                    </select>
                                </div>
                                <button type="submit" class="approve"<?= $scan !== 'clean' ? ' disabled' : '' ?>><?= esc(lang('Events.mediaReview.approveBtn')) ?></button>
                            </form>
                            <form class="inline" method="post" action="/media/<?= $midAttr ?>/review">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <input type="hidden" name="decision" value="reject">
                                <button type="submit" class="reject"><?= esc(lang('Events.mediaReview.rejectBtn')) ?></button>
                            </form>
                            <?php if ($scan !== 'clean'): ?>
                                <span class="note"><?= esc(lang('Events.mediaReview.approveBlocked')) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
