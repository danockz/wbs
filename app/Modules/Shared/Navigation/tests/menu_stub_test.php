<?php
declare(strict_types=1);
require 'app/Modules/Shared/Navigation/PermissionBits.php';
require 'app/Modules/Shared/Navigation/MenuCategory.php';
require 'app/Modules/Shared/Navigation/MenuItem.php';
require 'app/Modules/Shared/Navigation/CoreMenuProvider.php';
require 'app/Modules/Shared/Navigation/MenuCatalog.php';
require 'app/Modules/Shared/Navigation/MenuService.php';

use WBS\Shared\Navigation\{PermissionBits, MenuItem, MenuCatalog, MenuService, MenuCategory, CoreMenuProvider};

$p=0;$f=0; function chk($n,$c){global $p,$f;echo($c?"PASS":"FAIL")." $n\n";$c?$p++:$f++;}

// ---- 1. Frozen bit-map invariants ----
chk('P == 41 (fits one uint64)', PermissionBits::count()===41);
chk('selfCheck: no dup/out-of-range bits', PermissionBits::selfCheck()===[]);
chk('all bits <= MAX_BIT(62)', max(array_values(PermissionBits::all()))<=PermissionBits::MAX_BIT);
chk('unknown code -> UNSATISFIABLE mask', PermissionBits::mask('nope.zz')===PermissionBits::UNSATISFIABLE);
chk('known mask is single bit', PermissionBits::mask('event.create')===(1<<16));

// ---- 2. Item mask precomputation + visibility primitive ----
$item = new MenuItem('x', MenuCategory::EVENTS, 'X', 'events/create', ['event.create'], 'any');
$word_yes = (1<<16); $word_no = (1<<5);
chk('visibleTo true when bit present', $item->visibleTo($word_yes));
chk('visibleTo false when bit absent', !$item->visibleTo($word_no));
$always = new MenuItem('y', MenuCategory::OVERVIEW, 'Y', 'me');
chk('no-permission item always visible (mask 0)', $always->requiredMask===0 && $always->visibleTo(0));
$typo = new MenuItem('z', MenuCategory::EVENTS, 'Z', 'e', ['bogus.code']);
chk('typo permission -> fail closed vs real max word', !$typo->visibleTo(PermissionBits::allKnownMask()));

// ---- 3. Catalog frozen structure + render projection ----
$cat = MenuCatalog::shared();
$svc = new MenuService($cat, fn($o,$s,$g)=>0); // provider unused here; we call render() directly

// member: no permission bits at all -> sees overview + any PUBLIC (no-permission) items only
$memberWord = 0;
$m = $svc->render($memberWord);
$catsSeen = array_column($m['categories'],'key');
chk('member sees overview', in_array('overview',$catsSeen));
chk('member sees NO privileged categories (access/admin/people-mgmt)', !in_array('access',$catsSeen)&&!in_array('admin',$catsSeen));
// every item a member sees must be permission-free (public)
$leak=[]; foreach($m['categories'] as $c){foreach($c['items'] as $it){ foreach(CoreMenuProvider::items() as $src){ if($src->id===$it['id'] && $src->requiredMask!==0) $leak[]=$it['id']; }}}
chk('member sees ONLY permission-free items (no privileged leak)', $leak===[]);

// leader: group.create + event.create + report.view
$leaderWord = PermissionBits::mask('group.create')|PermissionBits::mask('event.create')|PermissionBits::mask('report.view');
$lm = $svc->render($leaderWord);
$lcats = array_column($lm['categories'],'key');
chk('leader gains groups/events/reports', in_array('groups',$lcats)&&in_array('events',$lcats)&&in_array('reports',$lcats));
chk('leader does NOT see access/admin', !in_array('access',$lcats)&&!in_array('admin',$lcats));

// admin: every known permission bit
$adminWord = PermissionBits::allKnownMask();
$am = $svc->render($adminWord);
$acats = array_column($am['categories'],'key');
chk('admin sees access + admin categories', in_array('access',$acats)&&in_array('admin',$acats));
chk('admin count >= member count', $am['count'] > $m['count']);
chk('categories emitted in fixed display order', $acats===array_values(array_intersect(MenuCategory::order(),$acats)));

// ---- 4. Anti-drift: every item's permissions map to KNOWN bits ----
$driftErrors=[];
foreach (CoreMenuProvider::items() as $it) {
    foreach ($it->permissions as $code) {
        if (PermissionBits::bit($code)===null) $driftErrors[]="{$it->id}:{$code}";
    }
}
chk('anti-drift: all catalog permissions are known codes', $driftErrors===[]);

// ---- 5. Tier cascade: cache read vs cold compute ----
$store=[]; $coldCalls=0;
$cacheGet=function($k) use (&$store){ return $store[$k]??null; };
$cacheSet=function($k,$v) use (&$store){ $store[$k]=$v; };
$provider=function($o,$s,$g) use (&$coldCalls,$leaderWord){ $coldCalls++; return $leaderWord; };
$svc2=new MenuService($cat,$provider,$cacheGet,$cacheSet);

$w1=$svc2->capabilityWord('org','u1',null,7);   // Tier 2 cold
$w2=$svc2->capabilityWord('org','u1',null,7);   // Tier 1 warm
$w3=$svc2->capabilityWord('org','u1',null,7);   // Tier 1 warm
chk('cold compute happened exactly once across 3 reads', $coldCalls===1);
chk('warm reads return same word', $w1===$w2 && $w2===$w3 && $w1===$leaderWord);

// version bump invalidates (new key) -> one more cold compute
$svc2->capabilityWord('org','u1',null,8);
chk('version bump forces one recompute (INCR invalidation)', $coldCalls===2);

// ETag / Tier 0 (instance method folds in the live catalog fingerprint)
$e7=$svc->etag(7,null); $e8=$svc->etag(8,null); $e7scope=$svc->etag(7,'grpA');
chk('etag changes with version (grant/config update)', $e7!==$e8);
chk('etag changes with scope (scope switch)', $e7!==$e7scope);
chk('etag stable for same version+scope+catalog (Tier 0 hit)', $e7===$svc->etag(7,null));

// ---- 6. Update propagation: STRUCTURE change (deploy) invalidates fleet-wide ----
$cv = $cat->catalogVersion();
chk('catalog fingerprint is non-empty + stable', $cv!=='' && $cv===$cat->catalogVersion());
// Simulate a deploy that adds/relabels an item -> different catalog -> different fingerprint & etag.
$extra = array_merge(CoreMenuProvider::items(), [ new MenuItem('new.item', MenuCategory::REPORTS, 'Brand New', 'reports/new', ['report.view']) ]);
$cat2 = new MenuCatalog($extra);
chk('structure change -> different catalog fingerprint', $cat2->catalogVersion()!==$cv);
$svcNew = new MenuService($cat2, fn()=>0);
chk('structure change -> different ETag (same version+scope)', $svcNew->etag(7,null)!==$svc->etag(7,null));
// A pure relabel (no permission change) must ALSO move the fingerprint.
$relabel = CoreMenuProvider::items();
$relabel[0] = new MenuItem($relabel[0]->id, $relabel[0]->category, 'RENAMED', $relabel[0]->route);
$catRe = new MenuCatalog($relabel);
chk('relabel-only change still invalidates (fingerprint moves)', $catRe->catalogVersion()!==$cv);
// Rebuilding the SAME items yields the SAME fingerprint (nodes agree across the fleet).
$catSame = new MenuCatalog(CoreMenuProvider::items());
chk('identical catalog on another node -> identical fingerprint (fleet-consistent)', $catSame->catalogVersion()===$cv);

// ---- 7. Update propagation: word re-keys when structure changes (new bit need) ----
$store2=[]; $cold2=0;
$svcK=new MenuService($cat, function() use(&$cold2){$cold2++; return PermissionBits::mask('report.view');},
    fn($k)=>$store2[$k]??null, function($k,$v)use(&$store2){$store2[$k]=$v;});
$svcK->capabilityWord('o','u',null,7);            // cold once for catalog A
$svcK2=new MenuService($cat2, function() use(&$cold2){$cold2++; return PermissionBits::mask('report.view');},
    fn($k)=>$store2[$k]??null, function($k,$v)use(&$store2){$store2[$k]=$v;});
$svcK2->capabilityWord('o','u',null,7);           // catalog B -> different key -> cold again
chk('catalog change re-keys the cached word (recompute once)', $cold2===2);

echo "\n== $p passed, $f failed ==\n";
exit($f>0?1:0);
