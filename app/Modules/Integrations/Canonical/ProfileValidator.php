<?php

declare(strict_types=1);

namespace WBS\Integrations\Canonical;

use WBS\Shared\Support\Result;

/**
 * Server-side connector-profile validator — the UPAF safety boundary
 * (SRS FR-INT-003/009).
 *
 * A profile is REJECTED if it:
 *  - references an unknown canonical operation / protocol family / HTTP method /
 *    signature algorithm;
 *  - targets a non-HTTPS URL, an IP literal, or a host outside the approved
 *    allowlist (SSRF / egress control);
 *  - contains anything resembling executable code (PHP/JS/shell tags, arbitrary
 *    expressions) in any string field;
 *  - supplies raw/arbitrary HTTP headers or a free-form request template.
 *
 * Profiles are configuration data ONLY.
 */
final class ProfileValidator
{
    /** Hosts profiles are allowed to talk to (exact or suffix match). */
    private array $approvedHosts;

    /** @param list<string> $approvedHosts */
    public function __construct(array $approvedHosts = [])
    {
        $this->approvedHosts = $approvedHosts;
    }

    /** Patterns that indicate attempted code/expression injection. */
    private const CODE_SIGNATURES = [
        '<?php', '<?=', '<script', '${', '#{', '{{', '`', 'eval(', 'system(', 'exec(',
        'passthru(', 'shell_exec', 'base64_decode(', 'file_get_contents(', 'curl_',
    ];

    /**
     * @param array<string,mixed> $profile
     */
    public function validate(array $profile): Result
    {
        $errors = [];

        // 1. Canonical operation.
        $op = (string) ($profile['canonical_op'] ?? '');
        if (! Canonical::isOperation($op)) {
            $errors[] = 'canonical_op not in approved set';
        }

        // 2. Protocol family.
        $family = (string) ($profile['family'] ?? '');
        if (! Canonical::isFamily($family)) {
            $errors[] = 'family not an approved UPAF family';
        }

        // 3. HTTP method (if present).
        if (isset($profile['http_method']) && $profile['http_method'] !== '' && ! Canonical::isHttpMethod((string) $profile['http_method'])) {
            $errors[] = 'http_method not allowlisted';
        }

        // 4. Signature algorithm (if present).
        if (isset($profile['signature_algo']) && $profile['signature_algo'] !== '' && ! Canonical::isSignatureAlgo((string) $profile['signature_algo'])) {
            $errors[] = 'signature_algo not in reviewed set';
        }

        // 5. Approved host + TLS-only + no IP literals.
        $host = (string) ($profile['approved_host'] ?? '');
        $hostError = $this->validateHost($host);
        if ($hostError !== null) {
            $errors[] = $hostError;
        }

        // 6. No arbitrary headers / free-form request template.
        if (isset($profile['request_mapping']) && is_array($profile['request_mapping'])) {
            if (isset($profile['request_mapping']['headers']) || isset($profile['request_mapping']['raw']) || isset($profile['request_mapping']['template'])) {
                $errors[] = 'arbitrary headers/raw request templates are prohibited';
            }
        }

        // 7. No embedded code in ANY string field (deep scan).
        if ($this->containsCode($profile)) {
            $errors[] = 'profile contains code-like content (prohibited)';
        }

        if ($errors !== []) {
            return Result::fail('PROFILE_INVALID', 'integration.profile_invalid', 422, $errors);
        }

        return Result::ok(['valid' => true]);
    }

    private function validateHost(string $host): ?string
    {
        if ($host === '') {
            return 'approved_host is required';
        }

        // Accept either a bare host or an https URL; reject non-HTTPS URLs.
        if (str_contains($host, '://')) {
            if (! str_starts_with($host, 'https://')) {
                return 'approved_host must be HTTPS';
            }
            $parsed = parse_url($host);
            $host   = $parsed['host'] ?? '';
        }

        // Reject IP literals (SSRF hardening).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return 'approved_host must not be an IP literal';
        }
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) {
            return 'approved_host is not a valid domain';
        }

        // Must match the deployed allowlist (exact or subdomain suffix).
        if ($this->approvedHosts !== [] && ! $this->hostAllowed($host)) {
            return 'approved_host is not in the deployment allowlist';
        }

        return null;
    }

    private function hostAllowed(string $host): bool
    {
        $host = strtolower($host);
        foreach ($this->approvedHosts as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function containsCode(mixed $value): bool
    {
        if (is_string($value)) {
            $lower = strtolower($value);
            foreach (self::CODE_SIGNATURES as $sig) {
                if (str_contains($lower, strtolower($sig))) {
                    return true;
                }
            }

            return false;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && $this->containsCode($k)) {
                    return true;
                }
                if ($this->containsCode($v)) {
                    return true;
                }
            }
        }

        return false;
    }
}
