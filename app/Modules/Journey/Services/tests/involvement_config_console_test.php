<?php

declare(strict_types=1);

/**
 * INVOLVEMENT-triage CONFIG CONSOLE test — the admin surface that switches
 * involvement-based triage ON/OFF and tunes window / activity target / band
 * thresholds / quantum weights for a context, persisting through the platform's
 * single hierarchical config store (no parallel config).
 *
 * Service (unit):
 *   - configView() returns the resolved effective values + enable flag + built-in
 *     defaults + clamp bounds + the threshold/weight key lists + a writable flag;
 *   - saveConfig() CLAMPS every value to the reader's bounds (window 30..365,
 *     target 1..100, downline_share_pct 0..100, others >= 0), coerces the enable
 *     flag, writes only KNOWN keys through the ConfigWriterPort, targets the
 *     org-root group for the org-wide context, and round-trips back through the
 *     reader; a missing writer fails cleanly.
 *
 * Wiring (source asserts): controller GET/POST, routes (GET read + POST
 * webcsrf-gated), the self-contained CSP-clean view, and 6-locale i18n parity.
 *
 *   php app/Modules/Journey/Services/tests/involvement_config_console_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }
    }
}

namespace Fake {
    // Minimal query-builder fake: only what orgRootGroup() needs (groups table
    // select/where/orderBy/get->getRowArray). Everything else is unused here.
    class QB
    {
        private array $wheres = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        private function base(): string { return explode(' ', trim($this->t))[0]; }

        public function select($s) { return $this; }

        public function where($k, $v = null) { $this->wheres[trim((string) $k)] = $v; return $this; }

        public function orderBy($c, $d = 'ASC') { return $this; }

        public function get() { return $this; }

        public function getRowArray()
        {
            $rows = $this->db->rows[$this->base()] ?? [];
            foreach ($rows as $r) {
                $ok = true;
                foreach ($this->wheres as $k => $v) {
                    if ($k === 'organization_id' && ($r['organization_id'] ?? null) !== $v) { $ok = false; break; }
                    if ($k === 'status' && ($r['status'] ?? null) !== $v) { $ok = false; break; }
                }
                if ($ok) { return $r; }
            }
            return null;
        }

        public function getResultArray() { return []; }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Journey\Services\ConfigResolverPort;
    use WBS\Journey\Services\ConfigWriterPort;
    use WBS\Journey\Services\InvolvementService;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyTransitionListener.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementTriagePort.php';
    require_once $root . '/app/Modules/Journey/Services/ConfigResolverPort.php';
    require_once $root . '/app/Modules/Journey/Services/ConfigWriterPort.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementSourcePort.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementService.php';

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    \WBS\Shared\Support\Clock::freeze(new \DateTimeImmutable('2026-09-14 12:00:00', new \DateTimeZone('UTC')));
    $clock = new \WBS\Shared\Support\Clock();

    // Config read/write ports sharing ONE in-memory store so a save round-trips.
    $store = new class {
        /** @var array<string,mixed> */
        public array $data = [];
    };
    $reader = new class($store) implements ConfigResolverPort {
        public function __construct(private $s) {}
        public function value(string $groupId, string $capability): mixed
        {
            return $this->s->data[$groupId . '|' . $capability] ?? null;
        }
    };
    $writer = new class($store) implements ConfigWriterPort {
        public array $calls = [];
        public function __construct(private $s) {}
        public function set(string $organizationId, string $groupId, string $capability, mixed $value, ?string $actorId = null): bool
        {
            $this->calls[] = [$organizationId, $groupId, $capability, $value, $actorId];
            $this->s->data[$groupId . '|' . $capability] = $value;
            return true;
        }
    };

    $db = new BaseConnection();
    $db->rows['groups'] = [
        ['id' => 'grp-root', 'organization_id' => 'org-1', 'status' => 'active', 'depth' => 0, 'created_at' => '2026-01-01 00:00:00'],
    ];

    $svc = new InvolvementService($db, $clock, null, $reader, null, $writer);

    // ── 1. configView defaults ───────────────────────────────────────────────
    echo "configView() view-model\n";
    $vm = $svc->configView('org-1', null);
    chk('enabled defaults OFF', $vm['enabled'] === false);
    chk('window default 90', $vm['window_days'] === 90);
    chk('activity target default 4', $vm['activity_target'] === 4);
    chk('bounds expose window 30..365', $vm['bounds']['window_min'] === 30 && $vm['bounds']['window_max'] === 365);
    chk('bounds expose target max 100', $vm['bounds']['target_max'] === 100);
    chk('threshold keys present', in_array('cold_floor_bps', $vm['threshold_keys'], true) && in_array('hot_quantum', $vm['threshold_keys'], true));
    chk('weight keys present', in_array('points', $vm['weight_keys'], true) && in_array('downline_share_pct', $vm['weight_keys'], true));
    chk('defaults carry threshold + weight maps', ($vm['defaults']['thresholds']['cold_floor_bps'] ?? null) === 2500 && ($vm['defaults']['weights']['per_sponsorship'] ?? null) === 50);
    chk('writable true when writer wired', $vm['writable'] === true);

    // ── 2. saveConfig clamps + round-trips ───────────────────────────────────
    echo "saveConfig() clamp + persist + round-trip\n";
    $res = $svc->saveConfig('org-1', null, [
        'enabled'         => '1',
        'window_days'     => '9999',   // clamps to 365
        'activity_target' => '0',      // clamps to 1
        'th_cold_floor_bps'        => '3000',
        'th_hot_quantum'           => '-5',   // clamps to 0
        'wt_points'                => '2',
        'wt_downline_share_pct'    => '250',  // clamps to 100
    ], 'actor-1');
    chk('save ok', $res->ok, (string) $res->message);
    chk('save targets org-root group', ($res->data['target_group_id'] ?? null) === 'grp-root');
    chk('save reports saved capabilities', in_array(InvolvementService::CAP_ENABLED, $res->data['saved'] ?? [], true));

    // Round-trip through the reader.
    $vm2 = $svc->configView('org-1', null);
    chk('round-trip: enabled now ON', $vm2['enabled'] === true);
    chk('round-trip: window clamped to 365', $vm2['window_days'] === 365);
    chk('round-trip: target clamped to 1', $vm2['activity_target'] === 1);
    chk('round-trip: threshold cold_floor_bps=3000', $vm2['thresholds']['cold_floor_bps'] === 3000);
    chk('round-trip: negative hot_quantum clamped to 0', $vm2['thresholds']['hot_quantum'] === 0);
    chk('round-trip: weight points=2', $vm2['weights']['points'] === 2);
    chk('round-trip: downline_share_pct clamped to 100', $vm2['weights']['downline_share_pct'] === 100);

    // Unknown keys are ignored (only the 5 capabilities are written).
    $caps = array_unique(array_map(static fn ($c) => $c[2], $writer->calls));
    chk('writes exactly the 5 involvement capabilities', count($caps) === 5
        && in_array(InvolvementService::CAP_WINDOW, $caps, true)
        && in_array(InvolvementService::CAP_THRESHOLDS, $caps, true)
        && in_array(InvolvementService::CAP_WEIGHTS, $caps, true));
    chk('enable flag persisted as bool true', ($store->data['grp-root|' . InvolvementService::CAP_ENABLED] ?? null) === true);
    chk('thresholds persisted as an array', is_array($store->data['grp-root|' . InvolvementService::CAP_THRESHOLDS] ?? null));

    // ── 3. enable=off coercion ───────────────────────────────────────────────
    echo "enable flag coercion\n";
    $svc->saveConfig('org-1', null, ['enabled' => '0'], 'actor-1');
    chk('unchecked enable persists false', $svc->configView('org-1', null)['enabled'] === false);

    // ── 4. missing writer fails cleanly ──────────────────────────────────────
    echo "no-writer guard\n";
    $roSvc = new InvolvementService($db, $clock, null, $reader, null, null);
    $vmRo  = $roSvc->configView('org-1', null);
    chk('configView reports NOT writable without a writer', $vmRo['writable'] === false);
    $roRes = $roSvc->saveConfig('org-1', null, ['enabled' => '1'], 'actor-1');
    chk('saveConfig fails cleanly without a writer', ! $roRes->ok && $roRes->status === 500);

    // ── 5. Controller + routes + view + i18n wiring ──────────────────────────
    echo "controller / route / view / i18n wiring\n";
    $ctrl = (string) file_get_contents($root . '/app/Modules/Journey/Controllers/JourneyController.php');
    chk('controller has involvementConfig read', str_contains($ctrl, 'function involvementConfig') && str_contains($ctrl, 'configView('));
    chk('controller renders involvement_config view', str_contains($ctrl, 'WBS\\Journey\\Views\\involvement_config'));
    chk('controller has saveInvolvementConfig write', str_contains($ctrl, 'function saveInvolvementConfig') && str_contains($ctrl, 'saveConfig('));
    chk('save is scope-checked', (bool) preg_match('/function saveInvolvementConfig.*?authorizeGroupScope/s', $ctrl));
    chk('save PRGs with savedFlash', (bool) preg_match("/function saveInvolvementConfig.*?'savedFlash'/s", $ctrl));

    $routes = (string) file_get_contents($root . '/app/Config/Routes.php');
    chk('GET involvement/config route present', (bool) preg_match('#\$routes->get\(\'involvement/config\',[^\n]*involvementConfig#', $routes));
    if (preg_match('#\$routes->post\(\'involvement/config\',[^\n]*saveInvolvementConfig[^\n]*#', $routes, $m)) {
        chk('POST involvement/config webcsrf-guarded', str_contains($m[0], 'webcsrf'));
        chk('POST involvement/config gated gamification.manage', str_contains($m[0], 'authorize:gamification.manage'));
    } else {
        chk('POST involvement/config present', false);
    }

    $view = (string) file_get_contents($root . '/app/Modules/Journey/Views/involvement_config.php');
    chk('view posts to /journey/involvement/config', str_contains($view, 'action="/journey/involvement/config"'));
    chk('view binds hidden _csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $view));
    chk('view has the enable checkbox', str_contains($view, 'name="enabled"'));
    chk('view renders threshold + weight fields', str_contains($view, 'name="th_') && str_contains($view, 'name="wt_'));
    chk('view is self-contained (own <html> + _locale)', str_contains($view, '_locale.php') && str_contains($view, '<html'));
    $noC = (string) preg_replace('#/\*.*?\*/#s', '', $view);
    chk('view CSP-clean: no <script>', ! str_contains($noC, '<script'));
    chk('view CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', $noC));

    $enIC = (require $root . '/app/Modules/Journey/Language/en/Journey.php')['admin']['involvementConfig'] ?? [];
    chk('en involvementConfig block present (>= 25 keys)', count($enIC) >= 25, (string) count($enIC));
    foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $ic = (require $root . "/app/Modules/Journey/Language/$loc/Journey.php")['admin']['involvementConfig'] ?? [];
        chk("$loc mirrors involvementConfig keys", array_diff(array_keys($enIC), array_keys($ic)) === [],
            'missing: ' . implode(',', array_diff(array_keys($enIC), array_keys($ic))));
    }
    // top-level pipeline link label parity
    foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $j = require $root . "/app/Modules/Journey/Language/$loc/Journey.php";
        chk("$loc has involvementSettings link label", isset($j['involvementSettings']) && $j['involvementSettings'] !== '');
    }

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail > 0 ? 1 : 0);
}
