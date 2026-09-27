<?php

declare(strict_types=1);

namespace WBS\Admin\Controllers;

use WBS\Admin\Config\Services as AdminServices;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Platform administration endpoints (SRS FR-GRP-006 / FR-ACL-007).
 *
 * All routes sit behind auth + authorize:admin.manage. Changes are versioned and
 * audited inside the services.
 */
final class AdminController extends BaseController
{
    /**
     * GET admin — the organization settings + feature-flags console (the Admin
     * menu landing page). Browsers get the bespoke settings view; API clients get
     * JSON. Read-only; gated by admin.manage in Routes.php.
     */
    /** Value TYPES a setting / group-config value can be captured as (form picker). */
    private const VALUE_TYPES = ['string', 'integer', 'number', 'boolean', 'json'];

    /** Inheritance modes offered by the group-config write form (mirror the resolver). */
    private const INHERITANCE_MODES = ['ancestor_default_child_override', 'inherit_only', 'child_owned', 'not_inheritable'];

    public function index()
    {
        $orgId = $this->orgId();
        $data  = [
            'settings' => AdminServices::settings()->listSettings($orgId),
            'flags'    => AdminServices::settings()->listFlags($orgId),
        ];

        return $this->respondWith(
            Result::ok($data),
            'WBS\Admin\Views\settings',
            null,
            $data + [
                // Write-control chrome for the browser form: the CSRF token minted
                // by the global webcsrfissue filter, the value-type vocabulary for
                // the type picker, and the org group roster so the feature-flag
                // SCOPE (an entity reference) is a group PICKER, not a free-text id.
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
                'types'  => self::VALUE_TYPES,
                'groups' => $this->groupPickerOptions($orgId),
            ],
        );
    }

    /**
     * GET admin/providers — configured provider connections (the Admin →
     * Providers landing page). Browsers get the bespoke providers view; API
     * clients get JSON. Read-only (NON-secret columns only); the underlying
     * connections service never returns credentials.
     */
    public function providers()
    {
        $status      = $this->field('status');
        $connections = IntegrationServices::connections()->listForOrg(
            $this->orgId(),
            $status !== null ? (string) $status : null,
        );

        return $this->respondWith(
            Result::ok(['providers' => $connections]),
            'WBS\Admin\Views\providers',
            null,
            ['providers' => $connections],
        );
    }

    public function getSetting(string $key = '')
    {
        $orgId = $this->orgId();
        $value = AdminServices::settings()->get($orgId, $key);

        // API clients get JSON; browsers get the bespoke single-setting view —
        // never the generic admin console / raw JSON.
        return $this->respondWith(
            Result::ok(['key' => $key, 'value' => $value]),
            'WBS\Admin\Views\setting_show',
            null,
            ['key' => $key, 'value' => $value],
        );
    }

    public function setSetting(string $key = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        $actor = $this->actorId();

        // The browser create form cannot put the (admin-typed) key in the URL
        // without JS (CSP forbids inline handlers), so it POSTs to /admin/settings
        // with the key as a body field; an existing row's edit form uses the URL
        // segment. URL segment wins when present.
        if ($key === '') {
            $key = trim((string) ($in['key'] ?? ''));
        }
        if ($key === '') {
            return $this->settingsResult(
                Result::fail('BAD_KEY', 'Admin.setting_key_required', 422),
            );
        }

        $value = $this->coerceValue($in['value'] ?? null, (string) ($in['type'] ?? 'string'));

        return $this->settingsResult(
            AdminServices::settings()->set($orgId, $key, $value, $actor),
        );
    }

    public function setFlag(string $flagKey = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        $actor = $this->actorId();

        if ($flagKey === '') {
            $flagKey = trim((string) ($in['flag_key'] ?? ''));
        }
        if ($flagKey === '') {
            return $this->settingsResult(
                Result::fail('BAD_FLAG', 'Admin.flag_key_required', 422),
            );
        }

        // An empty group_id means the ORG-WIDE default; a chosen group id scopes
        // the override. A checkbox absent from the POST reads as "off".
        $groupId = trim((string) ($in['group_id'] ?? ''));

        return $this->settingsResult(AdminServices::settings()->setFlag(
            $orgId,
            $flagKey,
            $this->truthy($in['enabled'] ?? false),
            $groupId === '' ? null : $groupId,
            $actor,
            isset($in['description']) && $in['description'] !== '' ? (string) $in['description'] : null,
        ));
    }

    public function setGroupConfig(string $groupId = '', string $capability = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        $actor = $this->actorId();

        // Browser create form: group + capability come as body fields (CSP-safe,
        // no URL-rewriting JS); the URL segments win when an edit form supplies
        // them.
        if ($groupId === '') {
            $groupId = trim((string) ($in['group_id'] ?? ''));
        }
        if ($capability === '') {
            $capability = trim((string) ($in['capability'] ?? ''));
        }
        if ($groupId === '' || $capability === '') {
            return $this->groupConfigResult(
                Result::fail('BAD_INPUT', 'Admin.group_config_input_required', 422),
                $groupId,
                $capability,
            );
        }

        $value = $this->coerceValue($in['value'] ?? null, (string) ($in['type'] ?? 'string'));

        return $this->groupConfigResult(
            AdminServices::effectiveConfig()->set(
                $orgId,
                $groupId,
                $capability,
                $value,
                (string) ($in['inheritance_mode'] ?? 'ancestor_default_child_override'),
                $actor,
            ),
            $groupId,
            $capability,
        );
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * PRG for the settings/flags console: a browser write redirects back to
     * /admin with a localized flash; API clients keep the JSON Result.
     */
    private function settingsResult(Result $result): mixed
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        return $result->ok
            ? redirect()->to('/admin')->with('success', (string) lang('Admin.settings.form.savedFlash'))
            : redirect()->to('/admin')->with('error', $this->errText((string) $result->message));
    }

    /**
     * PRG for a group-config write: on success redirect to the effective-config
     * resolution page for that group+capability so the admin sees the outcome;
     * on failure return to /admin with the error. API clients keep JSON.
     */
    private function groupConfigResult(Result $result, string $groupId, string $capability): mixed
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        if (! $result->ok) {
            return redirect()->to('/admin')->with('error', $this->errText((string) $result->message));
        }

        $to = '/admin/groups/' . rawurlencode($groupId) . '/config/' . rawurlencode($capability);

        return redirect()->to($to)->with('success', (string) lang('Admin.settings.form.savedFlash'));
    }

    /**
     * Coerce a form STRING into the requested typed value so settings keep the
     * type fidelity the service persists as JSON. Unknown/blank types pass the
     * raw string through; malformed json/number degrades to the raw string.
     */
    private function coerceValue(mixed $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }
        $s = (string) $raw;

        return match ($type) {
            'boolean' => $this->truthy($raw),
            'integer' => is_numeric($s) ? (int) $s : $s,
            'number'  => is_numeric($s) ? (float) $s : $s,
            'json'    => $this->decodeJsonOrRaw($s),
            default   => $raw,
        };
    }

    /** json_decode that returns the decoded value, or the raw string when invalid. */
    private function decodeJsonOrRaw(string $s): mixed
    {
        $trimmed = trim($s);
        if ($trimmed === '') {
            return $s;
        }
        $decoded = json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $s;
    }

    /** Normalize a checkbox/flag value ("1","true","on",true) to bool. */
    private function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Org group roster shaped for a <select> picker: [{id, label}] with the label
     * indented by depth so the hierarchy is scannable. Feature-flag scope and
     * group-config group are entity references → pickers, never free-text ids.
     *
     * @return list<array{id:string,label:string}>
     */
    private function groupPickerOptions(string $orgId): array
    {
        $rows = GroupServices::groups()->listForOrg($orgId, 500);
        $out  = [];
        foreach ($rows as $g) {
            $depth  = (int) ($g['depth'] ?? 0);
            $indent = str_repeat('— ', max(0, $depth));
            $out[]  = [
                'id'    => (string) ($g['id'] ?? ''),
                'label' => $indent . (string) ($g['name'] ?? ($g['id'] ?? '')),
            ];
        }

        return $out;
    }

    public function resolveGroupConfig(string $groupId = '', string $capability = '')
    {
        $res = AdminServices::effectiveConfig()->resolve($groupId, $capability);

        // API clients get JSON (admin-console path); browsers get the bespoke
        // effective-config resolution view — no raw JSON.
        if ($this->wantsJson()) {
            return $this->respondAdmin($res, 'Effective group config', $capability . ' @ ' . $groupId);
        }

        return $this->respondWith(
            $res,
            'WBS\Admin\Views\group_config',
            null,
            [
                'capability' => $capability,
                'group_id'   => $groupId,
                'config'     => is_array($res->data) ? $res->data : [],
                // Write control: set/override THIS group's value for the capability.
                'csrf'       => (string) ($this->request->wbsCsrf ?? ''),
                'types'      => self::VALUE_TYPES,
                'modes'      => self::INHERITANCE_MODES,
            ],
        );
    }
}
