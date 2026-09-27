<?php

declare(strict_types=1);

/**
 * JourneyAttributionService assessment — disciple-making credit (Option D).
 *
 * This is the transition listener that credits the DISCIPLER when a member moves
 * FORWARD on the journey, by delegating to the EXISTING gamification stack
 * (PointsEngine::award) rather than inventing a parallel scoring path. The
 * assessment pins the decision logic and the argument shaping:
 *
 *   - fires ONLY when: config gate on + forward direction + a discipler present
 *     + the required event ids present;
 *   - a regression / 'set' / plain non-forward move earns nothing;
 *   - the award uses the CONFIGURABLE rule code (default disciple.advance);
 *   - the ledger entry is idempotent per (journey, destination stage) via
 *     source_ref "journey:{id}:{to_stage}";
 *   - the discipler's receiving group is the journey group (null → membership
 *     fallback handled downstream), and phase / project_code are forwarded so
 *     the WBS + project boards pick the credit up.
 *
 * PointsEngine and ConfigService are `final`, so we register SPY classes under
 * their exact FQCNs and never load the real files (these standalone tests use
 * manual require_once, so loading is fully controlled). The spy records every
 * award() call for assertion.
 *
 *   php app/Modules/Journey/Services/tests/journey_attribution_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
    }
}

namespace WBS\Gamification\Services {

    use WBS\Shared\Support\Result;

    /** Spy ConfigService: values seeded per (org,key). */
    final class ConfigService
    {
        /** @var array<string,mixed> */
        public array $values = [];

        public function get(string $organizationId, string $key, mixed $default = null): mixed
        {
            return $this->values[$organizationId . '|' . $key] ?? $default;
        }
    }

    /** Spy PointsEngine: records award() calls, returns a canned Result. */
    final class PointsEngine
    {
        /** @var list<array{org:string,rule:string,subject:string,ref:string,opts:array}> */
        public array $calls = [];

        public function award(
            string $organizationId,
            string $ruleCode,
            string $subjectId,
            string $sourceRef,
            array $opts = [],
        ): Result {
            $this->calls[] = [
                'org' => $organizationId, 'rule' => $ruleCode, 'subject' => $subjectId,
                'ref' => $sourceRef, 'opts' => $opts,
            ];

            return Result::ok(['awarded' => true]);
        }
    }
}

namespace {
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyTransitionListener.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyAttributionService.php';

    use WBS\Gamification\Services\ConfigService;
    use WBS\Gamification\Services\PointsEngine;
    use WBS\Journey\Services\JourneyAttributionService;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    /** Build a fresh (service, config, points) triple with the gate preset. */
    $make = static function (bool $enabled, ?string $ruleCode = null): array {
        $config = new ConfigService();
        $config->values['org1|disciplemaking_award_enabled'] = $enabled;
        if ($ruleCode !== null) {
            $config->values['org1|disciplemaking_rule_code'] = $ruleCode;
        }
        $points = new PointsEngine();

        return [new JourneyAttributionService($config, $points), $config, $points];
    };

    $baseEvent = static fn (array $o = []): array => array_merge([
        'organization_id' => 'org1',
        'journey_id'      => 'jrn1',
        'user_id'         => 'disciple1',
        'group_id'        => 'g_cell',
        'from_stage'      => 'seeker',
        'to_stage'        => 'new_believer',
        'to_phase'        => 'build',
        'direction'       => 'advance',
        'discipler_id'    => 'leader1',
    ], $o);

    // ---- happy path --------------------------------------------------------
    echo "advance with discipler + gate on -> awards\n";
    [$svc, , $pe] = $make(true);
    $svc->onTransition($baseEvent());
    chk('one award emitted', count($pe->calls) === 1, (string) count($pe->calls));
    if ($pe->calls) {
        $c = $pe->calls[0];
        chk('awarded to the discipler', $c['subject'] === 'leader1');
        chk('org forwarded', $c['org'] === 'org1');
        chk('default rule code used', $c['rule'] === 'disciple.advance', $c['rule']);
        chk('idempotent source_ref', $c['ref'] === 'journey:jrn1:new_believer', $c['ref']);
        chk('receiving group = journey group', ($c['opts']['receiving_group_id'] ?? null) === 'g_cell');
        chk('phase forwarded', ($c['opts']['phase'] ?? null) === 'build');
        chk('subject_type user', ($c['opts']['subject_type'] ?? null) === 'user');
        chk('event data carries to_stage', ($c['opts']['data']['to_stage'] ?? null) === 'new_believer');
        chk('event data carries user_id', ($c['opts']['data']['user_id'] ?? null) === 'disciple1');
    }

    // ---- configurable rule code -------------------------------------------
    echo "configurable rule code\n";
    [$svc, , $pe] = $make(true, 'custom.disciple.win');
    $svc->onTransition($baseEvent());
    chk('custom rule code honored', ($pe->calls[0]['rule'] ?? '') === 'custom.disciple.win');

    // ---- project_code forwarding ------------------------------------------
    echo "project attribution\n";
    [$svc, , $pe] = $make(true);
    $svc->onTransition($baseEvent(['project_code' => 'harvest25']));
    chk('project_code forwarded to opts', ($pe->calls[0]['opts']['project_code'] ?? null) === 'harvest25');
    chk('project_code echoed in data', ($pe->calls[0]['opts']['data']['project_code'] ?? null) === 'harvest25');

    // ---- gate off ----------------------------------------------------------
    echo "config gate default off\n";
    [$svc, , $pe] = $make(false);
    $svc->onTransition($baseEvent());
    chk('gate off -> no award', $pe->calls === []);

    // ---- open landing above entry is forward ------------------------------
    echo "direction rules\n";
    [$svc, , $pe] = $make(true);
    $svc->onTransition($baseEvent(['direction' => 'open']));
    chk('open (with discipler) awards', count($pe->calls) === 1);

    [$svc, , $pe] = $make(true);
    $svc->onTransition($baseEvent(['direction' => 'regress', 'to_stage' => 'seeker']));
    chk('regress -> no award', $pe->calls === []);

    [$svc, , $pe] = $make(true);
    $svc->onTransition($baseEvent(['direction' => 'set']));
    chk('set (manual correction) -> no award', $pe->calls === []);

    // ---- missing discipler -------------------------------------------------
    echo "no discipler\n";
    [$svc, , $pe] = $make(true);
    $svc->onTransition($baseEvent(['discipler_id' => '']));
    chk('blank discipler -> no award', $pe->calls === []);

    [$svc, , $pe] = $make(true);
    $ev = $baseEvent();
    unset($ev['discipler_id']);
    $svc->onTransition($ev);
    chk('absent discipler -> no award', $pe->calls === []);

    // ---- missing required ids ---------------------------------------------
    echo "malformed events\n";
    [$svc, , $pe] = $make(true);
    $svc->onTransition($baseEvent(['journey_id' => '']));
    chk('missing journey_id -> no award', $pe->calls === []);

    [$svc, , $pe] = $make(true);
    $svc->onTransition($baseEvent(['to_stage' => '']));
    chk('missing to_stage -> no award', $pe->calls === []);

    [$svc, , $pe] = $make(true);
    $svc->onTransition($baseEvent(['organization_id' => '']));
    chk('missing org -> no award', $pe->calls === []);

    // ---- null group falls through to membership ---------------------------
    echo "org-level advance (no group)\n";
    [$svc, , $pe] = $make(true);
    $ev = $baseEvent();
    unset($ev['group_id']);
    $svc->onTransition($ev);
    chk('null receiving group forwarded', array_key_exists('receiving_group_id', $pe->calls[0]['opts'])
        && $pe->calls[0]['opts']['receiving_group_id'] === null);

    // ---- empty configured rule code disables ------------------------------
    echo "empty configured rule code\n";
    [$svc, , $pe] = $make(true, '');
    $svc->onTransition($baseEvent());
    chk('empty rule code -> no award', $pe->calls === []);

    // ---- listener must not throw ------------------------------------------
    echo "listener is side-effect-safe\n";
    $threw = false;
    try {
        [$svc, , $pe] = $make(true);
        $svc->onTransition([]); // completely empty event
    } catch (\Throwable $e) {
        $threw = true;
    }
    chk('empty event does not throw', ! $threw);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
