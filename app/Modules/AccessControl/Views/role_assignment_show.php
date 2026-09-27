<?php
/**
 * ROLE-ASSIGNMENT detail (GET /access-control/assignments/{id}) — the browser face
 * of RoleAssignmentController::show, which otherwise rendered the generic admin
 * console. Shows one assignment in full: the role (name + code), the subject it's
 * held by, scope (org-wide vs group + descendants flag), status, effective window
 * and provenance (issuer + source). When not found ($assignment is null) the same
 * page renders a localized not-found panel.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.assignmentShowView.*') with English fallback; the status and
 * source vocabularies are localized with a raw-value fallback (lowercased). Ids
 * and codes are server data shown verbatim & escaped.
 *
 * @var array<string,mixed>|null $assignment assignment row (+ role_code/name), or null
 */
$assignment = $assignment ?? null;
$found      = is_array($assignment);

include __DIR__ . '/_locale.php';

$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.assignmentShowView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.assignmentShowView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 860px; margin: 0 auto; padding: 5vh 20px 60px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:18px; }


        h2 { font-size:1rem; color:#c4b5fd; margin:0 0 8px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <?php if (! $found): ?>
            <h1><?= esc(lang('AccessControl.assignmentShowView.heading')) ?></h1>
            <p class="notfound"><?= esc(lang('AccessControl.assignmentShowView.notFound')) ?></p>
        <?php else: ?>
            <?php
            $status  = strtolower((string) ($assignment['status'] ?? ''));
            $group   = (string) ($assignment['scope_group_id'] ?? '');
            $incDesc = ! empty($assignment['include_descendants']);
            ?>
            <h1><?= esc((string) ($assignment['role_name'] ?? $assignment['role_code'] ?? $assignment['role_id'] ?? '')) ?> <span class="code"><?= esc((string) ($assignment['role_code'] ?? '')) ?></span></h1>
            <div class="badges">
                <?php if ($status !== ''): ?><span class="badge <?= $status ?>"><?= esc($vocab('status', $status)) ?></span><?php endif; ?>
            </div>

            <div class="card">
                <h2><?= esc(lang('AccessControl.assignmentShowView.detailHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.assignmentShowView.colSubject')) ?></span><span class="v mono"><?= esc((string) ($assignment['subject_id'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.assignmentShowView.colRole')) ?></span><span class="v"><?= esc((string) ($assignment['role_name'] ?? '—')) ?> <span class="code"><?= esc((string) ($assignment['role_code'] ?? '')) ?></span></span></div>
                <div class="row">
                    <span class="k"><?= esc(lang('AccessControl.assignmentShowView.colScope')) ?></span>
                    <span class="v">
                        <?php if ($group === ''): ?>
                            <?= esc(lang('AccessControl.assignmentShowView.orgWide')) ?>
                        <?php else: ?>
                            <span class="mono"><?= esc($group) ?></span><?= $incDesc ? ' ' . esc(lang('AccessControl.assignmentShowView.withDescendants')) : '' ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.assignmentShowView.colFrom')) ?></span><span class="v"><?= esc((string) ($assignment['effective_from'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.assignmentShowView.colTo')) ?></span><span class="v"><?= esc((string) ($assignment['effective_to'] ?? '—')) ?></span></div>
            </div>

            <div class="card">
                <h2><?= esc(lang('AccessControl.assignmentShowView.provenanceHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.assignmentShowView.colIssuedBy')) ?></span><span class="v mono"><?= esc((string) ($assignment['issued_by'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.assignmentShowView.colSource')) ?></span><span class="v"><?= esc($vocab('source', strtolower((string) ($assignment['source'] ?? '')))) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.assignmentShowView.colCreated')) ?></span><span class="v"><?= esc((string) ($assignment['created_at'] ?? '—')) ?></span></div>
            </div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
