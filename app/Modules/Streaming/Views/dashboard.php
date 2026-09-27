<?= $this->extend('layouts/app') ?>

<?php
/**
 * Stream organizer dashboard (SRS FR-STR-010/012). Server-rendered; JSON when
 * negotiated. Provider metrics are shown with their source, exactness and
 * retrieval time — the platform never presents a fabricated or unlabeled metric.
 *
 * Copy is localized via lang('Streaming.*') with English fallback. Status /
 * access-policy / exactness LABELS localize with raw-value fallback; numbers
 * are rendered as-is. Metric identifiers (source, metric name) are provider
 * data and stay verbatim.
 *
 * @var array<string,mixed> $result   service payload from StreamService::organizerDashboard
 * @var string              $streamId
 * @var string              $csrf
 */
$stream       = $result['stream'] ?? [];
$destinations = $result['destinations'] ?? [];
$engagement   = $result['engagement'] ?? [];
$metrics      = $result['provider_metrics'] ?? [];
$streamId     = $streamId ?? (string) ($stream['id'] ?? '');
$csrf         = $csrf ?? '';
$sidAttr      = rawurlencode((string) $streamId);
$status       = (string) ($stream['status'] ?? '');
$providers    = ['youtube', 'twitch', 'facebook', 'vimeo', 'telegram', 'rtmp', 'webrtc'];
$hasPending   = false;
foreach ($destinations as $d) {
    if ((string) ($d['status'] ?? '') === 'pending') { $hasPending = true; break; }
}
$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;

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
$exactLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Streaming.exactness.' . $s);

    return $t === 'Streaming.exactness.' . $s ? $s : $t;
};
?>

<?= $this->section('content') ?>
    <h1><?= esc($stream['title'] ?? lang('Streaming.streamFallback')) ?></h1>
    <div class="sub">
        <span class="pill"><?= esc($statusLbl((string) ($stream['status'] ?? ''))) ?></span>
        <span class="pill"><?= esc($accessLbl((string) ($stream['access_policy'] ?? ''))) ?></span>
        <?php if (! empty($stream['started_at'])): ?>
            · <?= esc($li('Streaming.started', (string) $stream['started_at'])) ?>
        <?php endif; ?>
    </div>

    <?php if ($flashOk !== null && $flashOk !== ''): ?>
        <div class="card" style="border-color:#22c55e55;background:#052e1b;color:#bbf7d0"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== null && $flashErr !== ''): ?>
        <div class="card" style="border-color:#ef444455;background:#3f1d1d;color:#fecaca"><?= esc($flashErr) ?></div>
    <?php endif; ?>

    <?php if ($streamId !== ''): ?>
        
        <h2><?= esc(lang('Streaming.lifecycle.heading')) ?></h2>
        <div class="lc">
            <!-- Add a destination (before the stream ends) -->
            <?php if (in_array($status, ['draft', 'scheduled', 'live'], true)): ?>
                <div class="grp">
                    <div class="lbl"><?= esc(lang('Streaming.lifecycle.addDestinationLabel')) ?></div>
                    <form method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/destinations'), 'attr') ?>">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <select name="provider" aria-label="<?= esc(lang('Streaming.lifecycle.providerLabel'), 'attr') ?>">
                            <?php foreach ($providers as $p): ?>
                                <option value="<?= esc($p, 'attr') ?>"><?= esc($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="label" maxlength="120" placeholder="<?= esc(lang('Streaming.lifecycle.destinationLabelPh'), 'attr') ?>">
                        <button class="btn ghost" type="submit"><?= esc(lang('Streaming.lifecycle.addDestinationBtn')) ?></button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Provision pending destinations -->
            <?php if ($hasPending && in_array($status, ['draft', 'scheduled', 'live'], true)): ?>
                <form method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/provision'), 'attr') ?>">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <button class="btn go" type="submit"><?= esc(lang('Streaming.lifecycle.provisionBtn')) ?></button>
                </form>
            <?php endif; ?>

            <!-- Go live -->
            <?php if (in_array($status, ['draft', 'scheduled'], true)): ?>
                <form method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/live'), 'attr') ?>"
                      onsubmit="return confirm('<?= esc(lang('Streaming.lifecycle.liveConfirm'), 'attr') ?>');">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <button class="btn live" type="submit"><?= esc(lang('Streaming.lifecycle.liveBtn')) ?></button>
                </form>
            <?php endif; ?>

            <!-- End -->
            <?php if ($status === 'live'): ?>
                <form method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/end'), 'attr') ?>"
                      onsubmit="return confirm('<?= esc(lang('Streaming.lifecycle.endConfirm'), 'attr') ?>');">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <button class="btn end" type="submit"><?= esc(lang('Streaming.lifecycle.endBtn')) ?></button>
                </form>
            <?php endif; ?>

            <!-- Link an external archive (VOD), once ended -->
            <?php if ($status === 'ended'): ?>
                <div class="grp">
                    <div class="lbl"><?= esc(lang('Streaming.lifecycle.archiveLabel')) ?></div>
                    <form method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/archive'), 'attr') ?>">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <select name="provider" aria-label="<?= esc(lang('Streaming.lifecycle.providerLabel'), 'attr') ?>">
                            <?php foreach ($providers as $p): ?>
                                <option value="<?= esc($p, 'attr') ?>"><?= esc($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="url" name="external_url" required placeholder="<?= esc(lang('Streaming.lifecycle.archiveUrlPh'), 'attr') ?>">
                        <select name="access_policy" aria-label="<?= esc(lang('Streaming.lifecycle.accessLabel'), 'attr') ?>">
                            <option value="restricted"><?= esc($accessLbl('restricted')) ?></option>
                            <option value="public"><?= esc($accessLbl('public')) ?></option>
                        </select>
                        <button class="btn ghost" type="submit"><?= esc(lang('Streaming.lifecycle.archiveBtn')) ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2><?= esc(lang('Streaming.engagement')) ?></h2>
    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Streaming.chatMessages')) ?></div><div class="v"><?= (int) ($engagement['chat_messages'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Streaming.polls')) ?></div><div class="v"><?= (int) ($engagement['polls'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Streaming.pollVotes')) ?></div><div class="v"><?= (int) ($engagement['poll_votes'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Streaming.archivedRecordings')) ?></div><div class="v"><?= (int) ($engagement['archived_recordings'] ?? 0) ?></div></div>
    </div>

    <h2><?= esc(lang('Streaming.destinations')) ?></h2>
    <?php if ($destinations === []): ?>
        <div class="empty"><?= esc(lang('Streaming.noDestinations')) ?></div>
    <?php else: ?>
        <?php foreach ($destinations as $d): ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc($d['provider'] ?? '') ?><?php if (! empty($d['label'])): ?> · <?= esc($d['label']) ?><?php endif; ?></span>
                    <span class="pill"><?= esc($statusLbl((string) ($d['status'] ?? ''))) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2><?= esc(lang('Streaming.providerMetrics')) ?></h2>
    <?php if ($metrics === []): ?>
        <div class="empty"><?= esc(lang('Streaming.noMetrics')) ?></div>
    <?php else: ?>
        <?php foreach ($metrics as $m): ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc($m['metric'] ?? '') ?></span>
                    <span class="v" style="font-size:1.1rem;">
                        <?= ($m['exactness'] ?? '') === 'unavailable' ? '—' : esc((string) ($m['value'] ?? '')) ?>
                    </span>
                </div>
                <div class="counts">
                    <?= esc($li('Streaming.source', (string) ($m['source'] ?? ''))) ?>
                    · <span class="pill"><?= esc($exactLbl((string) ($m['exactness'] ?? 'estimated'))) ?></span>
                    · <?= esc($li('Streaming.retrieved', (string) ($m['retrieved_at'] ?? ''))) ?>
                    <?php if (! empty($m['note'])): ?> · <?= esc($m['note']) ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <div class="meta"><?= esc($result['metrics_note'] ?? '') ?></div>
<?= $this->endSection() ?>
