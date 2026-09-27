<?php
declare(strict_types=1);
require 'app/Modules/Shared/Navigation/PermissionBits.php';
require 'app/Modules/Shared/Navigation/MenuCategory.php';
require 'app/Modules/Shared/Navigation/MenuItem.php';
require 'app/Modules/Shared/Navigation/CoreMenuProvider.php';
require 'app/Modules/Shared/Navigation/MenuCatalog.php';
require 'app/Modules/Shared/Navigation/MenuService.php';
use WBS\Shared\Navigation\{PermissionBits, MenuCatalog, MenuService};

$cat = MenuCatalog::shared();
$svc = new MenuService($cat, fn()=>0);
$N   = count($cat->items());
echo "Catalog items: $N\n\n";

// --- Tier 1 render throughput (the hot path: AND-fold over the catalog) ---
$word = PermissionBits::allKnownMask(); // worst case: every item visible
$iters = 2_000_000;
$t0 = hrtime(true);
$sink = 0;
$masks = $cat->masks(); $n = count($masks);
for ($k=0;$k<$iters;$k++){
    // inline the core primitive to measure the decision cost itself
    for ($i=0;$i<$n;$i++){ if (($word & $masks[$i])===$masks[$i]) $sink++; }
}
$t1 = hrtime(true);
$ns = ($t1-$t0);
$perRender = $ns/$iters;
$decisions = $iters*$n;
echo sprintf("Core AND-fold: %d renders x %d items = %s decisions\n", $iters, $n, number_format($decisions));
echo sprintf("  %.1f ns per full-menu render, %.3f ns per item-decision\n", $perRender, $ns/$decisions);
echo sprintf("  => ~%s full-menu renders/sec/core (sink=%d)\n\n", number_format(1e9/$perRender,0), $sink);

// --- Full render() incl. bucketing+serialization (Tier 1 real output) ---
$iters2=200_000;
$t0=hrtime(true);
for($k=0;$k<$iters2;$k++){ $out=$svc->render($word); }
$t1=hrtime(true);
$per=($t1-$t0)/$iters2;
echo sprintf("Full render() (bucket+serialize): %.0f ns each => ~%s/sec/core\n\n", $per, number_format(1e9/$per,0));

// --- Cache footprint model for 500k concurrent users ---
// Design: cache value is ONE 8-byte word per (user, distinct profile).
$users = 500_000;
$profilesPerUser = 2; // e.g. "as member" + "as leader-of-branch" typical
$bytesPerEntry_ideal = 8; // the word itself
// Redis realistic overhead per small key (key string + object header) ~ 60-90B; use 80B.
$redisPerEntry = 80;
$keys = $users*$profilesPerUser;
echo "Cache model @ {$users} users x {$profilesPerUser} profiles = ".number_format($keys)." words:\n";
echo sprintf("  ideal payload: %s (8B words)\n", human($keys*$bytesPerEntry_ideal));
echo sprintf("  realistic Redis (~%dB/key incl overhead): %s\n", $redisPerEntry, human($keys*$redisPerEntry));

// Compare: caching a serialized JSON tree per user instead.
$out=$svc->render($word);
$jsonBytes = strlen(json_encode($out));
echo sprintf("\nContrast: caching a rendered JSON tree per user (%d B admin tree):\n", $jsonBytes);
echo sprintf("  %s users x %d profiles x %dB = %s  (%.0fx larger)\n",
  number_format($users), $profilesPerUser, $jsonBytes,
  human($keys*$jsonBytes), ($keys*$jsonBytes)/($keys*$redisPerEntry));

// --- Process-wide catalog is shared (built once) ---
echo sprintf("\nShared catalog resident cost: built ONCE per process, %d items (~%s), shared by ALL requests.\n",
  $N, human( (strlen(serialize($cat->items()))) ));

function human($b){ $u=['B','KB','MB','GB','TB']; $i=0; while($b>=1024&&$i<4){$b/=1024;$i++;} return sprintf('%.2f %s',$b,$u[$i]); }
