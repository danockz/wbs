<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

/**
 * The seam a transport uses to ask "whose account does this message send on?".
 *
 * {@see NotificationCredentialResolver} is the production implementation (group
 * hierarchy + capability grants + the encrypted vault). The interface exists so a
 * channel transport depends on the QUESTION rather than on Integrations' storage:
 * a chain can be tested with a handful of canned credentials, and a future
 * implementation (per-org credential store, a cached resolver, a test double)
 * drops in without touching a transport.
 *
 * The contract is deliberately fail-closed: an implementation returns an EMPTY
 * list when a group neither provided nor was granted a credential, and callers
 * must refuse to send rather than reach for somebody else's account.
 */
interface GroupCredentialSource
{
    /**
     * Every credential $groupId may send on for $channel, most specific first and
     * at most one per provider. Empty ⇒ the caller MUST NOT send.
     *
     * @return list<ResolvedCredential>
     */
    public function resolveAll(string $organizationId, ?string $groupId, string $channel): array;

    /** Does this credential carry the secrets its provider actually needs? */
    public function isUsable(ResolvedCredential $credential): bool;

    /**
     * Run $fn with this credential's decrypted secrets. The plaintext exists only
     * inside the callback: nothing decrypted is returned to the caller or stored
     * on an object.
     *
     * @template T
     * @param callable(array<string,string>):T $fn
     * @return T|null null when the credential has no usable secret
     */
    public function withSecrets(ResolvedCredential $credential, callable $fn): mixed;
}
