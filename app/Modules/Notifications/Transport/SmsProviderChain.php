<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

use WBS\Notifications\Services\GroupCredentialSource;
use WBS\Notifications\Services\ResolvedCredential;

/**
 * Ordered SMS provider chain: mNotify first, Nalo Solutions second.
 *
 * A body that runs on one aggregator is one outage away from being unable to
 * reach its members, so the `sms` channel is registered as a CHAIN of providers
 * rather than a single transport. The chain is a {@see ChannelTransport} itself,
 * so the dispatcher, the queue and the circuit breaker are unchanged — they still
 * see one transport per channel and one {@see TransportResult} per attempt.
 *
 * WHOSE ACCOUNT IT SENDS ON
 * ------------------------
 * Credentials are per hierarchical group: each body supplies its own provider
 * account (an `integration_connections` row + write-only vault slots) and may
 * grant its subtree access to it. When a {@see GroupCredentialSource}
 * is wired, the chain resolves, for `$message->groupId`, the credentials that
 * body PROVIDED or was GRANTED, and builds each hop's transport from those —
 * its own sender identity, its own base URL/path, its own secret. Env is no
 * longer consulted for secrets on that path.
 *
 * A group with no usable credential is REFUSED, not sent on somebody else's
 * account (fail-closed): the delivery is recorded as a permanent rejection with
 * a reason naming the group, so a mis-provisioned subtree is visible instead of
 * silently billing — and branding — another body. A delivery with no group at
 * all is refused the same way.
 *
 * Without a resolver (unit tests, or an install that has not adopted per-group
 * credentials) the chain behaves exactly as before: one transport per provider
 * built from env, skipping providers the environment never configured.
 *
 * Failover rule (deliberately narrow, identical on both paths):
 *
 *  - **accepted** → stop. The message is with a provider; trying another would
 *    send it twice (and charge twice).
 *  - **rejected** → stop and report the rejection. A rejection is a PERMANENT,
 *    per-message verdict (bad number, unapproved sender id, empty body, no
 *    credential): the next provider would refuse the same message, and
 *    re-sending elsewhere would only hide a data problem. The dispatcher marks
 *    the delivery failed without re-queueing.
 *  - **failed** → try the next provider. A failure is a provider fault (5xx,
 *    timeout, rate limit, bad credentials, no credit), which is precisely the case
 *    where another provider can still deliver. Only when EVERY provider fails does
 *    the chain report failure, so the queue retries with backoff and the breaker
 *    sees a fault.
 *
 * Order comes from the sending group's own connection (`settings.provider_order`)
 * when it declares one, else env `SMS_PROVIDER_ORDER` (comma-separated, default
 * `mnotify,nalo`). A name that matches no usable provider is ignored, and a
 * usable provider the list omits is DEMOTED to the end rather than dropped, so a
 * typo cannot silently disable a fallback.
 */
final class SmsProviderChain implements ChannelTransport
{
    /** @var array<string,callable(?ResolvedCredential,array<string,string>):ChannelTransport> */
    private readonly array $providers;

    /** @var list<string> */
    private readonly array $preferred;

    /**
     * @param array<string,callable(?ResolvedCredential,array<string,string>):ChannelTransport> $providers
     *        name => factory. Called with the resolved credential and its decrypted
     *        secrets on the per-group path, and with (null, []) on the env path —
     *        a zero-argument factory still works, extra arguments are ignored.
     * @param list<string>|null          $preferred   org-wide order override
     * @param GroupCredentialSource|null $credentials per-group credential source;
     *        null keeps the legacy env behaviour
     */
    public function __construct(
        array $providers,
        ?array $preferred = null,
        private readonly ?GroupCredentialSource $credentials = null,
    ) {
        $this->providers = $providers;
        $this->preferred = $preferred ?? [];
    }

    public function channel(): string
    {
        return 'sms';
    }

    /**
     * The provider names in the order they will be attempted: the requested order
     * (filtered to registered providers) followed by any registered provider the
     * request omitted.
     *
     * @return list<string>
     */
    public function order(): array
    {
        $out = [];
        foreach ($this->preferred as $name) {
            $name = strtolower(trim($name));
            if ($name !== '' && isset($this->providers[$name]) && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        foreach (array_keys($this->providers) as $name) {
            if (! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** Every registered provider name, in attempt order. */
    public function providers(): array
    {
        return $this->order();
    }

    /**
     * What this group would actually send on, without touching a secret: the
     * attempt order plus, per hop, whose account it is (`own` / `granted`), the
     * sender identity and whether the credential is usable. This is what the
     * group-credentials screen shows a leader as "your effective chain".
     *
     * @return list<array<string,mixed>>
     */
    public function planFor(string $organizationId, ?string $groupId): array
    {
        if ($this->credentials === null) {
            return [];
        }

        $resolved = $this->credentials->resolveAll($organizationId, $groupId, $this->channel());
        $byProvider = [];
        foreach ($resolved as $cred) {
            $byProvider[$cred->provider] = $cred;
        }

        $plan = [];
        foreach ($this->orderFor($byProvider, $resolved[0] ?? null) as $name) {
            $cred    = $byProvider[$name];
            $plan[]  = [
                'provider'      => $name,
                'connection_id' => $cred->connectionId,
                'owner_group_id'=> $cred->ownerGroupId,
                'via'           => $cred->via,
                'scope_mode'    => $cred->scopeMode,
                'sender_id'     => $cred->senderId,
                'usable'        => $this->credentials->isUsable($cred),
            ];
        }

        return $plan;
    }

    public function deliver(TransportMessage $message): TransportResult
    {
        return $this->credentials === null
            ? $this->deliverFromEnvironment($message)
            : $this->deliverForGroup($message);
    }

    /**
     * Per-group path: every hop runs on credentials the sending body provided or
     * was granted. Nothing here reads env for a secret.
     */
    private function deliverForGroup(TransportMessage $message): TransportResult
    {
        $groupId = $message->groupId;
        if ($groupId === null || $groupId === '') {
            // No body stands behind this message ⇒ no account may be used for it.
            return TransportResult::rejected(
                'no group on delivery: per-group sms credentials cannot be resolved',
            );
        }

        $resolved   = $this->credentials->resolveAll($message->organizationId, $groupId, $this->channel());
        $byProvider = [];
        foreach ($resolved as $cred) {
            $byProvider[$cred->provider] = $cred;
        }

        $usable = [];
        foreach ($byProvider as $name => $cred) {
            if ($this->credentials->isUsable($cred)) {
                $usable[$name] = $cred;
            }
        }
        if ($usable === []) {
            // Fail closed: a body sends on credentials it provided or was granted,
            // never on the platform's. Recorded as a permanent rejection so the
            // queue does not retry a provisioning problem into oblivion.
            return TransportResult::rejected(sprintf(
                'no usable sms credential for group %s (a body sends on the account it provided or was granted)',
                $groupId,
            ));
        }

        $tried       = [];
        $lastFailure = null;

        foreach ($this->orderFor($usable, $resolved[0] ?? null) as $name) {
            $factory = $this->providers[$name] ?? null;
            if ($factory === null) {
                $tried[] = $name . ': skipped (no transport registered)';
                continue;
            }

            $cred = $usable[$name];

            // The plaintext exists only inside the vault callback: the transport
            // is BUILT and the delivery ATTEMPTED there, and nothing decrypted is
            // returned or retained.
            $result = $this->credentials->withSecrets(
                $cred,
                static fn (array $secrets): TransportResult => $factory($cred, $secrets)->deliver($message),
            );

            if (! $result instanceof TransportResult) {
                $tried[] = $name . ': skipped (no usable secret)';
                continue;
            }
            if ($result->isAccepted()) {
                return $result;
            }
            if ($result->isRejected()) {
                return $result;
            }

            $lastFailure = $result;
            $tried[]     = $name . ': ' . (string) ($result->reason ?? 'failed');
        }

        if ($lastFailure === null) {
            return TransportResult::failed(
                'no sms provider could be attempted for group ' . $groupId
                . ' (' . implode(', ', $tried) . ')',
            );
        }

        return TransportResult::failed(
            (string) ($lastFailure->reason ?? 'all sms providers failed')
            . ' [chain: ' . implode(' | ', $tried) . ']',
        );
    }

    /**
     * Legacy path: one transport per provider, built from env, skipping providers
     * this environment never configured.
     */
    private function deliverFromEnvironment(TransportMessage $message): TransportResult
    {
        $tried       = [];
        $lastFailure = null;

        foreach ($this->order() as $name) {
            $transport = ($this->providers[$name])(null, []);

            // Skip a provider this environment never configured: it would only
            // return "not configured" and waste a hop (and a log line that reads
            // like an outage).
            if (method_exists($transport, 'isConfigured') && ! $transport->isConfigured()) {
                $tried[] = $name . ': skipped (not configured)';
                continue;
            }

            $result = $transport->deliver($message);

            if ($result->isAccepted()) {
                return $result;
            }

            // Permanent, per-message: another provider would refuse it too.
            if ($result->isRejected()) {
                return $result;
            }

            $lastFailure = $result;
            $tried[]     = $name . ': ' . (string) ($result->reason ?? 'failed');
        }

        if ($lastFailure === null) {
            // Nothing was even attempted — misconfiguration, not a provider fault.
            return TransportResult::failed('no sms provider configured (' . implode(', ', $this->order()) . ')');
        }

        // Every configured provider faulted: report the last reason plus the hops,
        // so one delivery row explains the whole chain instead of hiding it.
        return TransportResult::failed(
            (string) ($lastFailure->reason ?? 'all sms providers failed') . ' [chain: ' . implode(' | ', $tried) . ']',
        );
    }

    /**
     * Attempt order for the credentials a group actually has: the group's own
     * `settings.provider_order` when its most specific connection declares one,
     * else the org-wide order (env), else registration order — followed by any
     * usable provider the list omitted, demoted rather than dropped.
     *
     * @param array<string,ResolvedCredential> $usable
     *
     * @return list<string>
     */
    private function orderFor(array $usable, ?ResolvedCredential $primary = null): array
    {
        $preferred = [];
        $raw       = $primary?->setting('provider_order');
        if (is_string($raw)) {
            $preferred = self::parseOrder($raw);
        } elseif (is_array($raw)) {
            foreach ($raw as $name) {
                $name = strtolower(trim((string) $name));
                if ($name !== '' && ! in_array($name, $preferred, true)) {
                    $preferred[] = $name;
                }
            }
        }
        if ($preferred === []) {
            $preferred = $this->preferred;
        }

        $out = [];
        foreach ($preferred as $name) {
            if (isset($usable[$name]) && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        foreach (array_keys($this->providers) as $name) {
            if (isset($usable[$name]) && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        foreach (array_keys($usable) as $name) {
            if (! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Parse an order list (`SMS_PROVIDER_ORDER="mnotify,nalo"` or a connection's
     * `settings.provider_order`), tolerating spaces, empty entries and case.
     * Returns [] when unset so the registration order stands.
     *
     * @return list<string>
     */
    public static function parseOrder(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[;,\s]+/', trim($raw)) ?: [] as $name) {
            $name = strtolower(trim($name));
            if ($name !== '' && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
