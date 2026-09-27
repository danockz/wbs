<?php

declare(strict_types=1);

// Fake the KeyProvider seam so the signer is testable offline.
namespace WBS\Shared\Security {
    interface KeyProvider
    {
        public function activeKeyId(): string;
        public function keyFor(string $keyId): string;
    }
}

namespace Fake {
    use WBS\Shared\Security\KeyProvider;

    final class MultiKeyProvider implements KeyProvider
    {
        /** @param array<string,string> $keys keyId => raw key */
        public function __construct(private array $keys, private string $active) {}
        public function activeKeyId(): string { return $this->active; }
        public function keyFor(string $keyId): string
        {
            if (! isset($this->keys[$keyId])) {
                throw new \RuntimeException("unknown key {$keyId}");
            }
            return $this->keys[$keyId];
        }
        public function rotate(string $newId, string $key): void
        {
            $this->keys[$newId] = $key;
            $this->active = $newId;
        }
    }
}

namespace {
    require __DIR__ . '/../PermissionBits.php';
    require __DIR__ . '/../SignedWord.php';

    use WBS\Shared\Navigation\{PermissionBits as PB, SignedWord};
    use Fake\MultiKeyProvider;

    $p = 0; $f = 0;
    function chk(string $n, bool $c): void { global $p, $f; echo ($c ? 'PASS' : 'FAIL') . " $n\n"; $c ? $p++ : $f++; }

    $keys = new MultiKeyProvider(['k1' => str_repeat("\x11", 32)], 'k1');
    $sw   = new SignedWord($keys);

    $word  = PB::mask('event.create') | PB::mask('group.create');
    $now   = 1_000_000;
    $args  = ['u1', 'o1', 'gCell', 7, 'rtv123', 'catABC'];

    // 1. Mint + verify roundtrip
    $tok = $sw->mint($word, ...[...$args, 3600, $now]);
    chk('token has v1.<keyId>.<payload>.<mac> shape', substr_count($tok, '.') === 3 && str_starts_with($tok, 'v1.k1.'));
    chk('verify returns the exact word', $sw->verify($tok, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now) === $word);

    // 2. Tamper: flip a char in the payload -> reject
    $bad = $tok; $bad[10] = $bad[10] === 'A' ? 'B' : 'A';
    chk('tampered token rejected', $sw->verify($bad, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now) === null);

    // 3. Forged MAC / garbage
    chk('garbage token rejected', $sw->verify('v1.k1.aaaa.bbbb', 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now) === null);
    chk('wrong-format token rejected', $sw->verify('nope', 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now) === null);

    // 4. Expiry
    chk('expired token rejected', $sw->verify($tok, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now + 3601) === null);
    chk('token valid just before expiry', $sw->verify($tok, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now + 3599) === $word);

    // 5. Context binding: every dimension must match
    chk('wrong subject rejected', $sw->verify($tok, 'uX', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now) === null);
    chk('wrong org rejected',     $sw->verify($tok, 'u1', 'oX', 'gCell', 7, 'rtv123', 'catABC', $now) === null);
    chk('wrong scope rejected',   $sw->verify($tok, 'u1', 'o1', 'gOther', 7, 'rtv123', 'catABC', $now) === null);
    chk('stale grant version rejected', $sw->verify($tok, 'u1', 'o1', 'gCell', 8, 'rtv123', 'catABC', $now) === null);
    chk('stale role-table version rejected', $sw->verify($tok, 'u1', 'o1', 'gCell', 7, 'rtvXXX', 'catABC', $now) === null);
    chk('stale catalog version rejected', $sw->verify($tok, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catXXX', $now) === null);

    // 6. Null-scope (org view) roundtrip
    $tokOrg = $sw->mint($word, 'u1', 'o1', null, 7, 'rtv123', 'catABC', 3600, $now);
    chk('null-scope token verifies with null scope', $sw->verify($tokOrg, 'u1', 'o1', null, 7, 'rtv123', 'catABC', $now) === $word);
    chk('null-scope token rejects a concrete scope', $sw->verify($tokOrg, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now) === null);

    // 7. Key rotation: old token still verifies via embedded keyId; new tokens use new key
    $keys->rotate('k2', str_repeat("\x22", 32));
    chk('old token (k1) still verifies after rotation', $sw->verify($tok, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now) === $word);
    $tok2 = $sw->mint($word, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', 3600, $now);
    chk('new token is minted under k2', str_starts_with($tok2, 'v1.k2.'));
    chk('new token verifies', $sw->verify($tok2, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now) === $word);

    // 8. Unknown keyId in token -> reject (not crash)
    $forgedKey = 'v1.kZZZ.' . explode('.', $tok)[2] . '.' . explode('.', $tok)[3];
    chk('token with unknown keyId rejected safely', $sw->verify($forgedKey, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now) === null);

    // 9. Word constrained to known bits even if minted over-wide (defense in depth)
    $overWide = $sw->mint(~0 & PHP_INT_MAX, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', 3600, $now);
    $verified = $sw->verify($overWide, 'u1', 'o1', 'gCell', 7, 'rtv123', 'catABC', $now);
    chk('verified word has no bits outside allKnownMask', $verified !== null && ($verified & ~PB::allKnownMask()) === 0);

    echo "\n== $p passed, $f failed ==\n";
    exit($f > 0 ? 1 : 0);
}
