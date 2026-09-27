<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Integrations\Services\CredentialVault;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\ScopeMode;

/**
 * Which body's credentials does THIS message send on? (FR-INT-007, FR-NOT-*)
 *
 * Every hierarchical group supplies its own provider credentials — an
 * `integration_connections` row owned by that group, with write-only encrypted
 * slots in the {@see CredentialVault} — and separately decides whether its
 * subtree may send on them, via a `capability_grants` row whose `scope_mode`
 * says "this grantee only", "the grantee and every subgroup", "subgroups only"
 * or "these hand-picked groups". This class resolves that, and nothing else.
 *
 * Resolution is most-specific-wins over `GroupScopeResolver::chain()` (self →
 * nearest ancestor → … → org-wide):
 *
 *  - the group's OWN active connection is always usable — a body that provided
 *    credentials does not need a grant to itself;
 *  - an ANCESTOR's (or the org's) connection is usable only when it carries an
 *    ACTIVE, IN-WINDOW grant whose scope covers the sending group. There is no
 *    "it's above me in the tree, so I inherit it" path: inheritance is an
 *    explicit act by the body that owns the account.
 *
 * When nothing resolves, this returns an empty list and the caller MUST NOT
 * send. That is deliberate (fail-closed): a body never sends on credentials it
 * neither provided nor was granted, so a mis-provisioned subtree is a visible,
 * recorded refusal rather than a message silently billed to — and branded by —
 * somebody else.
 *
 * Scope semantics are not re-implemented here: the coverage test is
 * `GroupScopeResolver::grantCoversScoped()`, the same call the PDP makes for
 * role grants, including opt-in cross-cut coverage. A credential grant and a
 * role grant therefore cannot disagree about what "the subtree" means.
 */
final class NotificationCredentialResolver implements GroupCredentialSource
{
    /** The capability a body grants when its subtree may send SMS on its account. */
    public const CAP_SMS = 'sms.send';

    /** channel => capability required to send on somebody's connection. */
    private const CAPABILITY_BY_CHANNEL = [
        'sms'   => self::CAP_SMS,
        'email' => 'email.send',
        'push'  => 'push.send',
    ];

    /** adapter_code => the chain provider name that knows how to talk to it. */
    private const PROVIDER_BY_ADAPTER = [
        'mnotify_sms_v1' => 'mnotify',
        'nalo_sms_v1'    => 'nalo',
    ];

    /** provider => secret slots to load, in the order the transport wants them. */
    private const SLOTS_BY_PROVIDER = [
        'mnotify' => ['api_key'],
        'nalo'    => ['username', 'password', 'auth_key'],
    ];

    /** `grant_scope_groups.grant_type` value for a hand-picked credential grant. */
    public const GRANT_TYPE = 'capability_grant';

    /**
     * Specificity of a credential whose owner is not on the sending group's chain
     * (a cross-cut branch sharing sideways): after every real ancestor, before the
     * org-wide slot.
     */
    private const OFF_CHAIN = 1000;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly CredentialVault $vault,
        private readonly GroupScopeResolver $scope,
        private readonly ?Clock $clock = null,
    ) {
    }

    /** The capability that governs sending on this channel. */
    public function capabilityFor(string $channel): string
    {
        return self::CAPABILITY_BY_CHANNEL[strtolower(trim($channel))] ?? (strtolower(trim($channel)) . '.send');
    }

    /**
     * Every credential $groupId may send on, most specific first and at most one
     * per provider (the nearest body wins for that provider). Empty when the
     * group neither provided nor was granted one — the caller must not send.
     *
     * @return list<ResolvedCredential>
     */
    public function resolveAll(string $organizationId, ?string $groupId, string $channel): array
    {
        $channel = strtolower(trim($channel));
        if ($organizationId === '' || $groupId === null || $groupId === '') {
            // No body ⇒ no credentials ⇒ fail closed. An org-wide *action* is not
            // a body, and only an explicit grant can authorize a named group.
            return [];
        }

        $connections = $this->activeConnectionsForChannel($organizationId, $channel);
        if ($connections === []) {
            return [];
        }
        $byId = [];
        foreach ($connections as $c) {
            $byId[(string) ($c['id'] ?? '')] = $c;
        }

        // Position in the most-specific-wins chain: 0 = this group, 1 = its parent,
        // … and the trailing org-wide slot last of all. An owner that is NOT on the
        // chain (a cross-cut branch sharing sideways) sorts after every ancestor
        // but before org-wide, so "nearest body that provided or granted" still
        // decides.
        $distance = [];
        foreach ($this->scope->chain($groupId) as $index => [$candidate, $isSelf]) {
            if ($candidate !== null) {
                $distance[$candidate] = $isSelf ? 0 : $index;
            }
        }

        /** @var array<string,array{0:int,1:string,2:array<string,mixed>|null,3:array<string,mixed>}> $best */
        $best     = [];
        $consider = function (array $conn, int $specificity, string $via, ?array $grant) use (&$best): void {
            $provider = $this->providerFor($conn);
            if ($provider === null) {
                return; // nothing in this channel knows that adapter
            }
            if (isset($best[$provider]) && $best[$provider][0] <= $specificity) {
                return; // a nearer body already won this provider
            }
            $best[$provider] = [$specificity, $via, $grant, $conn];
        };

        // 1) The group's OWN connection needs no grant: a body that supplied an
        //    account may always send on it.
        foreach ($connections as $conn) {
            if ((string) ($conn['group_id'] ?? '') === $groupId) {
                $consider($conn, 0, 'own', null);
            }
        }

        // 2) Anybody else's connection needs an ACTIVE, IN-WINDOW grant whose scope
        //    reaches this group. Being above me in the tree is NOT enough — sharing
        //    is an explicit act by the body that owns the account, and the coverage
        //    test is the shared resolver's, so hierarchy, hand-picked sets and
        //    opt-in cross-cut links all behave exactly as they do for role grants.
        foreach ($this->liveGrants($organizationId, $this->capabilityFor($channel)) as $grants) {
            foreach ($grants as $grant) {
                $conn = $byId[(string) ($grant['connection_id'] ?? '')] ?? null;
                if ($conn === null || ! $this->grantCovers($grant, $groupId)) {
                    continue;
                }
                $owner = isset($conn['group_id']) && $conn['group_id'] !== '' ? (string) $conn['group_id'] : null;
                $consider(
                    $conn,
                    $owner === null ? PHP_INT_MAX : ($distance[$owner] ?? self::OFF_CHAIN),
                    'granted',
                    $grant,
                );
            }
        }

        $out = [];
        foreach ($best as $provider => [$specificity, $via, $grant, $conn]) {
            $owner = isset($conn['group_id']) && $conn['group_id'] !== '' ? (string) $conn['group_id'] : null;
            $out[] = new ResolvedCredential(
                connectionId: (string) ($conn['id'] ?? ''),
                provider: $provider,
                adapterCode: (string) ($conn['adapter_code'] ?? ''),
                channel: $channel,
                ownerGroupId: $owner,
                via: $via,
                grantId: $grant !== null ? (string) ($grant['id'] ?? '') : null,
                scopeMode: $grant !== null
                    ? ScopeMode::normalize($grant['scope_mode'] ?? null, null)
                    : ScopeMode::SELF,
                senderId: $this->senderIdFor($conn),
                settings: $this->settingsFor($conn),
                specificity: $specificity,
            );
        }
        usort($out, static fn (ResolvedCredential $a, ResolvedCredential $b): int => [$a->specificity, $a->provider] <=> [$b->specificity, $b->provider]);

        return $out;
    }

    /** The single most-specific credential for this group/channel, or null. */
    public function resolve(string $organizationId, ?string $groupId, string $channel): ?ResolvedCredential
    {
        $all = $this->resolveAll($organizationId, $groupId, $channel);

        return $all[0] ?? null;
    }

    /**
     * Open the vault boundary, load every slot this provider uses, and hand the
     * plaintext to $fn — which is expected to build the transport and attempt
     * delivery INSIDE the callback. Nothing decrypted is returned to the caller
     * or stored on an object; a missing slot arrives as '' so a single-key Nalo
     * account (auth_key only) and a username/password one both work.
     *
     * @template T
     * @param callable(array<string,string>):T $fn
     * @return T|null null when the credential has no usable secret at all
     */
    public function withSecrets(ResolvedCredential $credential, callable $fn): mixed
    {
        if (! $this->isUsable($credential)) {
            return null;
        }

        $slots = self::SLOTS_BY_PROVIDER[$credential->provider] ?? [];
        $load  = function (int $i, array $acc) use (&$load, $slots, $credential, $fn): mixed {
            if ($i >= count($slots)) {
                return $fn($acc);
            }
            $slot = $slots[$i];
            if (! $this->vault->has($credential->connectionId, $slot)) {
                $acc[$slot] = '';

                return $load($i + 1, $acc);
            }

            return $this->vault->useSecret(
                $credential->connectionId,
                $slot,
                static function (#[\SensitiveParameter] string $plain) use ($i, $acc, $slot, $load): mixed {
                    $acc[$slot] = $plain;

                    return $load($i + 1, $acc);
                },
            );
        };

        return $load(0, []);
    }

    /**
     * Does this credential carry the secrets its provider needs? Mirrors each
     * transport's own `isConfigured()` rule so the chain skips a connection that
     * was approved with a sender ID but no key, instead of burning a hop on it.
     */
    public function isUsable(ResolvedCredential $credential): bool
    {
        return match ($credential->provider) {
            'mnotify' => $this->vault->has($credential->connectionId, 'api_key'),
            'nalo'    => $this->vault->has($credential->connectionId, 'auth_key')
                || ($this->vault->has($credential->connectionId, 'username')
                    && $this->vault->has($credential->connectionId, 'password')),
            default   => false,
        };
    }

    /** The provider names this resolver can hand to a chain, in registry order. */
    public function knownProviders(): array
    {
        return array_values(self::PROVIDER_BY_ADAPTER);
    }

    // ---------------------------------------------------------------- internals

    /**
     * Active connections in this org that serve $channel. The channel filter is
     * applied in PHP (the `channels` JSON column, falling back to the adapter
     * map) so the resolver stays readable in a query log and testable without a
     * JSON-capable database.
     *
     * @return list<array<string,mixed>>
     */
    private function activeConnectionsForChannel(string $organizationId, string $channel): array
    {
        $rows = $this->db->table('integration_connections')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            if ($this->servesChannel($r, $channel)) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $conn */
    private function servesChannel(array $conn, string $channel): bool
    {
        $declared = $conn['channels'] ?? null;
        if (is_string($declared) && $declared !== '') {
            $decoded = json_decode($declared, true);
            if (is_array($decoded)) {
                return in_array($channel, array_map('strval', $decoded), true);
            }
        }
        if (is_array($declared)) {
            return in_array($channel, array_map('strval', $declared), true);
        }

        // No declaration: a known SMS adapter is an SMS connection.
        return $channel === 'sms' && $this->providerFor($conn) !== null;
    }

    /** The chain provider name for a connection, or null when nothing knows it. */
    private function providerFor(array $conn): ?string
    {
        $adapter = strtolower(trim((string) ($conn['adapter_code'] ?? '')));
        if (isset(self::PROVIDER_BY_ADAPTER[$adapter])) {
            return self::PROVIDER_BY_ADAPTER[$adapter];
        }
        $settings = $this->settingsFor($conn);
        $named    = strtolower(trim((string) ($settings['provider'] ?? '')));

        return $named !== '' && in_array($named, self::PROVIDER_BY_ADAPTER, true) ? $named : null;
    }

    /** @param array<string,mixed> $conn */
    private function senderIdFor(array $conn): ?string
    {
        $sender = trim((string) ($conn['sender_identity'] ?? ''));
        if ($sender !== '') {
            return $sender;
        }
        $settings = $this->settingsFor($conn);
        $fromSettings = trim((string) ($settings['sender_id'] ?? ''));

        return $fromSettings !== '' ? $fromSettings : null;
    }

    /**
     * Non-secret config only. `settings` is documented as NON-secret in the
     * schema and the vault holds everything else, so this is safe to carry on a
     * value object that gets logged.
     *
     * @param array<string,mixed> $conn
     * @return array<string,mixed>
     */
    private function settingsFor(array $conn): array
    {
        $raw = $conn['settings'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Active, in-window grants for this capability, keyed by connection id.
     * Capability matching mirrors the PDP: exact, or a trailing `.*` wildcard
     * (`sms.*` covers `sms.send`).
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function liveGrants(string $organizationId, string $capability): array
    {
        $rows = $this->db->table('capability_grants')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get()->getResultArray();

        $now  = $this->now();
        $out  = [];
        foreach ($rows as $g) {
            if (! $this->capabilityMatches((string) ($g['capability'] ?? ''), $capability)) {
                continue;
            }
            $starts  = (string) ($g['starts_at'] ?? '');
            $expires = isset($g['expires_at']) ? (string) $g['expires_at'] : '';
            if ($starts !== '' && strcmp($starts, $now) > 0) {
                continue; // not yet in force
            }
            if ($expires !== '' && strcmp($expires, $now) <= 0) {
                continue; // lapsed
            }
            $out[(string) ($g['connection_id'] ?? '')][] = $g;
        }

        return $out;
    }

    /** Supports exact match or a trailing ".*" wildcard prefix, as the PDP does. */
    private function capabilityMatches(string $pattern, string $capability): bool
    {
        if ($pattern === $capability) {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            return str_starts_with($capability, substr($pattern, 0, -1));
        }

        return false;
    }

    /**
     * Does this grant's scope reach $groupId? Delegates to the shared resolver —
     * the same call the PDP makes for role grants — so `scope_mode`, the
     * hand-picked GROUPS set and opt-in cross-cut coverage behave identically
     * here and there. A grant written before scope_mode existed normalizes to
     * SELF: the named grantee only, exactly as it always meant.
     *
     * @param array<string,mixed> $grant
     */
    private function grantCovers(array $grant, string $groupId): bool
    {
        $mode = ScopeMode::normalize($grant['scope_mode'] ?? null, null);
        $set  = $mode === ScopeMode::GROUPS ? $this->grantGroupSet((string) ($grant['id'] ?? '')) : [];

        return $this->scope->grantCoversScoped(
            isset($grant['grantee_group_id']) && $grant['grantee_group_id'] !== ''
                ? (string) $grant['grantee_group_id']
                : null,
            $mode,
            $groupId,
            $set,
            ! empty($grant['include_crosscut']),
        );
    }

    /** Hand-picked group set for a GROUPS-mode grant. @return list<string> */
    private function grantGroupSet(string $grantId): array
    {
        $rows = $this->db->table('grant_scope_groups')
            ->select('group_id')
            ->where('grant_type', self::GRANT_TYPE)
            ->where('grant_id', $grantId)
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r): string => (string) $r['group_id'], $rows));
    }

    /** UTC 'Y-m-d H:i:s' for the in-window test (DATETIME columns compare as strings). */
    private function now(): string
    {
        return $this->clock !== null
            ? $this->clock->nowUtcString()
            : gmdate('Y-m-d H:i:s');
    }
}
