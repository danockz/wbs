<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

use SensitiveParameter;
use Throwable;

/**
 * Opt-in network {@see BreachChecker}: the HaveIBeenPwned "Pwned Passwords"
 * range API using k-anonymity (SRS FR-ID-003, Tier-1 switchable option).
 *
 * PRIVACY: only the FIRST 5 hex chars of the SHA-1 of the password are sent to
 * the API; the server returns every suffix in that bucket and the match is done
 * locally. The full password (and its full hash) never leave the process.
 *
 * FAIL-OPEN: any network/timeout/parse error returns false (not breached) so a
 * transient outage can never lock legitimate users out of changing a password.
 * Optionally chains to a local fallback checker so the offline corpus still
 * applies when the API is unreachable.
 *
 * The HTTP call is injected as a callable so this class stays testable and free
 * of a hard framework HTTP dependency: `fn(string $url): ?string` returns the
 * response body, or null on failure.
 */
final class PwnedPasswordsBreachChecker implements BreachChecker
{
    /** @var callable(string):?string */
    private $fetch;

    /**
     * @param callable(string):?string|null $fetch    URL fetcher (default: file_get_contents with a short timeout)
     * @param BreachChecker|null            $fallback consulted when the API call fails (fail-open to offline corpus)
     * @param string                        $endpoint range API base (override for a self-hosted mirror)
     */
    public function __construct(
        ?callable $fetch = null,
        private readonly ?BreachChecker $fallback = null,
        private readonly string $endpoint = 'https://api.pwnedpasswords.com/range/',
        private readonly int $timeoutSeconds = 3,
    ) {
        $this->fetch = $fetch ?? function (string $url): ?string {
            $ctx = stream_context_create(['http' => [
                'method'  => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header'  => "Add-Padding: true\r\nUser-Agent: WBS-Platform-BreachCheck\r\n",
            ]]);
            $body = @file_get_contents($url, false, $ctx);

            return $body === false ? null : $body;
        };
    }

    public function isBreached(#[SensitiveParameter] string $plain): bool
    {
        if ($plain === '') {
            return false;
        }

        try {
            $sha1   = strtoupper(sha1($plain));
            $prefix = substr($sha1, 0, 5);
            $suffix = substr($sha1, 5);

            $body = ($this->fetch)($this->endpoint . $prefix);
            if ($body === null || $body === '') {
                return $this->fallbackBreached($plain);
            }

            foreach (preg_split('/\r\n|\n/', $body) ?: [] as $line) {
                // Each line: "<SUFFIX>:<count>". Padding rows have count 0.
                $parts = explode(':', trim($line), 2);
                if (($parts[0] ?? '') === $suffix && (int) ($parts[1] ?? 0) > 0) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return $this->fallbackBreached($plain);
        }
    }

    private function fallbackBreached(#[SensitiveParameter] string $plain): bool
    {
        return $this->fallback !== null && $this->fallback->isBreached($plain);
    }
}
