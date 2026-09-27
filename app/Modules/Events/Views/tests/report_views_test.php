<?php

declare(strict_types=1);

/**
 * Events admin read-view i18n + render smoke for the 8 bespoke views that replaced
 * respondAdmin consoles: report, report_rollup, expense_reconcile, expense_list,
 * feedback_aggregate, catering_aggregates, accessibility_needs, media_review.
 *
 * Asserts each block's keys mirror across all locales, each SELF-CONTAINED view
 * references lang('Events.<block>.*'), includes _locale.php, emits a dynamic
 * <html lang dir>, and renders localized strings with correct direction (RTL for
 * Arabic), verbatim server data, localized vocab with raw-value fallback, PHP
 * singular/plural counts, privacy-suppression / not-found / empty states.
 *
 *   php app/Modules/Events/Views/tests/report_views_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';

require __DIR__ . '/view_test_helpers.php';
$render = wbs_events_renderer($langDir);

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }
    return $o;
};

// --- key parity across locales, per block ---
$blocks = ['report', 'rollup', 'reconcile', 'expenseList', 'feedbackAgg', 'catering', 'needs', 'mediaReview'];
$en = require $langDir . '/en/Events.php';
echo "language parity (8 blocks)\n";
foreach ($blocks as $b) {
    $enKeys = $flatten($en[$b] ?? []);
    chk("en has $b keys", $enKeys !== []);
    foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $m    = require $langDir . "/$loc/Events.php";
        $keys = $flatten($m[$b] ?? []);
        chk("$loc mirrors en $b", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
            'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
    }
}

// --- self-contained wiring per view ---
$views = [
    'report.php' => 'report', 'report_rollup.php' => 'rollup', 'expense_reconcile.php' => 'reconcile',
    'expense_list.php' => 'expenseList', 'feedback_aggregate.php' => 'feedbackAgg',
    'catering_aggregates.php' => 'catering', 'accessibility_needs.php' => 'needs', 'media_review.php' => 'mediaReview',
];
echo "view self-contained locale wiring\n";
foreach ($views as $file => $block) {
    $src = (string) file_get_contents("$viewDir/$file");
    chk("$file calls lang('Events.$block.", str_contains($src, "lang('Events.$block."));
    chk("$file includes _locale.php", str_contains($src, '_locale.php'));
    chk("$file emits dynamic <html lang dir>", str_contains($src, '_shell_open.php'));
    chk("$file no hardcoded lang=\"en\"", ! str_contains($src, 'lang="en"'));
}

// ---------- report ----------
echo "render: report\n";
$report = [
    'title' => 'Regional Convention', 'status' => 'live', 'group_id' => 'g1',
    'mobilization' => ['invitations' => 500, 'converted_prospects' => 40, 'responses' => 300],
    'attendance' => ['confirmed_registrations' => 280, 'waitlisted' => 12, 'expected' => 280, 'actual' => 265],
    'streaming' => ['streams' => 3, 'qualified' => 2],
    'contributions' => ['count' => 90, 'amount_minor' => 1234500],
    'feedback' => ['forms' => 2, 'responses' => 150],
    'expenses' => ['budget_minor' => 5000000, 'committed_minor' => 4200000, 'count' => 20, 'currency' => 'GHS'],
    'media' => ['total' => 60, 'approved' => 55],
    'points' => ['awarded' => 3200],
    'generated_at' => '2026-09-11T09:00:00Z',
];
$h = $render("$viewDir/report.php", ['report' => $report], 'fr');
chk('report fr rtl=ltr + title verbatim', str_contains($h, 'dir="ltr"') && str_contains($h, 'Regional Convention'));
chk('report fr mobilization heading', str_contains($h, 'Mobilisation'));
chk('report fr numbers formatted', str_contains($h, '500') && str_contains($h, '265'));
chk('report fr contribution money', str_contains($h, '12,345.00') && str_contains($h, 'GHS'));
$hn = $render("$viewDir/report.php", ['report' => null], 'fr');
chk('report fr not-found', str_contains($hn, 'Cet événement est introuvable.'));
$ha = $render("$viewDir/report.php", ['report' => null], 'ar');
chk('report ar rtl + not-found', str_contains($ha, 'dir="rtl"') && str_contains($ha, 'لم يُعثر على هذه الفعالية.'));

// ---------- rollup ----------
echo "render: rollup\n";
$rollup = ['events' => 5, 'invitations' => 1000, 'responses' => 700, 'expected_attendance' => 650, 'actual_attendance' => 600, 'qualified_streaming' => 8, 'contributions_count' => 300, 'contributions_minor' => 9876500];
$h = $render("$viewDir/report_rollup.php", ['rollup' => $rollup, 'groupId' => 'grp-9'], 'fr');
chk('rollup fr heading + group verbatim', str_contains($h, 'Cumul du groupe') && str_contains($h, 'grp-9'));
chk('rollup fr events count', str_contains($h, '5'));
chk('rollup fr contrib money', str_contains($h, '98,765.00'));
$ha = $render("$viewDir/report_rollup.php", ['rollup' => $rollup, 'groupId' => 'grp-9'], 'ar');
chk('rollup ar rtl', str_contains($ha, 'dir="rtl"') && str_contains($ha, 'تجميع المجموعة'));

// ---------- reconcile ----------
echo "render: reconcile\n";
$recon = ['event_id' => 'e1', 'currency' => 'GHS', 'budget_minor' => 5000000, 'approved_minor' => 3000000, 'reimbursed_minor' => 1000000, 'committed_minor' => 4000000, 'variance_minor' => 1000000, 'by_status' => ['approved' => ['count' => 10, 'requested_minor' => 3200000, 'decided_minor' => 3000000], 'rejected' => ['count' => 2, 'requested_minor' => 500000, 'decided_minor' => 0]]];
$h = $render("$viewDir/expense_reconcile.php", ['recon' => $recon], 'fr');
chk('reconcile fr heading', str_contains($h, 'Rapprochement des dépenses'));
chk('reconcile fr totals money', str_contains($h, '50,000.00') && str_contains($h, '40,000.00'));
chk('reconcile fr status localized', str_contains($h, 'Approuvée') && str_contains($h, 'Rejetée'));
$he = $render("$viewDir/expense_reconcile.php", ['recon' => ['currency' => 'GHS', 'by_status' => []]], 'fr');
chk('reconcile fr empty by-status', str_contains($he, 'Aucune dépense enregistrée.'));

// ---------- expenseList ----------
echo "render: expenseList\n";
$rows = [
    ['description' => 'Sound hire', 'category' => 'AV', 'allocation' => 'Line 3', 'amount_minor' => 250000, 'approved_amount_minor' => 240000, 'currency' => 'GHS', 'status' => 'approved'],
    ['description' => 'Snacks', 'category' => 'Catering', 'allocation' => null, 'amount_minor' => 80000, 'approved_amount_minor' => null, 'currency' => 'GHS', 'status' => 'submitted'],
];
$h = $render("$viewDir/expense_list.php", ['expenses' => $rows], 'fr');
chk('expenseList fr plural count', str_contains($h, '2 dépenses'));
chk('expenseList fr desc + money verbatim', str_contains($h, 'Sound hire') && str_contains($h, '2,500.00'));
chk('expenseList fr status localized', str_contains($h, 'Approuvée') && str_contains($h, 'Soumise'));
$h1 = $render("$viewDir/expense_list.php", ['expenses' => [$rows[0]]], 'fr');
chk('expenseList fr singular', str_contains($h1, '1 dépense') && ! str_contains($h1, '1 dépenses'));
$he = $render("$viewDir/expense_list.php", ['expenses' => []], 'fr');
chk('expenseList fr empty', str_contains($he, 'Aucune dépense enregistrée pour cet événement.'));

// ---------- feedbackAgg ----------
echo "render: feedbackAgg\n";
$agg = ['form_id' => 'f1', 'kind' => 'quiz', 'responses' => 40, 'suppressed' => false,
    'ratings' => [['question_id' => 'q1', 'prompt' => 'How was it?', 'avg_rating' => 4.5, 'n' => 40]],
    'quiz' => ['avg_score' => 8.2, 'max_score' => 10, 'passers' => 33, 'scored' => 40]];
$h = $render("$viewDir/feedback_aggregate.php", ['aggregate' => $agg], 'fr');
chk('feedbackAgg fr ratings prompt verbatim', str_contains($h, 'How was it?') && str_contains($h, '4.5'));
chk('feedbackAgg fr quiz section', str_contains($h, 'Scores du quiz') && str_contains($h, '8.2'));
$hs = $render("$viewDir/feedback_aggregate.php", ['aggregate' => ['form_id' => 'f', 'responses' => 2, 'suppressed' => true, 'min_n' => 5]], 'fr');
chk('feedbackAgg fr suppressed notice', str_contains($hs, 'Résultats masqués pour protéger la vie privée.'));
chk('feedbackAgg fr suppressed detail interpolated', str_contains($hs, '2') && str_contains($hs, '5'));
$hnf = $render("$viewDir/feedback_aggregate.php", ['aggregate' => null], 'fr');
chk('feedbackAgg fr not-found', str_contains($hnf, 'Ce formulaire de retour est introuvable.'));
$ha = $render("$viewDir/feedback_aggregate.php", ['aggregate' => null], 'ar');
chk('feedbackAgg ar rtl + not-found', str_contains($ha, 'dir="rtl"') && str_contains($ha, 'لم يُعثر على نموذج التقييم هذا.'));

// ---------- catering ----------
echo "render: catering\n";
$cat = [['diet_label' => 'standard', 'headcount' => 120], ['diet_label' => 'vegetarian', 'headcount' => 30]];
$h = $render("$viewDir/catering_aggregates.php", ['catering' => $cat], 'fr');
chk('catering fr heading + labels verbatim', str_contains($h, 'Cumuls de restauration') && str_contains($h, 'vegetarian'));
chk('catering fr total computed', str_contains($h, '150'));
$he = $render("$viewDir/catering_aggregates.php", ['catering' => []], 'fr');
chk('catering fr empty', str_contains($he, 'Aucun cumul de restauration pour l’instant.'));

// ---------- needs ----------
echo "render: needs\n";
$needs = [['need_type' => 'mobility', 'detail' => 'wheelchair access', 'classification' => 'restricted', 'user_id' => 'usr-42']];
$h = $render("$viewDir/accessibility_needs.php", ['needs' => $needs], 'fr');
chk('needs fr confidentiality banner', str_contains($h, 'Classé spécial'));
chk('needs fr type localized', str_contains($h, 'Mobilité'));
chk('needs fr detail + subject verbatim', str_contains($h, 'wheelchair access') && str_contains($h, 'usr-42'));
$hU = $render("$viewDir/accessibility_needs.php", ['needs' => [['need_type' => 'sensory', 'detail' => 'x', 'classification' => 'restricted', 'user_id' => 'u']]], 'fr');
chk('needs fr unknown type falls back to raw', str_contains($hU, 'sensory'));
$he = $render("$viewDir/accessibility_needs.php", ['needs' => []], 'ar');
chk('needs ar rtl + empty', str_contains($he, 'dir="rtl"') && str_contains($he, 'لا احتياجات وصول مسجّلة.'));

// ---------- mediaReview ----------
echo "render: mediaReview\n";
$media = [
    ['caption' => 'Opening night', 'mime_type' => 'image/jpeg', 'byte_size' => 2097152, 'object_ref' => 'obj-1', 'scan_state' => 'clean', 'review_state' => 'approved', 'visibility' => 'public'],
    ['caption' => null, 'alt_text' => null, 'mime_type' => 'video/mp4', 'byte_size' => 500, 'object_ref' => 'obj-2', 'scan_state' => 'pending', 'review_state' => 'pending', 'visibility' => 'private'],
];
$h = $render("$viewDir/media_review.php", ['media' => $media], 'fr');
chk('mediaReview fr plural count', str_contains($h, '2 éléments'));
chk('mediaReview fr caption + ref verbatim', str_contains($h, 'Opening night') && str_contains($h, 'obj-1'));
chk('mediaReview fr scan/review/vis localized', str_contains($h, 'Sain') && str_contains($h, 'Approuvé') && str_contains($h, 'Public'));
chk('mediaReview fr size formatted', str_contains($h, '2 MB') || str_contains($h, '2.0 MB'));
chk('mediaReview fr untitled fallback', str_contains($h, '(sans titre)'));
$he = $render("$viewDir/media_review.php", ['media' => []], 'fr');
chk('mediaReview fr empty', str_contains($he, 'Aucun média à examiner.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
