<?php
/**
 * Identity policies (GET /identity/policies) — the browser face of
 * IdentityPolicyController::index, which otherwise rendered the generic admin
 * console. Per-country identity-verification policies ordered by country code,
 * each showing minimum age, phone default region, email/phone uniqueness
 * requirements and whether minors are allowed. The `*` row is the org default.
 *
 * WRITE UI: each row carries an inline webcsrf-guarded POST to
 * /identity/policies/{country} (IdentityPolicyController::upsert) so admins can
 * edit a jurisdiction in place, plus a top "add a policy" form for new rows.
 * Both post to the same upsert endpoint (create/update on org+country). The
 * decision is PRG — the controller redirects back here with a success/error
 * flash. Destructive-free, but Save asks for confirm() before mutating.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy via lang('Identity.policies.*') with English fallback;
 * the {0} count is interpolated via $li(). Values are server data shown verbatim.
 *
 * @var list<array<string,mixed>> $policies identity_policies rows (cast)
 * @var string                    $csrf     webcsrf token for the inline forms
 */
$policies = $policies ?? [];
$count    = count($policies);
$csrf     = $csrf ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$yn = static fn (bool $b): string => $b ? lang('Identity.policies.yes') : lang('Identity.policies.no');

/**
 * Render the shared upsert form. $p is either an existing policy row (edit) or
 * null (the top "add" form). The country field is read-only when editing so the
 * upsert key can't be repointed at another jurisdiction by accident.
 *
 * @param array<string,mixed>|null $p
 */
$policyForm = static function (?array $p) use ($url, $csrf): string {
    $cc      = $p !== null ? (string) ($p['country_code'] ?? '') : '';
    $isEdit  = $p !== null;
    // Create posts to the segment-less endpoint (country_code travels in the body)
    // so the form works with NO JavaScript and under our CSP (which blocks inline
    // handlers). Edit keeps the jurisdiction in the path segment.
    $action  = $isEdit ? $url('identity/policies/' . rawurlencode($cc)) : $url('identity/policies');
    $minAge  = $isEdit ? (int) ($p['min_age'] ?? 0) : 13;
    $region  = $isEdit ? (string) ($p['phone_default_region'] ?? '') : '';
    $email   = $isEdit ? (bool) ($p['require_email_unique'] ?? false) : true;
    $phone   = $isEdit ? (bool) ($p['require_phone_unique'] ?? false) : true;
    $minor   = $isEdit ? (bool) ($p['allow_minor'] ?? false) : false;
    $notes   = $isEdit ? (string) ($p['notes'] ?? '') : '';
    $uid     = $isEdit ? 'e-' . preg_replace('/[^A-Za-z0-9]/', '', $cc ?: 'star') : 'new';

    ob_start(); ?>
    <form method="post" action="<?= esc($action, 'attr') ?>" class="pform">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
        <div class="frow">
            <label for="cc-<?= esc($uid, 'attr') ?>"><?= esc(lang('Identity.policies.countryLabel')) ?></label>
            <?php if ($isEdit): ?>
                <input id="cc-<?= esc($uid, 'attr') ?>" class="ro mono" type="text" value="<?= esc($cc, 'attr') ?>" readonly>
            <?php else: ?>
                <input id="cc-<?= esc($uid, 'attr') ?>" class="mono" type="text" name="country_code" required
                       placeholder="<?= esc(lang('Identity.policies.countryPh'), 'attr') ?>"
                       maxlength="8" pattern="[A-Za-z*]{1,8}">
                <span class="help"><?= esc(lang('Identity.policies.countryHelp')) ?></span>
            <?php endif; ?>
        </div>
        <div class="fgrid">
            <div class="frow">
                <label for="ma-<?= esc($uid, 'attr') ?>"><?= esc(lang('Identity.policies.minAgeLabel')) ?></label>
                <input id="ma-<?= esc($uid, 'attr') ?>" type="number" name="min_age" min="0" max="120" value="<?= esc((string) $minAge, 'attr') ?>">
            </div>
            <div class="frow">
                <label for="pr-<?= esc($uid, 'attr') ?>"><?= esc(lang('Identity.policies.phoneRegionLabel')) ?></label>
                <input id="pr-<?= esc($uid, 'attr') ?>" class="mono" type="text" name="phone_default_region"
                       maxlength="2" pattern="[A-Za-z]{0,2}"
                       placeholder="<?= esc(lang('Identity.policies.phoneRegionPh'), 'attr') ?>"
                       value="<?= esc($region, 'attr') ?>">
            </div>
        </div>
        <div class="checks">
            <label class="chk"><input type="checkbox" name="require_email_unique" value="1" <?= $email ? 'checked' : '' ?>> <?= esc(lang('Identity.policies.requireEmailUnique')) ?></label>
            <label class="chk"><input type="checkbox" name="require_phone_unique" value="1" <?= $phone ? 'checked' : '' ?>> <?= esc(lang('Identity.policies.requirePhoneUnique')) ?></label>
            <label class="chk"><input type="checkbox" name="allow_minor" value="1" <?= $minor ? 'checked' : '' ?>> <?= esc(lang('Identity.policies.allowMinorLabel')) ?></label>
        </div>
        <div class="frow">
            <label for="nt-<?= esc($uid, 'attr') ?>"><?= esc(lang('Identity.policies.notesLabel')) ?></label>
            <input id="nt-<?= esc($uid, 'attr') ?>" type="text" name="notes" maxlength="500"
                   placeholder="<?= esc(lang('Identity.policies.notesPh'), 'attr') ?>"
                   value="<?= esc($notes, 'attr') ?>">
        </div>
        <button type="submit" class="save"><?= esc(lang('Identity.policies.save')) ?></button>
    </form>
    <?php
    return (string) ob_get_clean();
};
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.policies.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        .item { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:10px; }


        .editor { background:#0f172aee; border:1px solid #164e63; border-radius:12px; padding:16px; margin-bottom:22px; }


        .editor h2 { font-size:1.05rem; margin:0 0 2px; color:#67e8f9; }


        .cc { font-weight:700; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; color:#67e8f9; }


        .stat { background:#0b1120; border:1px solid #1e293b; border-radius:10px; padding:8px 12px; }


        .stat .k { font-size:.66rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; }


        .stat .v { font-size:.95rem; font-weight:600; margin-top:4px; }


        details { margin-top:10px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Identity.policies.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Identity.policies.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><p class="flash ok"><?= esc($flashOk) ?></p><?php endif; ?>
        <?php if ($flashErr !== ''): ?><p class="flash err"><?= esc($flashErr) ?></p><?php endif; ?>

        <section class="editor">
            <h2><?= esc(lang('Identity.policies.editHeading')) ?></h2>
            <p class="sub"><?= esc(lang('Identity.policies.editSub')) ?></p>
            <?= $policyForm(null) ?>
        </section>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Identity.policies.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Identity.policies.countOne' : 'Identity.policies.count', (string) $count)) ?></p>
            <?php foreach ($policies as $p): ?>
                <?php $cc = (string) ($p['country_code'] ?? '—'); ?>
                <article class="item">
                    <div class="top">
                        <div class="lead">
                            <span class="cc"><?= esc($cc) ?></span>
                            <?php if ($cc === '*'): ?><span class="default"><?= esc(lang('Identity.policies.orgDefault')) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="grid">
                        <div class="stat"><div class="k"><?= esc(lang('Identity.policies.minAge')) ?></div><div class="v"><?= esc((string) (int) ($p['min_age'] ?? 0)) ?></div></div>
                        <div class="stat"><div class="k"><?= esc(lang('Identity.policies.phoneRegion')) ?></div><div class="v"><?= esc((string) ($p['phone_default_region'] ?? '—')) ?></div></div>
                        <div class="stat"><div class="k"><?= esc(lang('Identity.policies.emailUnique')) ?></div><div class="v"><?= esc($yn((bool) ($p['require_email_unique'] ?? false))) ?></div></div>
                        <div class="stat"><div class="k"><?= esc(lang('Identity.policies.phoneUnique')) ?></div><div class="v"><?= esc($yn((bool) ($p['require_phone_unique'] ?? false))) ?></div></div>
                        <div class="stat"><div class="k"><?= esc(lang('Identity.policies.allowMinor')) ?></div><div class="v"><?= esc($yn((bool) ($p['allow_minor'] ?? false))) ?></div></div>
                    </div>
                    <details>
                        <summary><?= esc(lang('Identity.policies.editExistingHint')) ?></summary>
                        <?= $policyForm($p) ?>
                    </details>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
