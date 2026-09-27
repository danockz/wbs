<?php

declare(strict_types=1);

/**
 * Prospect-transfer maker-checker QUEUE — view, CSRF, route, menu and i18n
 * coverage (FR-REF-7 review path).
 *
 * The service contract is pinned by
 * Services/tests/prospect_transfer_review_test.php; this file guards the human
 * surface around it, in the same shape as the two sponsor-reassignment view
 * tests it mirrors:
 *
 *  1. every actionable form in transfer_index.php carries the double-submit
 *     _csrf token, and every POST route is `webcsrf`-guarded (the exact hole the
 *     reassign CSRF test was written for);
 *  2. the checker routes keep the `sponsor.reassign.approve` gate while the read
 *     routes stay plain GETs;
 *  3. the controller mints the token + cookie, renders the bespoke page for
 *     browsers (never raw JSON), PRGs after a mutation, and scopes the maker's
 *     contact picker to the actor's OWN book;
 *  4. the queue is discoverable: a PEOPLE MenuItem + a `menuItems.people_transfers`
 *     label in all six locales (the menu anti-drift rule);
 *  5. `transfer.*` / `transferShow.*` leaves mirror English in all six locales,
 *     and both views are self-contained (own <html>, _locale.php, dynamic
 *     lang/dir) with localized fixed vocabularies, raw-value fallbacks, verbatim
 *     ids and RTL support.
 *
 *   php app/Modules/Referrals/Views/tests/transfer_queue_views_test.php
 */

$root       = dirname(__DIR__, 5);
$viewDir    = $root . '/app/Modules/Referrals/Views';
$langDir    = $root . '/app/Modules/Referrals/Language';
$controller = $root . '/app/Modules/Referrals/Controllers/ProspectTransferController.php';
$routesFile = $root . '/app/Config/Routes.php';
$menuFile   = $root . '/app/Modules/Shared/Navigation/CoreMenuProvider.php';

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

// ── 1. The dashboard's forms carry the double-submit token ───────────────────
echo "transfer_index.php forms carry the double-submit token\n";
$v = (string) file_get_contents("$viewDir/transfer_index.php");
chk('approve form present', str_contains($v, '/approve"'));
chk('reject form present', str_contains($v, '/reject"'));
chk('cancel form present', str_contains($v, '/cancel"'));
chk('submit (new proposal) form present', str_contains($v, 'action="/referrals/prospect-transfers"'));
chk('every form has a hidden _csrf field', substr_count($v, 'name="_csrf"') >= 4,
    'found ' . substr_count($v, 'name="_csrf"'));
chk('tokens bound to $csrf', str_contains($v, 'value="<?= esc($csrf'));
chk('forms POST, never GET', substr_count($v, 'method="post"') >= 4 && ! str_contains($v, 'method="get"'));
chk('detail links point at the show route', str_contains($v, '/referrals/prospect-transfers/<?= esc($id'));

echo "transfer_show.php is read-only (no mutation forms)\n";
$vs = (string) file_get_contents("$viewDir/transfer_show.php");
chk('no <form> on the detail page', ! str_contains($vs, '<form'));
chk('but it links back to the queue', str_contains($vs, 'href="/referrals/prospect-transfers/pending"'));

// ── 2. Routes: writes are webcsrf-guarded, reads are plain GETs ──────────────
echo "routes\n";
$r = (string) file_get_contents($routesFile);
$routes = [
    'submit'  => ["post('prospect-transfers'", ['webcsrf', 'auth']],
    'approve' => ["post('prospect-transfers/(:segment)/approve'", ['webcsrf', 'auth', 'authorize:sponsor.reassign.approve']],
    'reject'  => ["post('prospect-transfers/(:segment)/reject'", ['webcsrf', 'auth', 'authorize:sponsor.reassign.approve']],
    'cancel'  => ["post('prospect-transfers/(:segment)/cancel'", ['webcsrf', 'auth']],
];
foreach ($routes as $handler => [$needle, $filters]) {
    $at = strpos($r, $needle);
    if ($at === false) {
        chk("$handler POST route present", false);
        continue;
    }
    $line = substr($r, $at, (int) strpos(substr($r, $at), "\n"));
    chk("$handler POST route present", true);
    chk("$handler points at ProspectTransferController", str_contains($line, 'ProspectTransferController'));
    foreach ($filters as $f) {
        chk("$handler is $f-guarded", str_contains($line, $f));
    }
}
// There is deliberately NO expiry route: a request stays pending until a human
// decides it (the approve-time re-check is the only staleness guard).
chk('no expire route exists', ! str_contains($r, 'prospect-transfers/expire'));
chk('and neither view knows an expiry', ! str_contains(strtolower($v . $vs), 'expire'));

$mp = strpos($r, "get('prospect-transfers/pending'");
chk('pending is a GET read', $mp !== false);
$mpLine = $mp === false ? '' : substr($r, $mp, (int) strpos(substr($r, $mp), "\n"));
chk('pending is checker-gated', str_contains($mpLine, 'authorize:sponsor.reassign.approve'));
chk('pending has no webcsrf (it is a read)', ! str_contains($mpLine, 'webcsrf'));
$ms = strpos($r, "get('prospect-transfers/(:segment)'");
chk('show is a GET read', $ms !== false);
$msLine = $ms === false ? '' : substr($r, $ms, (int) strpos(substr($r, $ms), "\n"));
chk('show is auth-gated', str_contains($msLine, "'auth'"));
chk('show has no webcsrf', ! str_contains($msLine, 'webcsrf'));
chk('no new permission bit was spent (reuses the frozen sponsor.reassign.approve)',
    ! str_contains($r, 'prospect.transfer.approve') && substr_count($r, 'sponsor.reassign.approve') >= 5);

// ── 3. Controller wiring ─────────────────────────────────────────────────────
echo "controller\n";
$ctrl = (string) file_get_contents($controller);
chk('mints a csrf token', str_contains($ctrl, 'issueCsrf()'));
chk('sets the wbs_csrf cookie', str_contains($ctrl, "'wbs_csrf'"));
chk('renders transfer_index for browsers', str_contains($ctrl, 'transfer_index'));
chk('renders transfer_show for the detail page', str_contains($ctrl, 'transfer_show'));
chk('passes csrf to the view', str_contains($ctrl, "'csrf'"));
chk('PRGs browsers after a mutation', str_contains($ctrl, 'redirect()->to(self::DASHBOARD)'));
chk('the dashboard is the queue', str_contains($ctrl, "'/referrals/prospect-transfers/pending'"));
chk('still returns JSON for API clients', str_contains($ctrl, 'if ($this->wantsJson())'));
chk('never renders raw JSON to a browser', str_contains($ctrl, 'no-store') && str_contains($ctrl, 'text/html'));
chk("the maker's contact picker is scoped to their OWN book",
    str_contains($ctrl, 'listForOwner($this->orgId(), $actorId)'), 'must not offer the whole org');
chk('the mentor picker uses the org roster', str_contains($ctrl, 'listMembers($this->orgId()'));
chk('delegates to the review service, not to apply()',
    str_contains($ctrl, 'prospectTransferReviews()') && ! str_contains($ctrl, 'prospectTransfers()->apply'));
chk('every handler is org-scoped', substr_count($ctrl, '$this->orgId()') >= 7);
chk('approve/reject/cancel pass the actor for SoD', substr_count($ctrl, '$this->actorId()') >= 5);

// ── 3b. Leadership scope: BOTH review queues are subtree-scoped, identically ──
echo "leadership scope (both queues)\n";
$reassignCtrl = $root . '/app/Modules/Referrals/Controllers/SponsorReassignmentController.php';
$rc = (string) file_get_contents($reassignCtrl);

// The transfer queue: a checker sees/acts on requests touching their subtree.
chk('transfer queue filters through the PDP scope check',
    str_contains($ctrl, "canManageGroupScope('sponsor.reassign.approve'"));
chk('the queue is filtered BEFORE content negotiation (JSON cannot widen it)',
    strpos($ctrl, '$this->scopeFilter($requests)') !== false
    && strpos($ctrl, '$this->scopeFilter($requests)') < strpos($ctrl, 'wantsJson()'));
chk('EITHER side of the move being in scope is enough',
    str_contains($ctrl, '$request[\'from_group_id\'] ?? null') && str_contains($ctrl, '$request[\'to_group_id\'] ?? null'));
chk('an out-of-scope detail page is a 404, not a 403',
    str_contains($ctrl, '! $this->inScope($req)') && str_contains($ctrl, '$req = null;'));
chk('approve/reject/cancel each pre-flight the scope',
    substr_count($ctrl, '$this->outOfScope($requestId)') === 3);
chk('scope answers are memoised per group (one PDP call per group, not per row)',
    str_contains($ctrl, 'private array $scopeCache = [];') && str_contains($ctrl, '$this->scopeCache[$gid]'));

// The sponsor-reassignment queue: the same rule, keyed on the member's group.
chk('reassignment queue filters through the SAME PDP scope check',
    str_contains($rc, "canManageGroupScope('sponsor.reassign.approve'"));
chk('it resolves the member\'s own group for the scope test',
    str_contains($rc, 'primaryMembershipGroup($this->orgId(), $memberId)')
    && str_contains($rc, 'SharedServices::groupScope()'));
chk('its queue is filtered before content negotiation too',
    strpos($rc, '$this->scopeFilter($requests)') !== false
    && strpos($rc, '$this->scopeFilter($requests)') < strpos($rc, 'wantsJson()'));
chk('its detail page 404s when the member is out of scope',
    str_contains($rc, '! $this->inScope($req)') && str_contains($rc, '$req = null;'));
chk('its approve/reject/cancel each pre-flight the scope',
    substr_count($rc, '$this->outOfScope($requestId)') === 3);
chk('and its scope answers are memoised per member',
    str_contains($rc, 'private array $scopeCache = [];') && str_contains($rc, '$this->scopeCache[$memberId]'));
chk('neither queue invented a second scope implementation',
    ! str_contains($ctrl, 'group_closure') && ! str_contains($rc, 'group_closure'));

// ── 4. Discoverability: menu item + localized label ──────────────────────────
echo "menu\n";
$menu = (string) file_get_contents($menuFile);
chk('a PEOPLE menu item exists for the queue', str_contains($menu, "'people.transfers'"));
chk('it points at the pending queue', str_contains($menu, 'referrals/prospect-transfers/pending'));
$at = strpos($menu, "'people.transfers'");
$itemLine = $at === false ? '' : substr($menu, $at, (int) strpos(substr($menu, $at), "\n"));
chk('it is gated by sponsor.reassign.approve', str_contains($itemLine, "permissions: ['sponsor.reassign.approve']"));
chk('it sits in the PEOPLE section', str_contains($itemLine, '$C::PEOPLE'));
chk('it has an icon and an order', str_contains($itemLine, "icon: '") && str_contains($itemLine, 'order:'));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $app = require $root . "/app/Language/$loc/App.php";
    chk("$loc has a menuItems.people_transfers label",
        isset($app['menuItems']['people_transfers']) && trim((string) $app['menuItems']['people_transfers']) !== '',
        json_encode($app['menuItems']['people_transfers'] ?? null));
}

// ── 5. i18n parity for both new blocks ──────────────────────────────────────
echo "language parity for transfer + transferShow blocks\n";
$en = require $langDir . '/en/Referrals.php';
foreach (['transfer', 'transferShow'] as $block) {
    chk("en has $block block", isset($en[$block]) && is_array($en[$block]));
    $enLeaves = $flatten([$block => $en[$block] ?? []]);
    sort($enLeaves);
    foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $l      = require $langDir . "/$loc/Referrals.php";
        $leaves = $flatten([$block => $l[$block] ?? []]);
        sort($leaves);
        $diff = array_merge(array_diff($enLeaves, $leaves), array_diff($leaves, $enLeaves));
        chk("$loc mirrors en $block leaves (" . count($leaves) . ')', $diff === [], implode(',', $diff));
    }
}
chk('the queue copy keeps the {0}/{1} placeholders',
    substr_count((string) $en['transfer']['inactivity'], '{0}') === 1
    && substr_count((string) $en['transfer']['inactivity'], '{1}') === 1);

echo "static checks\n";
foreach (['transfer_index.php' => 'transfer', 'transfer_show.php' => 'transferShow'] as $file => $block) {
    $src = (string) file_get_contents("$viewDir/$file");
    chk("$file calls lang('Referrals.$block.", str_contains($src, "lang('Referrals.$block."));
    chk("$file has no hardcoded lang=\"en\"", ! str_contains($src, 'lang="en"'));
    chk("$file includes _locale.php", str_contains($src, '_locale.php'));
    chk("$file emits a dynamic <html lang dir>",
        str_contains($src, '_shell_open.php'));
    // Every short-echo must either run through esc() or be one of a tiny
    // allowlist of self-escaping emissions: the picker closures (which build
    // pre-escaped HTML themselves) and a literal colour ternary. Anything else —
    // a raw row field, an unescaped id — fails here.
    // A branch is safe when it emits only quoted literals and/or esc() calls
    // (concatenated) — so a colour ternary or an "esc() else ''" ternary passes,
    // while a bare `$row['field']` never does.
    $branchSafe = static function (string $b): bool {
        $b = trim($b);
        if ($b === '' || $b === "''") {
            return true;
        }
        foreach (preg_split('/\s*\.\s*/', $b) ?: [] as $part) {
            $part = trim($part);
            $literal = preg_match("/^'[^']*'$/", $part) === 1 || preg_match('/^"[^"]*"$/', $part) === 1;
            if (! $literal && ! str_starts_with($part, 'esc(')) {
                return false;
            }
        }

        return true;
    };
    preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $src, $echos);
    $unsafe = [];
    foreach (array_unique($echos[1]) as $body) {
        $safe = str_starts_with($body, 'esc(')
            || str_starts_with($body, '$personSelect(')
            || str_starts_with($body, '$contactSelect(');
        if (! $safe && str_contains($body, ' ? ') && str_contains($body, ' : ')) {
            // Ternary: both emitted branches must be literals/esc() calls.
            [$cond, $rest] = explode(' ? ', $body, 2);
            $branches = explode(' : ', $rest, 2);
            $safe = count($branches) === 2
                && $branchSafe($branches[0]) && $branchSafe($branches[1])
                && $cond !== '';
        }
        if (! $safe) {
            $unsafe[] = $body;
        }
    }
    chk("$file: every echo is escaped or allowlisted (" . count($echos[1]) . ' echoes)', $unsafe === [],
        json_encode(array_slice($unsafe, 0, 4)));
}

// ── 6. Render smoke ─────────────────────────────────────────────────────────
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
                return $GLOBALS['__refLoc'] ?? 'en';
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

$pending = [
    'id' => 'req-1', 'organization_id' => 'org-1', 'prospect_id' => 'P-1', 'linked_user_id' => 'U-1',
    'from_group_id' => 'G-OLD', 'to_group_id' => 'G-NEW', 'from_owner_user_id' => 'M-OLD', 'to_owner_user_id' => 'M-NEW',
    'requested_by' => 'M-NEW', 'approver_id' => 'LEAD-1', 'reason' => 'Quiet since June',
    'trigger_type' => 'event_invite', 'trigger_id' => 'evt-9', 'threshold_weeks' => 8, 'days_inactive' => 95,
    'status' => 'pending', 'eligibility_state' => 'ok', 'eligibility_detail' => null,
    'evaluation' => json_encode(['due' => true, 'reason' => 'inactive_threshold_met', 'threshold_weeks' => 8, 'days_inactive' => 95]),
    'created_at' => '2026-09-20 12:00:00.000000',
];
$blocked = ['id' => 'req-2', 'prospect_id' => 'P-2', 'from_group_id' => 'G-OLD', 'to_group_id' => 'G-NEW',
    'from_owner_user_id' => 'M-OLD', 'to_owner_user_id' => 'M-NEW', 'requested_by' => 'STAFF-1',
    'reason' => 'She asked to move', 'status' => 'pending', 'eligibility_state' => 'blocked',
    'eligibility_detail' => 'still_active', 'threshold_weeks' => 8, 'days_inactive' => 2];
$decided = ['id' => 'req-3', 'prospect_id' => 'P-3', 'linked_user_id' => 'U-3', 'from_group_id' => 'G-OLD',
    'to_group_id' => 'G-NEW', 'from_owner_user_id' => 'M-OLD', 'to_owner_user_id' => 'M-NEW',
    'requested_by' => 'M-NEW', 'approver_id' => 'LEAD-1', 'reason' => 'Quiet since June',
    'trigger_type' => 'event_invite', 'trigger_id' => 'evt-9', 'status' => 'approved', 'eligibility_state' => 'ok',
    'threshold_weeks' => 8, 'days_inactive' => 120, 'decided_by' => 'LEAD-1', 'decided_at' => '2026-09-21 09:00:00',
    'transfer_id' => 'TR-1'];

// fr — the queue with a pending, a blocked and a decided request.
$h = $render("$viewDir/transfer_index.php", [
    'requests' => [$pending, $blocked, $decided],
    'contacts' => [['id' => 'P-9', 'full_name' => 'Esi Owusu']],
    'roster'   => [['id' => 'M-NEW', 'display_name' => 'Kofi Mensah'], ['id' => 'LEAD-1', 'display_name' => 'Ama Leader']],
    'csrf'     => 'TOK-123',
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Transferts de prospects'));
chk('fr sub explains maker–checker', str_contains($h, 'Revue à quatre yeux'));
chk('fr status vocab (pending/approved)', str_contains($h, 'En attente') && str_contains($h, 'Approuvé'));
chk('fr elig vocab (eligible + blocked)', str_contains($h, 'Éligible') && str_contains($h, 'Bloqué'));
chk('fr inactivity chip interpolated', str_contains($h, '95 jours sans contact · seuil de 8 semaines'),
    substr($h, (int) strpos($h, 'jours sans contact') - 20, 80));
chk('fr ids verbatim', str_contains($h, 'P-1') && str_contains($h, 'G-OLD') && str_contains($h, 'G-NEW')
    && str_contains($h, 'M-OLD') && str_contains($h, 'M-NEW'));
chk('fr block detail surfaced', str_contains($h, 'still_active'));
chk('fr action buttons translated', str_contains($h, '>Approuver<') && str_contains($h, '>Rejeter<')
    && str_contains($h, '>Annuler<'));
chk('approve/reject/cancel post to the right urls',
    str_contains($h, 'action="/referrals/prospect-transfers/req-1/approve"')
    && str_contains($h, 'action="/referrals/prospect-transfers/req-1/reject"')
    && str_contains($h, 'action="/referrals/prospect-transfers/req-1/cancel"'));
chk('the csrf token is in every form', substr_count($h, 'value="TOK-123"') >= 4, (string) substr_count($h, 'value="TOK-123"'));
chk('a DECIDED request shows no action forms', ! str_contains($h, 'req-3/approve'));
chk('fr SoD note shown', str_contains($h, 'Vous ne pouvez pas approuver votre propre demande.'));
chk("fr maker form offers the contact by NAME", str_contains($h, '>Esi Owusu<') && str_contains($h, 'value="P-9"'));
chk('fr maker form offers mentors from the roster', str_contains($h, '>Kofi Mensah<') && str_contains($h, 'value="M-NEW"'));
chk('the maker form posts to the submit route', str_contains($h, 'action="/referrals/prospect-transfers"')
    && str_contains($h, 'name="prospect_id"') && str_contains($h, 'name="to_owner_user_id"')
    && str_contains($h, 'name="reason"') && str_contains($h, 'name="trigger_type"'));
chk('detail links rendered', str_contains($h, 'href="/referrals/prospect-transfers/req-1"'));

// ar — RTL.
$h = $render("$viewDir/transfer_index.php", ['requests' => [$pending], 'contacts' => [], 'roster' => [], 'csrf' => 'T'], 'ar');
chk('ar dir=rtl', str_contains($h, 'dir="rtl"') && str_contains($h, 'lang="ar"'));
chk('ar heading translated', str_contains($h, 'نقل المحتملين'));
chk('ar SoD note translated', str_contains($h, 'لا يمكنك الموافقة'));
chk('ar empty pickers degrade to text inputs', str_contains($h, '<input id="f-contact" type="text" name="prospect_id"'));

// en — empty queue.
$h = $render("$viewDir/transfer_index.php", ['requests' => [], 'contacts' => [], 'roster' => [], 'csrf' => 'T'], 'en');
chk('en empty state shown', str_contains($h, 'No pending transfers awaiting your review.'));
chk('en empty state renders no action forms', ! str_contains($h, '/approve"'));

// show page — full approved request with trail + decision.
$h = $render("$viewDir/transfer_show.php", [
    'requestId' => 'req-3',
    'request'   => array_merge($decided, [
        'evaluation' => json_encode(['due' => true, 'reason' => 'inactive_threshold_met', 'threshold_weeks' => 8, 'days_inactive' => 120]),
        'reviews'    => [
            ['id' => 'rv-1', 'action' => 'submit', 'actor_id' => 'M-NEW', 'note' => 'Quiet since June', 'created_at' => '2026-09-20 12:00:00.000000'],
            ['id' => 'rv-2', 'action' => 'block', 'actor_id' => 'LEAD-1', 'note' => 'still_active', 'created_at' => '2026-09-20 13:00:00.000000'],
            ['id' => 'rv-3', 'action' => 'approve', 'actor_id' => 'LEAD-1', 'note' => 'Verified by phone', 'created_at' => '2026-09-21 09:00:00.000000'],
        ],
    ]),
], 'en');
chk('show: heading + id', str_contains($h, 'Prospect transfer') && str_contains($h, 'req-3'));
chk('show: status vocab', str_contains($h, '>Approved<'));
chk('show: inactivity chip', str_contains($h, '120 days quiet · 8-week policy'));
chk('show: both sides of the move', str_contains($h, 'G-OLD') && str_contains($h, 'G-NEW')
    && str_contains($h, 'M-OLD') && str_contains($h, 'M-NEW'));
chk('show: trigger', str_contains($h, 'event_invite · evt-9') || str_contains($h, 'event_invite'));
chk('show: verdict localized', str_contains($h, 'Inactivity threshold met'));
chk('show: due-now flag', str_contains($h, '>Yes<'));
chk('show: review trail rows', str_contains($h, '>Submitted<') && str_contains($h, '>Blocked<') && str_contains($h, '>Approved<'));
chk('show: trail keeps actor + note verbatim', str_contains($h, 'LEAD-1') && str_contains($h, 'Verified by phone'));
chk('show: decision block with the transfer row', str_contains($h, '>Decision<') && str_contains($h, 'TR-1'));
chk('show: no mutation forms', ! str_contains(preg_replace('/<form class="lang__menu".*?<\/form>/s', '', $h), '<form'));

// show page — pending + blocked, unknown vocab values, no decision yet.
$h = $render("$viewDir/transfer_show.php", [
    'requestId' => 'req-2',
    'request'   => array_merge($blocked, ['status' => 'weird_status', 'eligibility_detail' => 'brand_new_verdict', 'reviews' => []]),
], 'en');
chk('show: unknown status falls back to a humanized raw token', str_contains($h, 'Weird status'));
chk('show: unknown verdict falls back, humanized', str_contains($h, 'Brand new verdict'));
chk('show: blocked chip', str_contains($h, '>Blocked<'));
chk('show: empty trail message', str_contains($h, 'No review entries yet.'));
chk('show: no decision block when undecided', ! str_contains($h, '>Decision<'));

// show page — a legacy row that still carries expires_at must not resurface it,
// and not-found (en + ar rtl).
$h = $render("$viewDir/transfer_show.php", ['requestId' => 'req-4',
    'request' => array_merge($pending, ['expires_at' => '2026-10-04 12:00:00.000000'])], 'en');
chk('show: a stray expires_at is never rendered', ! str_contains(strtolower($h), 'expires'));
$h = $render("$viewDir/transfer_show.php", ['requestId' => 'missing', 'request' => null], 'en');
chk('show: not-found panel', str_contains($h, 'That transfer request could not be found.') && str_contains($h, 'missing'));
$h = $render("$viewDir/transfer_show.php", ['requestId' => 'm', 'request' => null], 'ar');
chk('show: ar rtl + translated not-found', str_contains($h, 'dir="rtl"') && str_contains($h, 'لم يُعثر على طلب النقل هذا.'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
