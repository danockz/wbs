<?php

declare(strict_types=1);

/**
 * FeedTokenService unit test — the signed, cookie-less token behind the PERSONAL
 * iCalendar feed (GET /events/mine.ics?token=…). It stands in for a session when
 * a calendar client fetches the feed, so its security properties matter:
 *
 *   - issue()/verify() round-trip returns the SAME user id;
 *   - the token is STABLE for a user (a subscription keeps working);
 *   - distinct users get distinct tokens; one user's token never verifies as
 *     another's;
 *   - a tampered id part or signature part is REJECTED (null);
 *   - malformed input (empty, no dot, extra dots, garbage base64) is rejected;
 *   - rotating the signing key REVOKES previously issued tokens;
 *   - verification is signature-based (constant-time hash_equals), authorizing
 *     ONLY the encoded user (no privilege beyond read of their own feed).
 *
 *   php app/Modules/Events/Services/tests/feed_token_test.php
 */

$root = dirname(__DIR__, 5);
require $root . '/app/Modules/Events/Services/FeedTokenService.php';

use WBS\Events\Services\FeedTokenService;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$svc  = new FeedTokenService('key-A');
$uid  = 'user-123';
$tok  = $svc->issue($uid);

echo "round-trip + stability\n";
chk('issue returns a two-part token', substr_count($tok, '.') === 1 && $tok !== '');
chk('verify recovers the user id', $svc->verify($tok) === $uid);
chk('token is stable across calls', $svc->issue($uid) === $tok);
chk('empty user id issues empty token', $svc->issue('') === '');

echo "isolation between users\n";
$tok2 = $svc->issue('user-999');
chk('different users -> different tokens', $tok2 !== $tok);
chk('user-999 token verifies to user-999', $svc->verify($tok2) === 'user-999');

echo "tamper rejection\n";
[$idPart, $sigPart] = explode('.', $tok, 2);
chk('tampered signature rejected', $svc->verify($idPart . '.' . strrev($sigPart)) === null);
// Swap in a different user's id but keep this signature -> must fail.
[$id2] = explode('.', $tok2, 2);
chk('swapped id (foreign sig) rejected', $svc->verify($id2 . '.' . $sigPart) === null);
chk('signature-only, wrong id rejected', $svc->verify($idPart . '.' . $idPart) === null);

echo "malformed input\n";
chk('empty string rejected', $svc->verify('') === null);
chk('no separator rejected', $svc->verify('abcdef') === null);
chk('too many parts rejected', $svc->verify('a.b.c') === null);
chk('garbage base64 rejected', $svc->verify('!!!.???') === null);

echo "key rotation revokes\n";
$rotated = new FeedTokenService('key-B');
chk('old token no longer verifies under new key', $rotated->verify($tok) === null);
chk('new key still issues+verifies its own tokens', $rotated->verify($rotated->issue($uid)) === $uid);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
