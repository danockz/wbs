<?php

declare(strict_types=1);

/**
 * GROUP NOTIFICATION CREDENTIALS screen — view, CSRF, routes, menu, i18n.
 *
 * The resolution contract is pinned by
 * Services/tests/notification_credentials_test.php and the chain behaviour by
 * Transport/tests/nalo_sms_chain_test.php; this file guards the human surface a
 * leader actually uses to provide and share their body's account:
 *
 *  1. every write form carries the double-submit _csrf token, and every POST
 *     route is webcsrf-guarded (the secret route is rate-limited as well);
 *  2. the read is gated by `provider.configure` and stays a plain GET;
 *  3. the controller delegates to the Integrations services (one writer, no
 *     fork), bounds every read and write to the actor's leadership scope through
 *     the PDP, and never echoes secret material;
 *  4. the page is discoverable: a COMMUNICATIONS MenuItem + a
 *     `menuItems.comms_credentials` label in all six locales;
 *  5. `credentials.*` mirrors English in all six locales, and the page is
 *     self-contained (own <html>, _locale.php, dynamic lang/dir, RTL-correct)
 *     with localized fixed vocabularies and raw-value fallbacks;
 *  6. the FAIL-CLOSED story is on the page: a group with no credential shows the
 *     refusal panel, a granted hop is labelled as somebody else's account, and
 *     nothing anywhere offers the platform's own key as a fallback.
 *
 *   php app/Modules/Notifications/Views/tests/group_credentials_view_test.php
 */

$root       = dirname(__DIR__, 5);
$viewDir    = $root . '/app/Modules/Notifications/Views';
$langDir    = $root . '/app/Modules/Notifications/Language';
$controller = $root . '/app/Modules/Notifications/Controllers/GroupCredentialController.php';
$routesFile = $root . '/app/Config/Routes.php';
$menuFile   = $root . '/app/Modules/Shared/Navigation/CoreMenuProvider.php';
$appLangDir = $root . '/app/Language';

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

$view = (string) file_get_contents("$viewDir/group_credentials.php");
$ctrl = (string) file_get_contents($controller);
$r    = (string) file_get_contents($routesFile);
$menu = (string) file_get_contents($menuFile);

// ── 1. Forms carry the double-submit token ───────────────────────────────────
echo "group_credentials.php forms carry the double-submit token\n";
chk('the page has write forms at all', substr_count($view, '<form') >= 5, (string) substr_count($view, '<form'));
$forms = preg_split('/<form\b/', $view) ?: [];
$missing = 0;
foreach (array_slice($forms, 1) as $f) {
    $body = substr($f, 0, (int) strpos($f, '</form>'));
    if (str_contains($f, 'method="get"')) {
        continue; // the group picker is a read
    }
    if (! str_contains($body, 'name="_csrf"')) {
        $missing++;
    }
}
chk('every POST form carries _csrf', $missing === 0, (string) $missing);
chk('the token comes from the controller', str_contains($view, "value=\"<?= esc(\$csrf, 'attr') ?>\""));
chk('the group picker is a GET (no token needed)', str_contains($view, 'method="get" action="/notifications/credentials"'));
chk('secret inputs are password fields with autocomplete off',
    str_contains($view, 'type="password" name="secret" required autocomplete="off"'));
chk('and a stored secret is never echoed back',
    ! preg_match('/name="secret"[^>]*value=/i', $view) && ! str_contains($view, "['cipher']"));
chk('revoke asks for confirmation', str_contains($view, 'revokeConfirm'));

// ── 2. Routes ────────────────────────────────────────────────────────────────
echo "routes\n";
$routeNeedles = [
    'index'    => ["get('credentials'", ['auth', 'authorize:provider.configure'], ['webcsrf']],
    'create'   => ["post('credentials/connections'", ['auth', 'authorize:provider.configure', 'webcsrf'], []],
    'secrets'  => ["post('credentials/connections/(:segment)/secrets'", ['auth', 'ratelimit:provider.configure', 'webcsrf'], []],
    'grants'   => ["post('credentials/connections/(:segment)/grants'", ['auth', 'authorize:provider.configure', 'webcsrf'], []],
    'revoke'   => ["post('credentials/grants/(:segment)/revoke'", ['auth', 'authorize:provider.configure', 'webcsrf'], []],
];
foreach ($routeNeedles as $name => [$needle, $want, $forbid]) {
    $at = strpos($r, $needle);
    if ($at === false) {
        chk("$name route present", false, $needle);
        continue;
    }
    $line = substr($r, $at, (int) strpos(substr($r, $at), "\n"));
    chk("$name route present", true);
    chk("$name points at GroupCredentialController", str_contains($line, 'GroupCredentialController'));
    foreach ($want as $f) {
        chk("$name is $f-gated", str_contains($line, $f));
    }
    foreach ($forbid as $f) {
        chk("$name has no $f", ! str_contains($line, $f));
    }
}
chk('no new permission bit was spent (reuses provider.configure)',
    ! str_contains($r, 'notification.credentials') && substr_count($r, 'provider.configure') >= 5);

// ── 3. Controller wiring ─────────────────────────────────────────────────────
echo "controller\n";
chk('mints a csrf token', str_contains($ctrl, 'issueCsrf()'));
chk('sets the wbs_csrf cookie', str_contains($ctrl, "'wbs_csrf'"));
chk('never caches the page', str_contains($ctrl, 'no-store') && str_contains($ctrl, 'nosniff'));
chk('still returns JSON for API clients', str_contains($ctrl, 'if ($this->wantsJson())'));
chk('PRGs browsers after a write', str_contains($ctrl, 'redirect()->to(self::DASHBOARD)'));
chk('the dashboard constant matches the route', str_contains($ctrl, "'/notifications/credentials'"));
chk('delegates writes to the Integrations services (one writer, no fork)',
    str_contains($ctrl, 'IntegrationServices::connections()->create(')
    && str_contains($ctrl, 'IntegrationServices::connections()->setCredential(')
    && str_contains($ctrl, 'IntegrationServices::connections()->grantCapability(')
    && str_contains($ctrl, 'IntegrationServices::connections()->revokeGrant('));
chk('and never writes a credential table itself',
    ! str_contains($ctrl, "table('connection_credentials'") && ! str_contains($ctrl, "table('capability_grants'"));
chk('the effective chain comes from the real send path',
    str_contains($ctrl, 'transportRegistry()') && str_contains($ctrl, 'planFor($orgId, $groupId)'));
chk('every read and write is bounded to the actor\'s leadership scope',
    str_contains($ctrl, "canManageGroupScope(\n                'provider.configure',")
    || str_contains($ctrl, "canManageGroupScope('provider.configure'"));
chk('scope answers are memoised per group', str_contains($ctrl, 'private array $scopeCache = [];'));
chk('a connection outside the scope is refused before any write',
    substr_count($ctrl, '$this->connectionOutOfScope(') >= 3);
chk('hand-picked grant groups are each scope-checked',
    str_contains($ctrl, 'foreach (is_array($groups) ? $groups : [] as $gid)')
    && str_contains($ctrl, '! $this->inScope((string) $gid)'));
chk('capability and scope_mode are validated against fixed vocabularies',
    str_contains($ctrl, 'in_array($capability, self::CAPABILITIES, true)')
    && str_contains($ctrl, 'ScopeMode::isValid($scopeMode)'));
chk('a new account must name an ACTIVE sms adapter from the catalogue',
    str_contains($ctrl, "Result::fail('BAD_ADAPTER', 'integration.adapter_not_found', 422)")
    && str_contains($ctrl, 'foreach ($this->smsAdapters() as $a)'));
chk("the adapter version comes from the catalogue, not the form",
    str_contains($ctrl, "'adapter_version' => (int) (\$catalog['version'] ?? 1)")
    && ! str_contains($ctrl, "\$in['adapter_version']"));
chk('a share window is normalized, so an empty date is NULL and an expiry is inclusive',
    str_contains($ctrl, "'starts_at'        => \$this->windowStamp(\$in['starts_at'] ?? null)")
    && str_contains($ctrl, "'expires_at'       => \$this->windowStamp(\$in['expires_at'] ?? null, true)")
    && str_contains($ctrl, '23:59:59') && str_contains($ctrl, 'checkdate('));
chk('a new account is always a notification account on the sms channel',
    str_contains($ctrl, "'category'        => 'notification'") && str_contains($ctrl, "'channels'        => ['sms']"));
chk('and is owned by the group the leader picked, never by the actor',
    str_contains($ctrl, "'group_id'        => \$groupId"));
chk("a secret write is limited to the adapter's own slots",
    str_contains($ctrl, "self::ADAPTER_SLOTS[(string) (\$row['adapter_code'] ?? '')]")
    && str_contains($ctrl, "'BAD_SLOT'") && str_contains($ctrl, "integration.credential_bad_slot"));
chk('the nalo slots match the documented provider contract',
    str_contains($ctrl, "'nalo_sms_v1'    => ['username', 'password', 'auth_key']"));
chk('one allowlist feeds both the form and the write', substr_count($ctrl, 'ADAPTER_SLOTS') >= 3,
    (string) substr_count($ctrl, 'ADAPTER_SLOTS'));
chk('an empty secret is refused before the vault is touched', str_contains($ctrl, "'EMPTY_SECRET'"));
chk('only non-secret wire config is written to settings',
    str_contains($ctrl, 'api_base_url') && ! str_contains($ctrl, "'api_key'        =>"));

// ── 4. Discoverability ───────────────────────────────────────────────────────
echo "menu\n";
chk('a COMMUNICATIONS menu item exists', str_contains($menu, "'comms.credentials'"));
$at = strpos($menu, "'comms.credentials'");
$line = $at === false ? '' : substr($menu, $at, (int) strpos(substr($menu, $at), "\n"));
chk('it points at the credentials dashboard', str_contains($line, 'notifications/credentials'));
chk('gated by provider.configure', str_contains($line, "permissions: ['provider.configure']"));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $app = require "$appLangDir/$loc/App.php";
    $label = $app['menuItems']['comms_credentials'] ?? null;
    chk("$loc has a localized menu label", is_string($label) && $label !== '', json_encode($label));
}

// ── 5. i18n parity across the six locales ────────────────────────────────────
echo "i18n parity\n";
$baseKeys = null;
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $file = require "$langDir/$loc/Notifications.php";
    chk("$loc has a credentials block", isset($file['credentials']) && is_array($file['credentials']));
    $keys = $flatten($file['credentials'] ?? []);
    if ($baseKeys === null) {
        $baseKeys = $keys;
        echo '  ·    en baseline: ' . count($keys) . " keys\n";
        continue;
    }
    chk("$loc mirrors English (" . count($keys) . ' keys)',
        array_diff($baseKeys, $keys) === array_diff($keys, $baseKeys) && array_diff($baseKeys, $keys) === [],
        json_encode(array_values(array_slice(array_merge(array_diff($baseKeys, $keys), array_diff($keys, $baseKeys)), 0, 6))));
}
$en = require "$langDir/en/Notifications.php";
chk('the scope vocabulary covers every ScopeMode',
    array_keys($en['credentials']['scope']) === ['self', 'self_and_descendants', 'descendants_only', 'groups'],
    json_encode(array_keys($en['credentials']['scope'])));
chk('the provenance vocabulary covers own + granted',
    array_keys($en['credentials']['via']) === ['own', 'granted']);
chk('the status vocabulary covers the connection lifecycle',
    count(array_diff(['draft', 'tested', 'pending_approval', 'active', 'disabled', 'revoked'],
        array_keys($en['credentials']['status']))) === 0);
chk('flash messages exist for every write the controller performs',
    count(array_diff(['createdFlash', 'secretFlash', 'grantedFlash', 'revokedFlash'],
        array_keys($en['credentials']))) === 0);

// every key the view asks for exists in English
$used = [];
preg_match_all("/Notifications\.credentials\.([A-Za-z0-9_.]+)/", $view, $m);
foreach ($m[1] as $k) {
    $used[$k] = true;
}
$missingKeys = [];
foreach (array_keys($used) as $k) {
    $node = $en['credentials'];
    foreach (explode('.', $k) as $seg) {
        if (! is_array($node) || ! array_key_exists($seg, $node)) {
            $missingKeys[] = $k;
            $node = null;
            break;
        }
        $node = $node[$seg];
    }
}
chk('every literal key the view uses is translated', $missingKeys === [], json_encode($missingKeys));

// ── 6. Self-contained, CSP-safe, escaped ─────────────────────────────────────
echo "page hygiene\n";
chk('uses the shared HTML shell',
    str_contains($view, '_shell_open.php') && str_contains($view, '_shell_close.php'));
chk('includes _locale.php', str_contains($view, "include __DIR__ . '/_locale.php';"));
chk('no external resources (CSP-safe)', ! preg_match('#(src|href)\s*=\s*["\']https?://#i', $view));
chk('no inline event handlers except the two allowlisted helpers',
    substr_count($view, 'onchange=') <= 1 && ! str_contains($view, 'onclick='));
$echos = preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $view, $em);
$unsafe = [];
$allowlist = ['$idAttr', '(int) $i + 1'];
foreach ($em[1] as $body) {
    // A ternary whose two branches are both quoted literals can only ever emit a
    // constant (a css class, an attribute, a fixed vocabulary token).
    $literalTernary = (bool) preg_match("/\?\s*'[^']*'\s*:\s*'[^']*'\s*$/", $body);
    $safe = str_starts_with($body, 'esc(') || $literalTernary || in_array(trim($body), $allowlist, true);
    if (! $safe) {
        $unsafe[] = $body;
    }
}
chk("every echo is escaped or allowlisted ($echos echoes)", $unsafe === [], json_encode(array_slice($unsafe, 0, 4)));
chk('the page never mentions env, keys or the platform fallback',
    ! str_contains(strtolower($view), 'getenv') && ! str_contains(strtolower($view), 'mnotify_api_key')
    && ! str_contains(strtolower($view), 'sms_api_key'));

// ── 7. Render smoke ──────────────────────────────────────────────────────────
echo "render smoke\n";
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
            public function getLocale()
            {
                return $GLOBALS['__credLoc'] ?? 'en';
            }
        };
    }
}
if (! function_exists('config')) {
    function config($c)
    {
        return new class {
            /** @var list<string> */
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Notifications') {
            return $key;
        }
        $v = $GLOBALS['__credLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }

        return $v;
    }
}
$render = static function (array $data, string $loc) use ($viewDir, $langDir): string {
    $GLOBALS['__credLoc']  = $loc;
    $GLOBALS['__credLang'] = require $langDir . "/$loc/Notifications.php";
    extract($data);
    ob_start();
    include $viewDir . '/group_credentials.php';

    return (string) ob_get_clean();
};

$groups = [
    ['id' => 'g-nat', 'name' => 'National', 'depth' => 0, 'status' => 'active'],
    ['id' => 'g-region', 'name' => 'Greater Accra', 'depth' => 1, 'status' => 'active'],
    ['id' => 'g-cell', 'name' => 'Cell 12', 'depth' => 3, 'status' => 'active'],
];
$groupNames = ['g-nat' => 'National', 'g-region' => 'Greater Accra', 'g-cell' => 'Cell 12'];
$adapters = [
    ['code' => 'mnotify_sms_v1', 'display_name' => 'mNotify SMS (Ghana)', 'category' => 'notification'],
    ['code' => 'nalo_sms_v1', 'display_name' => 'Nalo Solutions SMS (Ghana)', 'category' => 'notification'],
];
$slots = ['mnotify_sms_v1' => ['api_key'], 'nalo_sms_v1' => ['username', 'password', 'auth_key']];
$scopes = ['self', 'self_and_descendants', 'descendants_only', 'groups'];

$cellConn = [
    'id' => 'conn-cell', 'group_id' => 'g-cell', 'adapter_code' => 'mnotify_sms_v1', 'adapter_version' => 1,
    'category' => 'notification', 'display_name' => 'Cell 12 SMS', 'channels' => '["sms"]',
    'settings' => '{"provider_order":"mnotify,nalo"}', 'sender_identity' => 'CELL12',
    'status' => 'active', 'requested_by' => 'u-1', 'approved_by' => 'u-2',
    'tested_at' => '2026-09-18 09:00:00', 'created_at' => '2026-09-01 09:00:00',
];
$draftConn = array_merge($cellConn, ['id' => 'conn-draft', 'display_name' => 'Region Nalo (draft)',
    'adapter_code' => 'nalo_sms_v1', 'status' => 'draft', 'group_id' => 'g-region']);
$grants = [
    'conn-cell' => [
        [
            'id' => 'gr-1', 'grantee_group_id' => 'g-cell', 'capability' => 'sms.send',
            'scope_mode' => 'self_and_descendants', 'include_crosscut' => 1, 'constraints' => null,
            'starts_at' => '2026-09-01 00:00:00', 'expires_at' => null, 'status' => 'active',
            'created_at' => '2026-09-01 00:00:00', 'groups' => [],
        ],
        [
            'id' => 'gr-2', 'grantee_group_id' => 'g-region', 'capability' => 'sms.send',
            'scope_mode' => 'groups', 'include_crosscut' => 0, 'constraints' => null,
            'starts_at' => '2026-09-01 00:00:00', 'expires_at' => '2026-12-31 00:00:00', 'status' => 'active',
            'created_at' => '2026-09-02 00:00:00', 'groups' => ['g-cell', 'g-nat'],
        ],
    ],
    'conn-draft' => [],
];

$base = [
    'groups' => $groups, 'groupNames' => $groupNames, 'adapters' => $adapters, 'slots' => $slots,
    'scopes' => $scopes, 'capabilities' => ['sms.send'], 'csrf' => 'TOK-1',
];

// (a) A live chain: own account first, a granted one second.
$h = $render($base + [
    'connections' => [$cellConn, $draftConn],
    'grants'      => $grants,
    'selected'    => 'g-cell',
    'plan'        => [
        ['provider' => 'mnotify', 'connection_id' => 'conn-cell', 'owner_group_id' => 'g-cell',
         'via' => 'own', 'scope_mode' => 'self', 'sender_id' => 'CELL12', 'usable' => true],
        ['provider' => 'nalo', 'connection_id' => 'conn-nat-nalo', 'owner_group_id' => 'g-nat',
         'via' => 'granted', 'scope_mode' => 'self_and_descendants', 'sender_id' => 'WBS-NALO', 'usable' => true],
    ],
], 'en');
chk('en lang/dir', str_contains($h, 'lang="en"') && str_contains($h, 'dir="ltr"'));
chk('heading + fail-closed framing in the sub', str_contains($h, 'Group notification credentials')
    && str_contains($h, 'did not provide and was not granted'));
chk('the chain lists both hops in order', strpos($h, 'mnotify') < strpos($h, 'nalo'));
chk("an own account is labelled as the group's own", str_contains($h, '>Own account<'));
chk("a shared account is labelled as somebody else's", str_contains($h, '>Shared with us<')
    && str_contains($h, 'National'));
chk('the share that reaches it is named', str_contains($h, 'That group and all its subgroups'));
chk('sender identities are shown', str_contains($h, 'CELL12') && str_contains($h, 'WBS-NALO'));
chk('a ready hop reads ready', str_contains($h, '>Ready<'));
chk('the managed account offers a write-only secret form',
    str_contains($h, 'action="/notifications/credentials/connections/conn-cell/secrets"')
    && str_contains($h, 'type="password" name="secret"'));
chk('nalo slots are offered for a nalo adapter', str_contains($h, 'auth_key') || str_contains($h, 'auth key'));
chk('shares are listed with their reach', str_contains($h, 'Includes cross-cut groups')
    && str_contains($h, 'Selected groups only'));
chk('a hand-picked share lists its groups by name', str_contains($h, 'Cell 12, National'));
chk('each active share can be revoked', substr_count($h, '/revoke"') === 2);
chk('a draft account cannot be shared yet', str_contains($h, 'Sharing becomes available once the account is active'));
chk('and the lifecycle stays on the Integrations page', str_contains($h, 'href="/integrations/connections"'));
chk('the add-account form names the sms adapters', str_contains($h, 'mnotify_sms_v1') && str_contains($h, 'nalo_sms_v1'));
chk('a declared provider order is shown on the account', str_contains($h, 'Preferred order')
    && str_contains($h, 'mnotify,nalo'));
chk('and can be set when the account is created', str_contains($h, 'name="provider_order"')
    && str_contains($h, 'which provider this body tries first'));
chk('group pickers are indented by depth', str_contains($h, '— — — Cell 12'));

// (b) Fail closed: a group with no credential at all.
$h = $render($base + ['connections' => [], 'grants' => [], 'selected' => 'g-cell', 'plan' => []], 'en');
chk('an unprovisioned group shows the refusal panel', str_contains($h, 'This group has no SMS credential.'));
chk('and says messages are refused, not borrowed',
    str_contains($h, 'refused, not sent on another body') && ! str_contains(strtolower($h), 'fallback to the platform'));
chk('with no accounts to manage', str_contains($h, 'No provider accounts in your scope yet.'));

// (c) An approved account with no secret stored: skipped, not sent.
$h = $render($base + [
    'connections' => [$cellConn], 'grants' => $grants, 'selected' => 'g-cell',
    'plan' => [['provider' => 'mnotify', 'connection_id' => 'conn-cell', 'owner_group_id' => 'g-cell',
        'via' => 'own', 'scope_mode' => 'self', 'sender_id' => 'CELL12', 'usable' => false]],
], 'en');
chk('a credential with no secret is flagged', str_contains($h, 'No secret'));
chk('and the page explains it is skipped', str_contains($h, 'it is skipped and the next hop is tried'));

// (d) Arabic: RTL, translated copy and vocabulary.
$h = $render($base + [
    'connections' => [$cellConn], 'grants' => $grants, 'selected' => 'g-cell',
    'plan' => [['provider' => 'mnotify', 'connection_id' => 'conn-cell', 'owner_group_id' => 'g-cell',
        'via' => 'granted', 'scope_mode' => 'groups', 'sender_id' => 'CELL12', 'usable' => true]],
], 'ar');
chk('ar lang=ar dir=rtl', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('ar heading translated', str_contains($h, 'بيانات اعتماد الإشعارات للمجموعة'));
chk('ar scope vocabulary translated', str_contains($h, 'المجموعات المحدَّدة فقط'));
chk('ar keeps server data verbatim', str_contains($h, 'mnotify') && str_contains($h, 'CELL12'));
$h = $render($base + ['connections' => [], 'grants' => [], 'selected' => 'g-cell', 'plan' => []], 'ar');
chk('ar fail-closed copy translated', str_contains($h, 'لا تملك هذه المجموعة بيانات اعتماد')
    && str_contains($h, 'تُرفَض رسائلها'));
chk('ar never hardcodes a direction', ! str_contains($h, 'dir="ltr"'));

// (e) French + Chinese spot checks, and an unknown vocabulary value.
$h = $render($base + ['connections' => [], 'grants' => [], 'selected' => 'g-nat', 'plan' => []], 'fr');
chk('fr heading translated', str_contains($h, 'Identifiants de notification du groupe'));
chk('fr refusal panel translated', str_contains($h, 'Ce groupe n’a aucun identifiant SMS.'));
$h = $render($base + [
    'connections' => [array_merge($cellConn, ['status' => 'weird_state'])], 'grants' => [], 'selected' => 'g-cell', 'plan' => [],
], 'en');
chk('an unknown status falls back to a humanized raw token', str_contains($h, 'Weird State'),
    substr($h, (int) strpos($h, 'Weird'), 20));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
