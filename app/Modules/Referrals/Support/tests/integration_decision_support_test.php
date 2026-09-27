<?php

declare(strict_types=1);

/**
 * Support-layer test for the integration-decisions catalog + config
 * (FR-REF-3b). Pure and DB-free — the same constants the service and the views
 * share, so this is where the standalone-decisions verdict and the fail-closed
 * config parsing are pinned down.
 *
 *   php app/Modules/Referrals/Support/tests/integration_decision_support_test.php
 */

$root = dirname(__DIR__, 5);
require $root . '/app/Modules/Referrals/Support/IntegrationDecision.php';
require $root . '/app/Modules/Referrals/Support/IntegrationConfig.php';

use WBS\Referrals\Support\IntegrationConfig;
use WBS\Referrals\Support\IntegrationDecision;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ---- 1. The catalog ----------------------------------------------------------
echo "catalog: four standalone required decisions, six types\n";

chk('six decision types, all catalogued', IntegrationDecision::TYPES === [
    'salvation', 'rededication', 'water_baptism', 'holy_spirit_baptism', 'foundation_course', 'join_group',
]);
chk('four required decisions in lifecycle order — baptisms SEPARATE', IntegrationDecision::GROUPS === [
    'salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course',
]);
chk('the two baptism types remain catalogued', IntegrationDecision::BAPTISM_TYPES === ['water_baptism', 'holy_spirit_baptism']);
chk('no bundled baptism group exists', ! in_array('baptism', IntegrationDecision::GROUPS, true)
    && ! array_key_exists('baptism', IntegrationDecision::GROUP_TYPES));
chk('each decision maps 1:1 to its own type', IntegrationDecision::GROUP_TYPES === [
    'salvation'           => ['salvation'],
    'water_baptism'       => ['water_baptism'],
    'holy_spirit_baptism' => ['holy_spirit_baptism'],
    'foundation_course'   => ['foundation_course'],
]);
chk('salvation maps to itself', IntegrationDecision::groupFor('salvation') === 'salvation');
chk('water baptism STANDS ALONE', IntegrationDecision::groupFor('water_baptism') === 'water_baptism');
chk('holy-spirit baptism STANDS ALONE', IntegrationDecision::groupFor('holy_spirit_baptism') === 'holy_spirit_baptism');
chk('foundation_course maps to itself', IntegrationDecision::groupFor('foundation_course') === 'foundation_course');
chk('rededication is history-only (no group)', IntegrationDecision::groupFor('rededication') === null);
chk('join_group is history-only (no group)', IntegrationDecision::groupFor('join_group') === null);
chk('foundation_course is a required type', IntegrationDecision::isRequiredType('foundation_course'));
chk('water_baptism is a required type', IntegrationDecision::isRequiredType('water_baptism'));
chk('holy_spirit_baptism is a required type', IntegrationDecision::isRequiredType('holy_spirit_baptism'));
chk('rededication is NOT a required type', ! IntegrationDecision::isRequiredType('rededication'));
chk('join_group is NOT a required type', ! IntegrationDecision::isRequiredType('join_group'));
chk('bogus types are invalid', ! IntegrationDecision::isValidType('nonsense'));
chk('label keys are namespaced', IntegrationDecision::groupLabelKey('salvation') === 'Referrals.integration.decision.salvation'
    && IntegrationDecision::groupLabelKey('water_baptism') === 'Referrals.integration.decision.water_baptism'
    && IntegrationDecision::typeLabelKey('water_baptism') === 'Referrals.decision.types.water_baptism');

// ---- 2. The verdict -----------------------------------------------------------
echo "\nverdict: integrated only when ALL FOUR standalone decisions carry dates\n";

$cfg = IntegrationConfig::normalize(['enabled' => true]);

$v = IntegrationDecision::integrationOf([], $cfg);
chk('nothing recorded → not integrated, all four outstanding', $v['integrated'] === false && $v['outstanding'] === [
    'salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course',
]);

$v = IntegrationDecision::integrationOf(['salvation'], $cfg);
chk('salvation alone → both baptisms + foundation outstanding', $v['outstanding'] === [
    'water_baptism', 'holy_spirit_baptism', 'foundation_course',
]);

$v = IntegrationDecision::integrationOf(['salvation', 'water_baptism'], $cfg);
chk('water baptism does NOT cover Holy Spirit baptism (standalone)', $v['integrated'] === false
    && $v['outstanding'] === ['holy_spirit_baptism', 'foundation_course']);

$v = IntegrationDecision::integrationOf(['salvation', 'holy_spirit_baptism'], $cfg);
chk('Holy Spirit baptism does NOT cover water baptism (standalone)', $v['integrated'] === false
    && $v['outstanding'] === ['water_baptism', 'foundation_course']);

$v = IntegrationDecision::integrationOf(['salvation', 'water_baptism', 'holy_spirit_baptism'], $cfg);
chk('both baptisms recorded → only foundation outstanding', $v['outstanding'] === ['foundation_course']);

$v = IntegrationDecision::integrationOf(['salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course'], $cfg);
chk('all four → integrated', $v['integrated'] === true && $v['outstanding'] === []);

$v = IntegrationDecision::integrationOf(['rededication', 'join_group'], $cfg);
chk('history-only types satisfy nothing', $v['outstanding'] === [
    'salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course',
]);

$fewer = IntegrationConfig::normalize(['enabled' => true, 'required_groups' => ['salvation']]);
$v     = IntegrationDecision::integrationOf(['salvation'], $fewer);
chk('a body may require fewer decisions (salvation only)', $v['integrated'] === true);

// ---- 3. Config parsing (fail-closed) -----------------------------------------
echo "\nconfig: fail-closed in every direction\n";

chk('null → OFF', IntegrationConfig::normalize(null)['enabled'] === false);
chk('unparseable string → OFF', IntegrationConfig::normalize('not json')['enabled'] === false);
chk('bare int → OFF (no int shape accepted)', IntegrationConfig::normalize(8)['enabled'] === false);
chk('enabled:false with other keys → OFF', IntegrationConfig::normalize(['enabled' => false, 'gate_journey_advance' => true])['enabled'] === false);

$on = IntegrationConfig::normalize(['enabled' => true]);
chk('enabled:true → ON with defaults', $on['enabled'] === true
    && $on['required_groups'] === ['salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course']
    && $on['gate_stages'] === ['in_foundation', 'established']
    && $on['derive_from_enrolment'] === true
    && $on['derive_from_completion'] === false
    && $on['self_declaration_requires_confirmation'] === true);
chk('baptism_mode is gone (each baptism stands alone)', ! array_key_exists('baptism_mode', $on));

$json = IntegrationConfig::normalize(json_encode(['enabled' => true, 'gate_stages' => ['established']], JSON_THROW_ON_ERROR));
chk('JSON-string shape decodes', $json['enabled'] === true && $json['gate_stages'] === ['established']);

$trim = IntegrationConfig::normalize(['enabled' => true, 'foundation_course_categories' => [' Foundation ', 'MEMBERSHIP', '', '  ']]);
chk('categories are trimmed + lowercased + filtered', $trim['foundation_course_categories'] === ['foundation', 'membership']);

$stages = IntegrationConfig::normalize(['enabled' => true, 'gate_stages' => ['in_foundation']]);
chk('gate stages are configurable', $stages['gate_stages'] === ['in_foundation']);

chk('unknown groups are dropped from required_groups', IntegrationConfig::normalize(['enabled' => true, 'required_groups' => ['bogus', 'salvation']])['required_groups'] === ['salvation']);

$legacy = IntegrationConfig::normalize(['enabled' => true, 'required_groups' => ['salvation', 'baptism', 'foundation_course']]);
chk('legacy bundled `baptism` expands to BOTH standalone baptisms', $legacy['required_groups'] === [
    'salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course',
]);

chk('isFoundationCategory is case-insensitive', IntegrationConfig::isFoundationCategory($on, 'Foundations ') === false
    && IntegrationConfig::isFoundationCategory($on, 'membership') === true
    && IntegrationConfig::isFoundationCategory($on, 'leadership') === false);
chk('isGatedStage honours the config', IntegrationConfig::isGatedStage($on, 'established') === true
    && IntegrationConfig::isGatedStage($on, 'worker') === false);

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
