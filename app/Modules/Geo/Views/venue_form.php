<?php
/**
 * VENUE create/edit form (GET /venues/new and /venues/{id}/edit) — the browser
 * face of VenueController::createForm / editForm, and the write side of the
 * venue directory's "New venue" / "Edit" links (previously the directory was
 * read-only, with the create/update endpoints reachable only via the JSON API).
 *
 * Posts to POST /venues (create) or POST /venues/{id} (update), both
 * webcsrf-guarded. On success the controller redirects (PRG) to the venue
 * detail; on failure it re-renders with $error + the submitted values.
 *
 * SELF-CONTAINED page: renders its own <html>, includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy via
 * lang('Geo.venueForm.*') with English fallback. `venue_type` / `status` /
 * `discovery_status` are fixed vocabularies localized with raw-value fallback.
 *
 * @var string               $csrf
 * @var string               $mode   'create' | 'edit'
 * @var array<string,mixed>  $venue  current/submitted values
 * @var string               $error
 */
$csrf  = $csrf ?? '';
$mode  = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$venue = $venue ?? [];
$error = $error ?? '';

$isEdit = $mode === 'edit';
$id     = (string) ($venue['id'] ?? '');

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$ov = static function (string $k, string $default = '') use ($venue): string {
    $v = $venue[$k] ?? $default;
    if ($v === null) {
        $v = '';
    }

    return htmlspecialchars((string) $v, ENT_QUOTES);
};

// Fixed vocabularies (mirror LocationService constants).
$venueTypes  = ['church', 'hall', 'conference_center', 'classroom', 'outdoor', 'fellowship_hall', 'auditorium', 'training_center', 'other'];
$statuses    = ['active', 'inactive', 'maintenance', 'closed'];
$discoveries = ['private', 'unlisted', 'public'];

$labelize = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
$vocab = static function (string $group, string $value) use ($labelize): string {
    if ($value === '') {
        return '';
    }
    $s = lang('Geo.' . $group . '.' . $value);

    return (is_string($s) && ! str_contains($s, 'Geo.')) ? $s : $labelize($value);
};

$curType      = (string) ($venue['venue_type'] ?? 'other');
$curStatus    = (string) ($venue['status'] ?? 'active');
$curDiscovery = (string) ($venue['discovery_status'] ?? 'private');
$version      = isset($venue['version']) ? (string) (int) $venue['version'] : '';

$action = $isEdit ? $url('venues/' . rawurlencode($id)) : $url('venues');
?>

<?php ob_start(); ?>
<?= esc(lang($isEdit ? 'Geo.venueForm.metaTitleEdit' : 'Geo.venueForm.metaTitleNew')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 720px; margin: 0 auto; padding: 5vh 20px 60px; }


        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }


        .field { display:flex; flex-direction:column; gap:6px; margin-bottom:16px; }


        input[type=text], input[type=number], input[type=email], input[type=tel], input[type=url], select, textarea {
            font:inherit; color:#e2e8f0; background:#0b1424; border:1px solid #334155; border-radius:9px; padding:10px 12px; width:100%; }


        input[readonly] { opacity:.6; cursor:not-allowed; }


        .check { flex-direction:row; align-items:center; gap:10px; }


        .btn.primary { background:#0284c7; color:#fff; }


        .btn.primary:hover { background:#0369a1; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <a class="back" href="<?= esc($url('venues'), 'attr') ?>">&larr; <?= esc(lang('Geo.venueForm.backToList')) ?></a>
        <h1><?= esc(lang($isEdit ? 'Geo.venueForm.headingEdit' : 'Geo.venueForm.headingNew')) ?></h1>
        <p class="sub"><?= esc(lang('Geo.venueForm.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <form method="post" action="<?= esc($action, 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <?php if ($isEdit && $version !== ''): ?>
                <input type="hidden" name="expected_version" value="<?= esc($version, 'attr') ?>">
            <?php endif; ?>

            <div class="field full">
                <label for="name"><?= esc(lang('Geo.venueForm.nameLabel')) ?></label>
                <input type="text" id="name" name="name" required maxlength="200"
                       placeholder="<?= esc(lang('Geo.venueForm.namePh'), 'attr') ?>" value="<?= $ov('name') ?>">
            </div>

            <div class="grid">
                <div class="field">
                    <label for="venue_type"><?= esc(lang('Geo.venueForm.typeLabel')) ?></label>
                    <select id="venue_type" name="venue_type">
                        <?php foreach ($venueTypes as $t): ?>
                            <option value="<?= esc($t, 'attr') ?>"<?= $curType === $t ? ' selected' : '' ?>><?= esc($vocab('venueType', $t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="status"><?= esc(lang('Geo.venueForm.statusLabel')) ?></label>
                    <select id="status" name="status">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= esc($s, 'attr') ?>"<?= $curStatus === $s ? ' selected' : '' ?>><?= esc($vocab('status', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="capacity"><?= esc(lang('Geo.venueForm.capacityLabel')) ?>
                        <span class="hint"><?= esc(lang('Geo.venueForm.capacityHint')) ?></span></label>
                    <input type="number" id="capacity" name="capacity" min="0" inputmode="numeric" value="<?= $ov('capacity') ?>">
                </div>
                <div class="field">
                    <label for="discovery_status"><?= esc(lang('Geo.venueForm.discoveryLabel')) ?>
                        <span class="hint"><?= esc(lang('Geo.venueForm.discoveryHint')) ?></span></label>
                    <select id="discovery_status" name="discovery_status">
                        <?php foreach ($discoveries as $d): ?>
                            <option value="<?= esc($d, 'attr') ?>"<?= $curDiscovery === $d ? ' selected' : '' ?>><?= esc($vocab('discovery', $d)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field full">
                <label for="address_text"><?= esc(lang('Geo.venueForm.addressLabel')) ?>
                    <span class="hint"><?= esc(lang('Geo.venueForm.locationHint')) ?></span></label>
                <input type="text" id="address_text" name="address_text" maxlength="255"
                       placeholder="<?= esc(lang('Geo.venueForm.addressPh'), 'attr') ?>" value="<?= $ov('address_text') ?>">
            </div>

            <div class="grid">
                <div class="field">
                    <label for="latitude"><?= esc(lang('Geo.venueForm.latLabel')) ?></label>
                    <input type="text" id="latitude" name="latitude" inputmode="decimal"
                           placeholder="e.g. 5.6037" value="<?= $ov('latitude') ?>">
                </div>
                <div class="field">
                    <label for="longitude"><?= esc(lang('Geo.venueForm.lngLabel')) ?></label>
                    <input type="text" id="longitude" name="longitude" inputmode="decimal"
                           placeholder="e.g. -0.1870" value="<?= $ov('longitude') ?>">
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="contact_phone"><?= esc(lang('Geo.venueForm.phoneLabel')) ?></label>
                    <input type="tel" id="contact_phone" name="contact_phone" maxlength="40" value="<?= $ov('contact_phone') ?>">
                </div>
                <div class="field">
                    <label for="contact_email"><?= esc(lang('Geo.venueForm.emailLabel')) ?></label>
                    <input type="email" id="contact_email" name="contact_email" maxlength="200" value="<?= $ov('contact_email') ?>">
                </div>
            </div>

            <div class="field full">
                <label for="website"><?= esc(lang('Geo.venueForm.websiteLabel')) ?></label>
                <input type="url" id="website" name="website" maxlength="255" placeholder="https://" value="<?= $ov('website') ?>">
            </div>

            <div class="field full">
                <label for="parking_info"><?= esc(lang('Geo.venueForm.parkingLabel')) ?></label>
                <textarea id="parking_info" name="parking_info" maxlength="500"><?= $ov('parking_info') ?></textarea>
            </div>

            <div class="actions">
                <button type="submit" class="btn primary"><?= esc(lang($isEdit ? 'Geo.venueForm.saveEdit' : 'Geo.venueForm.saveNew')) ?></button>
                <a class="btn ghost" href="<?= esc($url('venues'), 'attr') ?>"><?= esc(lang('Geo.venueForm.cancel')) ?></a>
            </div>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
