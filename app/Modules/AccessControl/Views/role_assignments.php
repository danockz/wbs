<?php
/**
 * ROLE-ASSIGNMENTS for a subject (GET /access-control/subjects/{id}/assignments) —
 * the browser face of RoleAssignmentController::forSubject, which otherwise
 * rendered the generic admin console. Lists every role assignment held by one
 * subject, newest first, showing the role, its scope (group + descendants flag),
 * status, effective window and provenance.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.assignmentsView.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() with singular/plural chosen in PHP. Fixed
 * vocabularies (status, source) are localized with a raw-value fallback. The
 * subject id, role code/name and group ids are server data shown verbatim &
 * escaped.
 *
 * @var list<array<string,mixed>> $assignments role-assignment rows for the subject
 * @var string                    $subjectId   the subject these assignments belong to
 */
$assignments = $assignments ?? [];
$subjectId   = $subjectId ?? '';
$count       = count($assignments);

include __DIR__ . '/_locale.php';

$vocab = static function (string $group, string $value): string {
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.assignmentsView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.assignmentsView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1080px; margin: 0 auto; padding: 5vh 20px 60px; }


        .subject { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#c4b5fd;
            border:1px solid #6d28d9; border-radius:6px; padding:2px 8px; }


        .role { font-size:1.02rem; font-weight:700; }


        .meta { display:flex; flex-wrap:wrap; gap:6px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('AccessControl.assignmentsView.heading')) ?></h1>
        <p class="sub"><?= esc(lang('AccessControl.assignmentsView.subjectLabel')) ?>: <span class="subject"><?= esc($subjectId !== '' ? $subjectId : '—') ?></span></p>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('AccessControl.assignmentsView.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'AccessControl.assignmentsView.countOne' : 'AccessControl.assignmentsView.count', (string) $count)) ?></p>
            <?php foreach ($assignments as $a): ?>
                <?php
                $status  = strtolower((string) ($a['status'] ?? ''));
                $isRev   = $status === 'revoked';
                $group   = (string) ($a['scope_group_id'] ?? '');
                $incDesc = ! empty($a['include_descendants']);
                ?>
                <article class="asg<?= $isRev ? ' revoked' : '' ?>">
                    <div class="top">
                        <span class="role"><?= esc((string) ($a['role_name'] ?? $a['role_code'] ?? $a['role_id'] ?? '')) ?></span>
                        <span class="code"><?= esc((string) ($a['role_code'] ?? '')) ?></span>
                        <span class="st <?= $status === 'active' ? 'active' : ($isRev ? 'revoked' : '') ?>"><?= esc($vocab('status', $status)) ?></span>
                    </div>
                    <div class="meta">
                        <span class="tag">
                            <?= esc(lang('AccessControl.assignmentsView.colScope')) ?>:
                            <?php if ($group === ''): ?>
                                <?= esc(lang('AccessControl.assignmentsView.orgWide')) ?>
                            <?php else: ?>
                                <span class="mono"><?= esc($group) ?></span><?= $incDesc ? ' ' . esc(lang('AccessControl.assignmentsView.withDescendants')) : '' ?>
                            <?php endif; ?>
                        </span>
                        <span class="tag"><?= esc(lang('AccessControl.assignmentsView.colFrom')) ?>: <?= esc((string) ($a['effective_from'] ?? '—')) ?></span>
                        <span class="tag"><?= esc(lang('AccessControl.assignmentsView.colTo')) ?>: <?= esc((string) ($a['effective_to'] ?? '—')) ?></span>
                        <?php if (! empty($a['source'])): ?>
                            <span class="tag"><?= esc(lang('AccessControl.assignmentsView.colSource')) ?>: <?= esc($vocab('source', strtolower((string) $a['source']))) ?></span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
