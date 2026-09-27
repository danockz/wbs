<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

use WBS\Integrations\Canonical\Canonical;

/**
 * Declarative manifest a custom adapter MUST publish (SRS FR-INT-013).
 *
 * The manifest is the entire contract between a custom-adapter MODULE and the
 * core platform: it names the adapter's machine code + version, its category and
 * protocol family, the exact set of canonical operations it genuinely supports,
 * the credential slots it needs, and the approved hosts it is allowed to reach.
 *
 * Because everything a custom adapter can do is DECLARED here (and validated
 * against the finite canonical vocabulary), onboarding a conforming adapter is
 * pure configuration data — the generated no-code catalogue/UI, the fallback
 * matrix, and the credential vault all read from this manifest, so NO core
 * business module changes when a new adapter is added.
 *
 * This is a value object: immutable, self-validating, and (de)serialisable to
 * the JSON stored in `custom_adapters.manifest` / published to the catalogue.
 */
final class AdapterManifest
{
    /**
     * @param list<string>         $capabilities     canonical ops the adapter supports (⊆ Canonical::OPERATIONS)
     * @param list<string>         $credentialFields credential slot names the adapter reads via the vault
     * @param list<string>         $approvedHosts    HTTPS hosts the adapter may reach (egress allowlist)
     * @param array<string,mixed>  $configSchema     non-secret config the connection supplies
     */
    public function __construct(
        public readonly string $code,
        public readonly int $version,
        public readonly string $category,
        public readonly string $family,
        public readonly string $displayName,
        public readonly array $capabilities,
        public readonly array $credentialFields = [],
        public readonly array $approvedHosts = [],
        public readonly array $configSchema = [],
        public readonly ?string $docsRef = null,
    ) {
    }

    /**
     * Build a manifest from a decoded JSON/array shape, coercing types.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $strList = static function (mixed $v): array {
            if (! is_array($v)) {
                return [];
            }

            return array_values(array_filter(array_map(
                static fn ($x): string => is_string($x) ? $x : '',
                $v,
            ), static fn (string $s): bool => $s !== ''));
        };

        return new self(
            code: (string) ($data['code'] ?? ''),
            version: (int) ($data['version'] ?? 1),
            category: (string) ($data['category'] ?? ''),
            family: (string) ($data['family'] ?? ''),
            displayName: (string) ($data['display_name'] ?? $data['displayName'] ?? ''),
            capabilities: $strList($data['capabilities'] ?? []),
            credentialFields: $strList($data['credential_fields'] ?? $data['credentialFields'] ?? []),
            approvedHosts: $strList($data['approved_hosts'] ?? $data['approvedHosts'] ?? []),
            configSchema: is_array($data['config_schema'] ?? $data['configSchema'] ?? null) ? (array) ($data['config_schema'] ?? $data['configSchema']) : [],
            docsRef: isset($data['docs_ref']) ? (string) $data['docs_ref'] : (isset($data['docsRef']) ? (string) $data['docsRef'] : null),
        );
    }

    /**
     * Validate the manifest against the finite canonical vocabulary and the
     * safety rules (HTTPS-only allowlist, no IP literals, sane code/version).
     *
     * @return list<string> validation errors (empty = valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (! preg_match('/^[a-z0-9][a-z0-9_]{1,79}$/', $this->code)) {
            $errors[] = 'code must be lower_snake, 2-80 chars, e.g. "acme_pay_v1"';
        }
        if ($this->version < 1) {
            $errors[] = 'version must be a positive integer';
        }
        if ($this->displayName === '' || mb_strlen($this->displayName) > 150) {
            $errors[] = 'display_name is required (<=150 chars)';
        }
        if (! in_array($this->category, Canonical::CATEGORIES, true)) {
            $errors[] = 'category not in approved set';
        }
        if (! Canonical::isFamily($this->family)) {
            $errors[] = 'family not an approved UPAF family';
        }
        if ($this->capabilities === []) {
            $errors[] = 'at least one canonical capability must be declared';
        }
        foreach ($this->capabilities as $cap) {
            if (! Canonical::isOperation($cap)) {
                $errors[] = "capability '{$cap}' is not a canonical operation";
            }
        }
        // healthCheck is mandatory: the platform must be able to probe any adapter.
        if (! in_array('healthCheck', $this->capabilities, true)) {
            $errors[] = 'capabilities must include "healthCheck"';
        }
        // Duplicate capabilities are a manifest smell.
        if (count($this->capabilities) !== count(array_unique($this->capabilities))) {
            $errors[] = 'capabilities contains duplicates';
        }
        foreach ($this->approvedHosts as $host) {
            if (! $this->isSafeHost($host)) {
                $errors[] = "approved host '{$host}' must be a bare HTTPS-capable hostname (no scheme, path, IP literal, or localhost)";
            }
        }

        return $errors;
    }

    public function isValid(): bool
    {
        return $this->validate() === [];
    }

    public function supports(string $op): bool
    {
        return in_array($op, $this->capabilities, true);
    }

    /**
     * Canonical serialisation used for signing AND for publishing to the
     * catalogue. Deterministic key order so a signature is reproducible.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'code'              => $this->code,
            'version'           => $this->version,
            'category'          => $this->category,
            'family'            => $this->family,
            'display_name'      => $this->displayName,
            'capabilities'      => $this->capabilities,
            'credential_fields' => $this->credentialFields,
            'approved_hosts'    => $this->approvedHosts,
            'config_schema'     => $this->configSchema,
            'docs_ref'          => $this->docsRef,
        ];
    }

    /** Deterministic JSON (sorted keys) — the exact bytes that get signed. */
    public function canonicalJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '';
    }

    private function isSafeHost(string $host): bool
    {
        if ($host === '' || mb_strlen($host) > 253) {
            return false;
        }
        // No scheme, path, port, wildcard, whitespace or credentials.
        if (preg_match('#[/:@\s*]#', $host) === 1) {
            return false;
        }
        // Reject IP literals (SSRF) and loopback/metadata names.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        $lower = strtolower($host);
        if (in_array($lower, ['localhost', 'metadata.google.internal'], true)) {
            return false;
        }

        return preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*)(\.[a-z0-9](-?[a-z0-9])*)+$/i', $host) === 1;
    }
}
