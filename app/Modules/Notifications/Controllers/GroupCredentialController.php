<?php

declare(strict_types=1);

namespace WBS\Notifications\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Notifications\Transport\SmsProviderChain;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\ScopeMode;

/**
 * Group notification credentials — "whose account does MY subtree send on?".
 *
 * Every hierarchical body supplies its own provider account and decides whether
 * its subtree may send on it (FR-INT-007, FR-NOT-*). This is the leader-facing
 * screen for exactly that question, and it is deliberately a THIN face over the
 * Integrations services that own the data:
 *
 *  - connections, secrets and grants are read/written through
 *    {@see \WBS\Integrations\Services\ConnectionService} and the write-only
 *    {@see \WBS\Integrations\Services\CredentialVault} — one writer, no fork;
 *  - the effective chain comes from
 *    {@see \WBS\Notifications\Services\NotificationCredentialResolver} via
 *    `SmsProviderChain::planFor()`, i.e. the same resolution a real send uses, so
 *    the screen cannot disagree with the pipeline;
 *  - every read and write is bounded to the actor's OWN leadership scope through
 *    the PDP (`canManageGroupScope('provider.configure', group)`), and a grant is
 *    additionally bounded by the service to the connection owner's subtree.
 *
 * Secret material is never echoed: the form posts a slot, the vault stores it
 * encrypted and write-only, and the page shows only which slots exist. Provider
 * testing, approval and activation stay on the Integrations connections page
 * (linked from here) so the lifecycle has one home.
 */
final class GroupCredentialController extends BaseController
{
    /** Landing page, so the menu and PRG redirects agree. */
    public const DASHBOARD = '/notifications/credentials';

    /** Capabilities a body can share with its subtree, per channel. */
    private const CAPABILITIES = ['sms.send', 'email.send', 'push.send'];

    /**
     * The secret slots each SMS adapter expects, keyed by adapter code.
     *
     * `CredentialVault::put()` is a generic slot store — it will happily encrypt
     * whatever name it is handed — so the allowlist lives here, next to the form
     * that offers the same list, and a write naming any other slot is refused: a
     * typo (or a crafted post) must not mint a slot no transport will ever read.
     *
     * @var array<string,list<string>>
     */
    private const ADAPTER_SLOTS = [
        'mnotify_sms_v1' => ['api_key'],
        'nalo_sms_v1'    => ['username', 'password', 'auth_key'],
    ];

    /** Memo of the actor's leadership scope, keyed by group id. */
    private array $scopeCache = [];

    /** GET credentials — the group credentials dashboard. */
    public function index()
    {
        $orgId  = $this->orgId();
        $groups = $this->groupsInScope();

        $selected = trim((string) ($this->field('group') ?? ''));
        if ($selected === '' || ! $this->inScope($selected)) {
            $selected = (string) ($groups[0]['id'] ?? '');
        }

        $connections = $this->manageableConnections($orgId);
        $grants      = [];
        foreach ($connections as $c) {
            $grants[(string) ($c['id'] ?? '')] = IntegrationServices::connections()->grantsFor($orgId, (string) ($c['id'] ?? ''));
        }

        $names = [];
        foreach (GroupServices::groups()->listForOrg($orgId, 2000) as $g) {
            $names[(string) ($g['id'] ?? '')] = (string) ($g['name'] ?? $g['id'] ?? '');
        }

        $payload = [
            'connections' => $connections,
            'grants'      => $grants,
            'groups'      => $groups,
            'groupNames'  => $names,
            'selected'    => $selected,
            'plan'        => $this->effectivePlan($orgId, $selected),
            'adapters'    => $this->smsAdapters(),
            'scopes'      => ScopeMode::ALL,
            'capabilities'=> self::CAPABILITIES,
        ];

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($payload));
        }

        $csrf = \WBS\Identity\Config\Services::webAuth()->issueCsrf();
        $body = view('WBS\Notifications\Views\group_credentials', $payload + [
            'csrf' => $csrf,
            // Which secret slots each provider expects, so the form offers the
            // right ones (write-only: the page never shows a stored value).
            'slots' => self::ADAPTER_SLOTS,
        ]);

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($body)
            ->setCookie($this->csrfCookie($csrf));
    }

    /** POST credentials/connections — add an account for a group I lead. */
    public function create()
    {
        $in      = $this->input();
        $groupId = trim((string) ($in['group_id'] ?? ''));

        // A body may only provision credentials for a group it actually leads.
        if ($groupId === '' || ! $this->inScope($groupId)) {
            return $this->afterWrite(Result::denied('access.out_of_scope', 'ACCESS_OUT_OF_SCOPE'));
        }

        // This screen only ever provisions SMS accounts, and `ConnectionService`
        // does not consult the catalogue: an adapter that is not an ACTIVE sms
        // adapter would create a connection no transport can use and a slot list
        // nobody could fill, so it is refused here. The version is taken from the
        // catalogue row rather than the form, so the two can never disagree.
        $adapter = trim((string) ($in['adapter_code'] ?? ''));
        $catalog = null;
        foreach ($this->smsAdapters() as $a) {
            if ((string) ($a['code'] ?? '') === $adapter) {
                $catalog = $a;

                break;
            }
        }
        if ($catalog === null) {
            return $this->afterWrite(Result::fail('BAD_ADAPTER', 'integration.adapter_not_found', 422));
        }

        $res = IntegrationServices::connections()->create($this->orgId(), (string) ($this->actorId() ?? ''), [
            'group_id'        => $groupId,
            'adapter_code'    => $adapter,
            'adapter_version' => (int) ($catalog['version'] ?? 1),
            'category'        => 'notification',
            'display_name'    => $in['display_name'] ?? null,
            'channels'        => ['sms'],
            'sender_identity' => $in['sender_identity'] ?? null,
            // Non-secret wire config only; secrets go to the vault separately.
            'settings'        => array_filter([
                'api_base_url'   => trim((string) ($in['api_base_url'] ?? '')),
                'api_path'       => trim((string) ($in['api_path'] ?? '')),
                'country_code'   => trim((string) ($in['country_code'] ?? '')),
                'provider_order' => trim((string) ($in['provider_order'] ?? '')),
            ], static fn (string $v): bool => $v !== ''),
        ]);

        return $this->afterWrite($res, 'createdFlash');
    }

    /** POST credentials/connections/{id}/secrets — store or rotate one slot. */
    public function setSecret(string $connectionId = '')
    {
        $in  = $this->input();
        $row = null;
        if ($guard = $this->connectionOutOfScope($connectionId, $row)) {
            return $this->afterWrite($guard);
        }

        $slot   = (string) ($in['slot'] ?? '');
        $secret = (string) ($in['secret'] ?? '');
        if (! in_array($slot, self::ADAPTER_SLOTS[(string) ($row['adapter_code'] ?? '')] ?? [], true)) {
            return $this->afterWrite(Result::fail('BAD_SLOT', 'integration.credential_bad_slot', 422));
        }
        if ($secret === '') {
            return $this->afterWrite(Result::fail('EMPTY_SECRET', 'integration.empty_secret', 422));
        }

        return $this->afterWrite(
            IntegrationServices::connections()->setCredential($connectionId, $slot, $secret),
            'secretFlash',
        );
    }

    /** POST credentials/connections/{id}/grants — share with part of my subtree. */
    public function grant(string $connectionId = '')
    {
        $in      = $this->input();
        $grantee = trim((string) ($in['grantee_group_id'] ?? ''));

        if ($guard = $this->connectionOutOfScope($connectionId)) {
            return $this->afterWrite($guard);
        }
        // The leader shares within their OWN scope; ConnectionService additionally
        // bounds the grantee to the connection OWNER's subtree (containment).
        if ($grantee === '' || ! $this->inScope($grantee)) {
            return $this->afterWrite(Result::denied('access.out_of_scope', 'ACCESS_OUT_OF_SCOPE'));
        }

        $capability = (string) ($in['capability'] ?? 'sms.send');
        if (! in_array($capability, self::CAPABILITIES, true)) {
            return $this->afterWrite(Result::fail('BAD_CAPABILITY', 'integration.grant_bad_capability', 422));
        }
        $scopeMode = (string) ($in['scope_mode'] ?? ScopeMode::SELF);
        if (! ScopeMode::isValid($scopeMode)) {
            return $this->afterWrite(Result::fail('BAD_SCOPE', 'integration.grant_bad_scope', 422));
        }

        $groups = $in['groups'] ?? [];
        if (is_string($groups)) {
            $groups = $groups === '' ? [] : [$groups];
        }
        // Hand-picked groups must each be in the actor's own scope too.
        foreach (is_array($groups) ? $groups : [] as $gid) {
            if (! $this->inScope((string) $gid)) {
                return $this->afterWrite(Result::denied('access.out_of_scope', 'ACCESS_OUT_OF_SCOPE'));
            }
        }

        return $this->afterWrite(
            IntegrationServices::connections()->grantCapability($this->orgId(), $connectionId, $grantee, $capability, [
                'scope_mode'       => $scopeMode,
                'groups'           => is_array($groups) ? $groups : [],
                'include_crosscut' => (bool) ($in['include_crosscut'] ?? false),
                'starts_at'        => $this->windowStamp($in['starts_at'] ?? null),
                'expires_at'       => $this->windowStamp($in['expires_at'] ?? null, true),
            ]),
            'grantedFlash',
        );
    }

    /** POST credentials/grants/{id}/revoke — stop sharing. */
    public function revokeGrant(string $grantId = '')
    {
        $in           = $this->input();
        $connectionId = trim((string) ($in['connection_id'] ?? ''));
        if ($guard = $this->connectionOutOfScope($connectionId)) {
            return $this->afterWrite($guard);
        }

        return $this->afterWrite(
            IntegrationServices::connections()->revokeGrant($grantId),
            'revokedFlash',
        );
    }

    // ---------------------------------------------------------------- internals

    /**
     * Connections this actor may MANAGE: those owned by a group in their
     * leadership scope, plus org-wide ones when their grant is org-wide. Accounts
     * merely SHARED with them are not listed here — they appear (read-only) in the
     * effective-chain panel, because a leader must be able to see whose account
     * their subtree sends on without being able to rotate somebody else's key.
     *
     * @return list<array<string,mixed>>
     */
    private function manageableConnections(string $orgId): array
    {
        $out = [];
        foreach (IntegrationServices::connections()->listForOrg($orgId) as $c) {
            // An org-wide account (no owning group) is manageable only by an
            // actor whose provider.configure grant is itself org-wide.
            $groupId = isset($c['group_id']) && $c['group_id'] !== '' ? (string) $c['group_id'] : '';
            if ($this->inScope($groupId)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * What the selected group would actually send on, in order — resolved by the
     * same code path a real send uses, so the screen cannot drift from the
     * pipeline. Never includes secret material.
     *
     * @return list<array<string,mixed>>
     */
    private function effectivePlan(string $orgId, string $groupId): array
    {
        if ($groupId === '') {
            return [];
        }
        $registry = NotificationServices::transportRegistry();
        if (! $registry->has('sms')) {
            return [];
        }
        $transport = $registry->for('sms');

        return $transport instanceof SmsProviderChain ? $transport->planFor($orgId, $groupId) : [];
    }

    /** Catalogue adapters that serve the sms channel, for the "add an account" form. */
    private function smsAdapters(): array
    {
        $out = [];
        foreach (IntegrationServices::adapterCatalog()->activeAdapters() as $a) {
            $channels = $a['channels'] ?? null;
            if (is_string($channels) && $channels !== '') {
                $channels = json_decode($channels, true);
            }
            if (is_array($channels) && in_array('sms', array_map('strval', $channels), true)) {
                $out[] = $a;
            }
        }

        return $out;
    }

    /** Groups the actor leads, indented by depth, for pickers. */
    private function groupsInScope(): array
    {
        $out = [];
        foreach (GroupServices::groups()->listForOrg($this->orgId(), 2000) as $g) {
            $id = (string) ($g['id'] ?? '');
            if ($id !== '' && $this->inScope($id)) {
                $out[] = $g;
            }
        }

        return $out;
    }

    /**
     * Leadership-scope test through the PDP — the same call the review queues and
     * every other group-scoped surface make, so scope_mode, hand-picked sets,
     * cross-cut links and break-glass all apply identically. Memoised per group.
     * An empty id asks the org-wide question (null target).
     */
    private function inScope(string $groupId): bool
    {
        $key = $groupId === '' ? '*' : $groupId;
        if (! array_key_exists($key, $this->scopeCache)) {
            $this->scopeCache[$key] = $this->canManageGroupScope(
                'provider.configure',
                $groupId === '' ? null : $groupId,
            );
        }

        return $this->scopeCache[$key] === true;
    }

    /**
     * Denied unless the connection belongs to a group this actor manages.
     *
     * @param array<string,mixed>|null $row out: the found connection, so a caller
     *                                      that already passed the guard can read
     *                                      the adapter without scanning again
     */
    private function connectionOutOfScope(string $connectionId, ?array &$row = null): ?Result
    {
        $row = null;
        if ($connectionId === '') {
            return Result::notFound('integration.connection_not_found', 'CONNECTION_NOT_FOUND');
        }
        foreach (IntegrationServices::connections()->listForOrg($this->orgId()) as $c) {
            if ((string) ($c['id'] ?? '') !== $connectionId) {
                continue;
            }
            $row     = $c;
            $groupId = isset($c['group_id']) && $c['group_id'] !== '' ? (string) $c['group_id'] : '';

            return $this->inScope($groupId) ? null : Result::denied('access.out_of_scope', 'ACCESS_OUT_OF_SCOPE');
        }

        return Result::notFound('integration.connection_not_found', 'CONNECTION_NOT_FOUND');
    }

    /**
     * Normalize a share-window date field into a full timestamp.
     *
     * A `<input type="date">` posts `Y-m-d` or an empty string; storing the empty
     * string in a datetime column is a hard error, and a bare date would make an
     * expiry cut a body off at midnight *before* the day the leader picked. So:
     * empty or unparseable ⇒ `null` (no bound), a start ⇒ that day 00:00:00, an
     * expiry ⇒ that day 23:59:59 (inclusive). No timezone arithmetic — the value
     * is a wall-clock bound the leader chose, compared against stored timestamps.
     */
    private function windowStamp(mixed $value, bool $endOfDay = false): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1) {
            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return $raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        // Already a timestamp: keep it, but only if it is a real one.
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw) === 1
            ? str_replace('T', ' ', $raw)
            : null;
    }

    /**
     * Browsers get a post-redirect-get back to this dashboard (a refresh never
     * re-posts, and no secret is ever echoed); API clients keep the JSON Result.
     */
    private function afterWrite(Result $result, string $okKey = ''): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        if (! $result->ok) {
            return redirect()->to(self::DASHBOARD)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to(self::DASHBOARD)
            ->with('success', $okKey === '' ? '' : lang('Notifications.credentials.' . $okKey));
    }

    /**
     * Double-submit CSRF cookie matching the ContactBook/WebSession shape
     * (HttpOnly, SameSite=Lax, Secure over HTTPS) so the dashboard forms pass the
     * `webcsrf` guard on POST.
     *
     * @return array<string,mixed>
     */
    private function csrfCookie(string $value): array
    {
        $secure = str_contains(strtolower($this->request->getHeaderLine('X-Forwarded-Proto')), 'https')
            || (method_exists($this->request, 'isSecure') && $this->request->isSecure());

        return [
            'name'     => 'wbs_csrf',
            'value'    => $value,
            'expires'  => 7200,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
