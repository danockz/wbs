<?php

declare(strict_types=1);

/**
 * EVENT INVITE-POLICY enforcement test (gap G5).
 *
 * `registration_policy = 'invite'` was declared but never enforced. This test
 * proves the InvitationService eligibility gate (fail-closed) and its wiring into
 * RegistrationService::register(), covering the valid-invite sources the product
 * requires:
 *   - hierarchical-group event → members of that group SUBTREE are eligible;
 *   - a DIRECT invite by user / email / phone (matched to the user's identity);
 *   - the ONE shareable, cloaked per-event LINK — reusable and broadcast (social
 *     media + direct email/SMS), bounded by a configurable mode
 *     (expiry | max_redemptions | capacity);
 *   - staff/leader/follow-up ASSISTED registration (authorized caller vouches).
 * Also: open events are always eligible, closed always blocked, the link honours
 * its mode bounds and manual disable, and the service/factory/routes/i18n are
 * wired — including the guest→prospect capture path.
 *
 *   php app/Modules/Events/Services/tests/event_invite_policy_test.php
 */

namespace CodeIgniter\Database {
    // In-memory fake supporting exactly the query shapes InvitationService uses.
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows) {}
        public function getRowArray() { return $this->rows[0] ?? null; }
        public function getResultArray() { return $this->rows; }
    }

    class QB
    {
        private array $eq = [];
        private array $in = [];
        private bool $orExpiry = false;
        private ?string $now = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($s) { return $this; }

        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if ($escape === false && $k === 'expires_at IS NULL') {
                // handled by the OR group below (no-op predicate here)
                return $this;
            }
            if (str_ends_with($k, '>')) {
                $this->now = (string) $v;
            } else {
                $this->eq[$k] = $v;
            }

            return $this;
        }

        public function whereIn($k, array $v) { $this->in[$k] = $v; return $this; }

        public function groupStart() { $this->orExpiry = true; return $this; }
        public function orWhere($k, $v = null) { $this->now = $v; return $this; }
        public function groupEnd() { return $this; }
        public function orderBy($k, $d = 'ASC', $e = true) { return $this; }
        public function limit($n) { return $this; }

        public function get($limit = null): RS { return new RS($this->rowsFor()); }
        public function countAllResults(): int { return count($this->rowsFor()); }

        public function insert(array $data): bool { $this->db->rows[$this->t][] = $data; return true; }

        public function update(array $data): bool
        {
            foreach ($this->db->rows[$this->t] ?? [] as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $data);
                }
            }

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) { return false; }
            }
            foreach ($this->in as $k => $vs) {
                if (! in_array($r[$k] ?? null, $vs, true)) { return false; }
            }
            if ($this->orExpiry && $this->now !== null) {
                $exp = $r['expires_at'] ?? null;
                if ($exp !== null && (string) $exp <= (string) $this->now) { return false; }
            }

            return true;
        }

        private function rowsFor(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Events/Services/InvitationService.php';

use WBS\Events\Services\InvitationService;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};

Clock::freeze(new DateTimeImmutable('2026-09-14 00:00:00', new DateTimeZone('UTC')));
$clock = new Clock();
$db    = new \CodeIgniter\Database\BaseConnection();
$svc   = new InvitationService($db, $clock);

$openEvent   = ['id' => 'ev-open', 'organization_id' => 'org-1', 'registration_policy' => 'open', 'group_id' => 'grp-1'];
$closedEvent = ['id' => 'ev-cl', 'organization_id' => 'org-1', 'registration_policy' => 'closed', 'group_id' => 'grp-1'];
$invEvent    = ['id' => 'ev-inv', 'organization_id' => 'org-1', 'registration_policy' => 'invite', 'group_id' => 'grp-legon'];

// ── 1. open / closed short-circuits ──────────────────────────────────────────
echo "open / closed policies\n";
chk('open event is always eligible', $svc->eligibility($openEvent, 'u-stranger')->ok);
$cl = $svc->eligibility($closedEvent, 'u-1');
chk('closed event is never eligible', ! $cl->ok && $cl->code === 'REG_CLOSED');

// ── 2. invite-only: stranger rejected (fail-closed) ──────────────────────────
echo "invite-only fail-closed\n";
$r = $svc->eligibility($invEvent, 'u-stranger');
chk('un-invited stranger is REJECTED', ! $r->ok && $r->code === 'INVITE_REQUIRED');
chk('rejection is a 403', $r->status === 403);

// ── 3. assisted registration passes ──────────────────────────────────────────
echo "assisted (staff/leader/follow-up)\n";
$a = $svc->eligibility($invEvent, 'u-stranger', ['assisted' => true]);
chk('assisted caller is eligible', $a->ok && $a->data['reason'] === 'assisted');

// ── 4. group-subtree membership ──────────────────────────────────────────────
echo "group-subtree membership\n";
$db->rows['group_closure'] = [
    ['ancestor_id' => 'grp-legon', 'descendant_id' => 'grp-legon'],
    ['ancestor_id' => 'grp-legon', 'descendant_id' => 'grp-legon-cell'],
];
$db->rows['group_members'] = [
    ['organization_id' => 'org-1', 'user_id' => 'u-member', 'group_id' => 'grp-legon-cell', 'status' => 'active'],
];
$g = $svc->eligibility($invEvent, 'u-member');
chk('member of a DESCENDANT group is eligible', $g->ok && $g->data['reason'] === 'group_member');
chk('non-member is still rejected', ! $svc->eligibility($invEvent, 'u-outsider')->ok);

// ── 5. direct user invite ────────────────────────────────────────────────────
echo "direct invites (user / email / phone)\n";
$iu = $svc->issue('org-1', 'ev-inv', ['channel' => 'user', 'invitee_user_id' => 'u-vip']);
chk('issue user invite ok', $iu->ok);
chk('invited user is eligible', $svc->eligibility($invEvent, 'u-vip')->data['reason'] === 'invited_user');

$db->rows['users'] = [
    ['id' => 'u-mail', 'email' => 'kofi@example.test', 'phone' => ''],
    ['id' => 'u-tel',  'email' => '', 'phone' => '+233201234567'],
];
$svc->issue('org-1', 'ev-inv', ['channel' => 'email', 'invitee_email' => 'Kofi@Example.Test']);
chk('email invite matches the user (case-insensitive)', $svc->eligibility($invEvent, 'u-mail')->data['reason'] === 'invited_email');
$svc->issue('org-1', 'ev-inv', ['channel' => 'phone', 'invitee_phone' => '+233201234567']);
chk('phone invite matches the user', $svc->eligibility($invEvent, 'u-tel')->data['reason'] === 'invited_phone');
chk('resolveInviteeUserId maps email → user id', $svc->resolveInviteeUserId('email', 'kofi@example.test') === 'u-mail');
chk('resolveInviteeUserId returns empty for an unknown contact', $svc->resolveInviteeUserId('email', 'nobody@nowhere.test') === '');

// ── 6. shareable link: reusable, mode-bounded ────────────────────────────────
echo "shareable link (reusable)\n";
$gl = $svc->generateLink('org-1', 'ev-inv', ['mode' => 'expiry'], 'u-sponsor');
chk('generateLink returns a cloaked token + url_token', $gl->ok && str_starts_with((string) $gl->data['token'], 'wbsevt_'));
$token = (string) $gl->data['token'];
chk('first person via link is eligible', $svc->eligibility($invEvent, 'u-anon1', ['invite_token' => $token])->data['reason'] === 'invite_link');
chk('link is REUSABLE — a second person is also eligible', $svc->eligibility($invEvent, 'u-anon2', ['invite_token' => $token])->data['reason'] === 'invite_link');
chk('a wrong token is rejected', ! $svc->eligibility($invEvent, 'u-anon', ['invite_token' => 'wbsevt_bogus'])->ok);
chk('exactly one link row exists per event', count($db->rows['event_invite_links']) === 1);
chk('getLink exposes the plaintext token for re-sharing', (string) ($svc->getLink('org-1', 'ev-inv')['token'] ?? '') === $token);

echo "link mode: max_redemptions\n";
$svc->generateLink('org-1', 'ev-inv', ['mode' => 'max_redemptions', 'max_redemptions' => 2, 'rotate' => true]);
$tok2 = (string) $svc->getLink('org-1', 'ev-inv')['token'];
chk('rotate mints a fresh token', $tok2 !== $token);
chk('within the cap: usable', $svc->eligibility($invEvent, 'u-a', ['invite_token' => $tok2])->ok);
$svc->consumeFor('ev-inv', 'u-a', 'invite_link', ['invite_token' => $tok2]);
$svc->consumeFor('ev-inv', 'u-b', 'invite_link', ['invite_token' => $tok2]);
chk('at the cap: link no longer eligible', ! $svc->eligibility($invEvent, 'u-c', ['invite_token' => $tok2])->ok);
chk('bad max_redemptions is rejected', ! $svc->generateLink('org-1', 'ev-x', ['mode' => 'max_redemptions', 'max_redemptions' => 0])->ok);

echo "link mode: expiry + manual disable\n";
$svc->generateLink('org-1', 'ev-inv', ['mode' => 'expiry', 'expires_at' => '2026-09-13 00:00:00', 'rotate' => true]);
$tok3 = (string) $svc->getLink('org-1', 'ev-inv')['token'];
chk('an expired link is not eligible', ! $svc->eligibility($invEvent, 'u-d', ['invite_token' => $tok3])->ok);
$svc->generateLink('org-1', 'ev-inv', ['mode' => 'expiry', 'expires_at' => '', 'rotate' => true]);
$tok4 = (string) $svc->getLink('org-1', 'ev-inv')['token'];
chk('no-expiry link is eligible', $svc->eligibility($invEvent, 'u-e', ['invite_token' => $tok4])->ok);
$svc->setLinkActive('org-1', 'ev-inv', false);
chk('a manually disabled link is not eligible', ! $svc->eligibility($invEvent, 'u-f', ['invite_token' => $tok4])->ok);
$svc->setLinkActive('org-1', 'ev-inv', true);
chk('re-enabling restores eligibility', $svc->eligibility($invEvent, 'u-g', ['invite_token' => $tok4])->ok);
chk('capacity mode defers to the registrar (link active → eligible)',
    $svc->generateLink('org-1', 'ev-cap', ['mode' => 'capacity'])->ok
    && $svc->eligibility(['id' => 'ev-cap', 'organization_id' => 'org-1', 'registration_policy' => 'invite'], 'u-h',
        ['invite_token' => (string) $svc->getLink('org-1', 'ev-cap')['token']])->ok);

// ── 7. direct-invite revoke ──────────────────────────────────────────────────
echo "direct-invite revoke\n";
$rv = $svc->revoke('org-1', (string) $iu->data['id']);
chk('revoke ok', $rv->ok);
chk('a revoked invite no longer grants eligibility', ! $svc->eligibility($invEvent, 'u-vip')->ok);

// ── 8. RegistrationService wiring (source) ───────────────────────────────────
echo "RegistrationService wiring\n";
$reg = (string) file_get_contents($root . '/app/Modules/Events/Services/RegistrationService.php');
chk('ctor takes an optional InvitationService', (bool) preg_match('/__construct\(.*?\?InvitationService \$invitations = null/s', $reg));
chk('register() gates on invite policy', (bool) preg_match("/registration_policy'\] === 'invite'/", $reg));
chk('register() calls the eligibility gate', str_contains($reg, '->eligibility('));
chk('register() fails closed when unwired + not assisted', (bool) preg_match("/elseif \(\(\\\$opts\['assisted_by'\].*?INVITE_REQUIRED/s", $reg));
chk('register() consumes the winning invite after commit', str_contains($reg, '->consumeFor('));
chk('assisted_by opt drives the assisted signal', str_contains($reg, "\$opts['assisted_by']"));

// ── 9. Factory + routes + controller ─────────────────────────────────────────
echo "factory + routes + controller\n";
$fac = (string) file_get_contents($root . '/app/Modules/Events/Config/Services.php');
chk('factory exposes eventInvitations()', str_contains($fac, 'function eventInvitations('));
chk('factory exposes eventInviteSender() for direct delivery', str_contains($fac, 'function eventInviteSender('));
chk('eventRegistrations() injects the invite gate', (bool) preg_match('/new RegistrationService\(.*?static::eventInvitations\(\)/s', $fac));

$routes = (string) file_get_contents($root . '/app/Config/Routes.php');
chk('GET invitations console route (authorized)', (bool) preg_match('#invitations\'.*?EventController::invitations.*?authorize:event\.create,any#s', $routes));
chk('POST issue invite route (authorized + webcsrf)', (bool) preg_match('#post\(\'\(:segment\)/invitations\'.*?EventController::invite.*?webcsrf#s', $routes));
chk('POST generate-link route', str_contains($routes, 'EventController::generateInviteLink'));
chk('POST toggle-link route', str_contains($routes, 'EventController::toggleInviteLink'));
chk('POST revoke invite route', (bool) preg_match('#invitations/\(:segment\)/revoke.*?EventController::revokeInvite#s', $routes));
chk('POST register-guest route (public, webcsrf + ratelimit)', (bool) preg_match('#register-guest\'.*?EventController::registerGuest.*?webcsrf#s', $routes));

$ctrl = (string) file_get_contents($root . '/app/Modules/Events/Controllers/EventController.php');
chk('controller issues invites', str_contains($ctrl, 'eventInvitations()->issue('));
chk('controller generates the shareable link', str_contains($ctrl, '->generateLink('));
chk('controller delivers direct invites over Notifications', str_contains($ctrl, 'eventInviteSender()->send('));
chk('controller builds the shareable URL with ?invite=', str_contains($ctrl, "'?invite=' . rawurlencode"));
chk('guest sign-up captures a prospect', str_contains($ctrl, 'captureGuestFromInvite('));
chk('guest sign-up counts a link redemption', (bool) preg_match("/registerGuest.*?consumeFor\(.*?'invite_link'/s", $ctrl));

// ── 10. Guest→prospect capture (Referrals) ───────────────────────────────────
echo "guest→prospect capture\n";
$cb = (string) file_get_contents($root . '/app/Modules/Referrals/Services/ContactBookService.php');
chk('captureGuestFromInvite exists', str_contains($cb, 'function captureGuestFromInvite('));
chk('guest becomes a prospect owned by the sponsor', str_contains($cb, "'owner_user_id'       => \$sponsorId"));
chk('guest is registered via the assisted follow-up path', (bool) preg_match('/captureGuestFromInvite.*?recordAttendance\(/s', $cb));
chk('follow-up registration is assisted by the follower', str_contains($cb, "'assisted_by'       => \$ownerId"));

// ── 11. migration + i18n parity ──────────────────────────────────────────────
echo "migration + i18n\n";
$migs = glob($root . '/app/Modules/Events/Database/Migrations/*CreateEventInvitations.php');
chk('event_invitations migration exists', $migs !== []);
$mig = $migs !== [] ? (string) file_get_contents($migs[0]) : '';
chk('migration defines the direct-invite allow-list', str_contains($mig, 'CREATE TABLE IF NOT EXISTS event_invitations'));
chk('migration defines the shareable-link table', str_contains($mig, 'CREATE TABLE IF NOT EXISTS event_invite_links'));
chk('link table carries mode + redeemed_count + one-per-event unique', str_contains($mig, 'mode') && str_contains($mig, 'redeemed_count') && str_contains($mig, 'eil_event_uq'));
chk('migration is reversible', str_contains($mig, 'DROP TABLE IF EXISTS event_invite_links') && str_contains($mig, 'DROP TABLE IF EXISTS event_invitations'));

$en  = require $root . '/app/Modules/Events/Language/en/Events.php';
$enI = $flat($en['invite'] ?? []);
chk('en has an invite block with link keys', in_array('linkHeading', $enI, true) && in_array('linkModeMax', $enI, true));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr  = require $root . "/app/Modules/Events/Language/$loc/Events.php";
    $keys = $flat($arr['invite'] ?? []);
    chk("$loc mirrors invite keys", array_diff($enI, $keys) === [] && array_diff($keys, $enI) === [],
        'missing: ' . implode(',', array_diff($enI, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enI)));
}

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
