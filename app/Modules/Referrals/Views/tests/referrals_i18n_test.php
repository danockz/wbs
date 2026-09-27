<?php

declare(strict_types=1);

/**
 * Referrals i18n test — asserts every locale's Referrals.php mirrors the English
 * keys (incl. the nested temperature enum group), that the SELF-CONTAINED
 * contacts_index view references lang('Referrals.*'), dropped its hardcoded
 * lang="en", includes _locale.php and emits a dynamic <html lang dir>, and
 * renders localized strings with correct direction (RTL for Arabic),
 * localized-with-fallback temperature, verbatim humanized stage/decision slugs,
 * verbatim contact data, and preserved numeric summary counts.
 *
 *   php app/Modules/Referrals/Views/tests/referrals_i18n_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Referrals/Language';
$viewDir = $root . '/app/Modules/Referrals/Views';

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

echo "language file completeness\n";
$en     = require $langDir . '/en/Referrals.php';
$enKeys = $flatten($en);
chk('en has nested temperature.hot', in_array('temperature.hot', $enKeys, true));
chk('en has contact + contacts plural', in_array('contact', $enKeys, true) && in_array('contacts', $enKeys, true));
chk('en has chain.* group', in_array('chain.heading', $enKeys, true) && in_array('chain.one', $enKeys, true) && in_array('chain.many', $enKeys, true));
chk('en has reassign.* group', in_array('reassign.heading', $enKeys, true) && in_array('reassign.approve', $enKeys, true) && in_array('reassign.statusPending', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Referrals.php";
    if (! is_file($f)) {
        chk("$loc/Referrals.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/contacts_index.php");
chk("contacts_index.php calls lang('Referrals.", str_contains($src, "lang('Referrals."));
chk("contacts_index.php has no hardcoded lang=\"en\"", ! str_contains($src, 'lang="en"'));
chk("contacts_index.php includes _locale.php", str_contains($src, '_locale.php'));
chk("contacts_index.php emits dynamic <html lang dir>", str_contains($src, '_shell_open.php'));
foreach (['<h1>My contacts</h1>', '<title>My contacts', 'Add a contact', 'Follow-up due'] as $needle) {
    chk("contacts_index.php no bare '$needle'", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar) with fallback & verbatim data\n";
// Framework stubs so _locale.php resolves a locale + rtl set.
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            function getLocale() { return $GLOBALS['__refLoc'] ?? 'en'; }
        };
    }
}
if (! function_exists('config')) {
    function config($c)
    {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Referrals') {
            return $key;
        }
        $v = $GLOBALS['__refLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}

$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__refLoc']  = $loc;
    $GLOBALS['__refLang'] = require $langDir . "/$loc/Referrals.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr — dynamic lang/dir, headings, plural, temp label, verbatim slug/data, counts
$h = $render("$viewDir/contacts_index.php", [
    'summary'   => ['total' => 12, 'by_temperature' => ['hot' => 3, 'warm' => 4, 'cold' => 5], 'by_stage' => [], 'due' => 2],
    'contacts'  => [
        ['id' => 'k1', 'full_name' => 'Kofi Boateng', 'phone' => '+233111', 'address_id' => 'a1', 'journey_stage' => 'first_contact', 'temperature' => 'hot', 'next_follow_up_at' => '2026-10-12'],
        ['id' => 'k2', 'full_name' => 'Ama Serwaa', 'journey_stage' => 'regular_attender', 'temperature' => 'weird_temp'],
    ],
    'filters'   => [],
    'temps'     => ['hot', 'warm', 'cold'],
    'stages'    => ['first_contact', 'regular_attender'],
    'decisions' => ['made_commitment', 'join_group'],
    'csrf'      => 'TKN',
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr title translated', str_contains($h, 'Mes contacts —'));
chk('fr heading translated', str_contains($h, '<h1>Mes contacts</h1>'));
chk('fr total tile translated', str_contains($h, 'Total'));
chk('fr followUpDue translated', str_contains($h, 'Suivi à faire'));
chk('fr temperature hot -> Chaud', str_contains($h, 'Chaud'));
chk('fr unknown temperature falls back (Weird_temp)', str_contains($h, 'Weird_temp'));
chk('fr contacts plural translated', str_contains($h, '2 contacts'));
chk('fr add-contact heading translated', str_contains($h, 'Ajouter un contact'));
chk('fr consent note translated', str_contains($h, 'les DEUX cases sont cochées'));
chk('fr stage slug humanized verbatim', str_contains($h, 'First Contact'));
chk('fr decision slug humanized verbatim', str_contains($h, 'Join Group'));
chk('fr contact name verbatim', str_contains($h, 'Kofi Boateng'));
chk('fr phone verbatim', str_contains($h, '+233111'));
chk('fr next follow-up date verbatim', str_contains($h, '2026-10-12'));
chk('fr location tagged translated', str_contains($h, 'emplacement enregistré'));
chk('fr csrf preserved verbatim', str_contains($h, 'value="TKN"'));
chk('fr summary counts preserved', str_contains($h, '>12<') && str_contains($h, '>3<') && str_contains($h, '>2<'));

// fr singular
$h1 = $render("$viewDir/contacts_index.php", [
    'summary' => ['total' => 1, 'by_temperature' => [], 'due' => 0],
    'contacts' => [['id' => 'x', 'full_name' => 'Solo', 'temperature' => 'cold']],
    'filters' => [], 'temps' => ['hot', 'warm', 'cold'], 'stages' => [], 'decisions' => [], 'csrf' => 'T',
], 'fr');
chk('fr contact singular', (bool) preg_match('/1 contact\b/u', $h1) && ! str_contains($h1, '1 contacts'));

// ar — RTL, empty state, name fallback, temp translated
$h = $render("$viewDir/contacts_index.php", [
    'summary' => ['total' => 0, 'by_temperature' => ['hot' => 0, 'warm' => 0, 'cold' => 0], 'due' => 0],
    'contacts' => [], 'filters' => [], 'temps' => ['hot', 'warm', 'cold'], 'stages' => [], 'decisions' => [], 'csrf' => 'T',
], 'ar');
chk('ar lang=ar dir=rtl', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('ar heading translated', str_contains($h, 'جهات اتصالي'));
chk('ar empty contacts translated', str_contains($h, 'لا توجد جهات اتصال بعد'));
chk('ar temperature hot translated', str_contains($h, 'ساخن'));
chk('ar add contact button translated', str_contains($h, 'إضافة جهة الاتصال'));

$h2 = $render("$viewDir/contacts_index.php", [
    'summary' => ['total' => 1, 'by_temperature' => [], 'due' => 0],
    'contacts' => [['id' => 'x', 'temperature' => 'warm']],
    'filters' => [], 'temps' => ['hot', 'warm', 'cold'], 'stages' => [], 'decisions' => [], 'csrf' => 'T',
], 'ar');
chk('ar name fallback translated', str_contains($h2, 'بدون اسم'));
chk('ar temperature warm translated', str_contains($h2, 'دافئ'));

echo "chain view — localized, self-contained, verbatim ids\n";
$cs = (string) file_get_contents("$viewDir/chain.php");
chk("chain.php calls lang('Referrals.chain.", str_contains($cs, "lang('Referrals.chain."));
chk('chain.php has no hardcoded lang="en"', ! str_contains($cs, 'lang="en"'));
chk('chain.php includes _locale.php', str_contains($cs, '_locale.php'));
chk('chain.php emits dynamic <html lang dir>', str_contains($cs, '_shell_open.php'));
foreach (['<h1>Sponsorship chain', 'Nearest sponsor', 'Top of chain'] as $needle) {
    chk("chain.php no bare '$needle'", ! str_contains($cs, $needle));
}

// fr chain: heading, plural count, badges, verbatim ids, nearest-first order
$hc = $render("$viewDir/chain.php", [
    'memberId' => 'M-root',
    'chain'    => [
        ['level' => 1, 'member_id' => 'S-near'],
        ['level' => 2, 'member_id' => 'S-mid'],
        ['level' => 3, 'member_id' => 'S-top'],
    ],
], 'fr');
chk('fr chain lang=fr dir=ltr', str_contains($hc, 'lang="fr"') && str_contains($hc, 'dir="ltr"'));
chk('fr chain heading translated', str_contains($hc, 'Chaîne de parrainage'));
chk('fr chain many-count interpolated', str_contains($hc, '3 parrains dans la chaîne'));
chk('fr chain nearest badge translated', str_contains($hc, 'Parrain le plus proche'));
chk('fr chain root badge translated', str_contains($hc, 'Sommet de la chaîne'));
chk('fr chain mid level interpolated', str_contains($hc, 'Niveau 2'));
chk('fr chain member id verbatim', str_contains($hc, 'M-root'));
chk('fr chain sponsor ids verbatim', str_contains($hc, 'S-near') && str_contains($hc, 'S-top'));
chk('fr chain nearest before root', strpos($hc, 'S-near') < strpos($hc, 'S-top'));

// singular + empty
$hc1 = $render("$viewDir/chain.php", ['memberId' => 'X', 'chain' => [['level' => 1, 'member_id' => 'Y']]], 'fr');
chk('fr chain singular count', str_contains($hc1, '1 parrain dans la chaîne') && ! str_contains($hc1, '1 parrains'));
$hce = $render("$viewDir/chain.php", ['memberId' => 'Solo', 'chain' => []], 'ar');
chk('ar chain rtl + empty translated', str_contains($hce, 'dir="rtl"') && str_contains($hce, 'لا يوجد كفيل لهذا العضو بعد'));
chk('ar chain member id verbatim', str_contains($hce, 'Solo'));

echo "reassign view — localized, self-contained, csrf, status fallback\n";
$rs = (string) file_get_contents("$viewDir/reassign_index.php");
chk("reassign_index.php calls lang('Referrals.reassign.", str_contains($rs, "lang('Referrals.reassign."));
chk('reassign_index.php has no hardcoded lang="en"', ! str_contains($rs, 'lang="en"'));
chk('reassign_index.php includes _locale.php', str_contains($rs, '_locale.php'));
chk('reassign_index.php emits dynamic <html lang dir>', str_contains($rs, '_shell_open.php'));
foreach (['<h1>Sponsor reassignments', '>Approve<', 'Pending requests'] as $needle) {
    chk("reassign_index.php no bare '$needle'", ! str_contains($rs, $needle));
}

// fr reassign: heading, columns, status label, impact, actions, csrf, verbatim ids
$hr = $render("$viewDir/reassign_index.php", [
    'csrf'     => 'RTKN',
    'requests' => [
        ['id' => 'req1', 'member_id' => 'MEM-1', 'current_sponsor_id' => 'CUR-1', 'new_sponsor_id' => 'NEW-1',
         'requested_by' => 'MK-1', 'reason' => 'moved house', 'status' => 'pending', 'eligibility_state' => 'ok',
         'impact' => ['descendant_count' => 4]],
    ],
], 'fr');
chk('fr reassign lang=fr dir=ltr', str_contains($hr, 'lang="fr"') && str_contains($hr, 'dir="ltr"'));
chk('fr reassign heading translated', str_contains($hr, 'Réattributions de parrain'));
chk('fr reassign status pending translated', str_contains($hr, 'En attente'));
chk('fr reassign impact interpolated', str_contains($hr, '4 filleuls affectés'));
chk('fr reassign approve button translated', str_contains($hr, 'Approuver'));
chk('fr reassign reject button translated', str_contains($hr, 'Rejeter'));
chk('fr reassign submit button translated', str_contains($hr, 'Soumettre la demande'));
chk('fr reassign SoD note translated', str_contains($hr, 'Vous ne pouvez pas approuver votre propre demande.'));
chk('fr reassign csrf present in forms', substr_count($hr, 'name="_csrf" value="RTKN"') >= 4);
chk('fr reassign action posts to approve route', str_contains($hr, '/referrals/sponsor-reassignments/req1/approve'));
chk('fr reassign member id verbatim', str_contains($hr, 'MEM-1') && str_contains($hr, 'NEW-1'));
chk('fr reassign reason verbatim', str_contains($hr, 'moved house'));

// unknown status falls back; non-pending hides actions; ar rtl + empty
$hr2 = $render("$viewDir/reassign_index.php", [
    'csrf' => 'T',
    'requests' => [['id' => 'r2', 'member_id' => 'M', 'new_sponsor_id' => 'N', 'requested_by' => 'RB', 'status' => 'weird_status']],
], 'fr');
chk('fr reassign unknown status falls back (Weird_status)', str_contains($hr2, 'Weird_status'));
chk('fr reassign non-pending hides approve action', ! str_contains($hr2, '/referrals/sponsor-reassignments/r2/approve'));
$hre = $render("$viewDir/reassign_index.php", ['csrf' => 'T', 'requests' => []], 'ar');
chk('ar reassign rtl + empty translated', str_contains($hre, 'dir="rtl"') && str_contains($hre, 'لا توجد طلبات معلّقة بانتظار مراجعتك.'));
chk('ar reassign heading translated', str_contains($hre, 'إعادة تعيين الكفلاء'));

// member_id / new_sponsor_id / approver_id are entity references → roster-backed
// person pickers when a roster is supplied, else bounded text inputs (unchanged).
$hrp = $render("$viewDir/reassign_index.php", [
    'csrf'     => 'T',
    'requests' => [],
    'roster'   => [
        ['id' => 'u-1', 'display_name' => 'Ama Owusu'],
        ['id' => 'u-2', 'display_name' => 'Kofi Mensah'],
    ],
], 'fr');
chk('reassign member picker: <select id="f-member"> required', str_contains($hrp, '<select id="f-member" name="member_id" required>'));
chk('reassign new-sponsor picker: <select id="f-new"> required', str_contains($hrp, '<select id="f-new" name="new_sponsor_id" required>'));
chk('reassign approver picker: <select id="f-approver"> optional', str_contains($hrp, '<select id="f-approver" name="approver_id">'));
chk('reassign pickers: option value = user id + name', str_contains($hrp, 'value="u-1"') && str_contains($hrp, 'Ama Owusu'));
chk('reassign pickers: none option present', str_contains($hrp, lang('Referrals.reassign.personNone')));
// no roster → bounded text fallback (unchanged behaviour)
$hrn = $render("$viewDir/reassign_index.php", ['csrf' => 'T', 'requests' => []], 'fr');
chk('reassign member picker: bounded text fallback when no roster', str_contains($hrn, 'id="f-member" type="text" name="member_id" required maxlength="64"'));
chk('reassign approver picker: bounded text fallback (not required)', str_contains($hrn, 'id="f-approver" type="text" name="approver_id" maxlength="64"'));

// contacts_index target_id is an entity reference (event OR course) → optgroup
// picker when lists are supplied, else bounded text fallback.
$hcp = $render("$viewDir/contacts_index.php", [
    'summary'   => ['total' => 1, 'by_temperature' => [], 'due' => 0],
    'contacts'  => [['id' => 'x', 'full_name' => 'Solo', 'temperature' => 'hot']],
    'filters'   => [], 'temps' => ['hot', 'warm', 'cold'], 'stages' => [], 'decisions' => ['join_group'],
    'csrf'      => 'T',
    'events'    => [['id' => 'ev-1', 'title' => 'Sunday Celebration']],
    'courses'   => [['id' => 'co-1', 'title' => 'Foundations 101']],
], 'fr');
chk('contacts target picker: renders <select name="target_id"> required', str_contains($hcp, '<select name="target_id" required>'));
chk('contacts target picker: events optgroup + option', str_contains($hcp, 'value="ev-1"') && str_contains($hcp, 'Sunday Celebration'));
chk('contacts target picker: courses optgroup + option', str_contains($hcp, 'value="co-1"') && str_contains($hcp, 'Foundations 101'));
chk('contacts target picker: none option present', str_contains($hcp, lang('Referrals.eventCourseIdNone')));
chk('contacts target picker: optgroups labelled by type', str_contains($hcp, '<optgroup label="' . esc(lang('Referrals.typeEvent'), 'attr') . '"') && str_contains($hcp, '<optgroup label="' . esc(lang('Referrals.typeCourse'), 'attr') . '"'));
// no lists → bounded text fallback (unchanged behaviour)
$hcn = $render("$viewDir/contacts_index.php", [
    'summary'   => ['total' => 1, 'by_temperature' => [], 'due' => 0],
    'contacts'  => [['id' => 'x', 'full_name' => 'Solo', 'temperature' => 'hot']],
    'filters'   => [], 'temps' => ['hot', 'warm', 'cold'], 'stages' => [], 'decisions' => ['join_group'], 'csrf' => 'T',
], 'fr');
chk('contacts target picker: bounded text fallback when no events/courses', str_contains($hcn, '<input name="target_id" maxlength="64"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
