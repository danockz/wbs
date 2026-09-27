<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

use CodeIgniter\HTTP\CURLRequest;
use Config\Services;
use Throwable;

/**
 * Thin, testable HTTP layer for provider adapters (adaptation of the source
 * spec's inline httpRequest()).
 *
 *  - Wraps CI4's CURLRequest with sane timeouts and no exception-on-HTTP-error
 *    (we inspect the status ourselves).
 *  - Surfaces the provider's OWN error body, not a bare "HTTP 400", via
 *    {@see ProviderException}.
 *  - Returns the decoded JSON body as an array on success.
 *
 * Auth headers are passed in by the adapter (built inside a CredentialVault
 * closure); this class never reads or stores credentials itself.
 */
class ProviderHttp
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT         = 15;

    public function __construct(
        private readonly string $provider = '',
    ) {
    }

    /**
     * Perform a request and return the decoded JSON body.
     *
     * @param array<string,mixed> $options
     *   - headers: array<string,string>
     *   - json:    array<string,mixed>   (sent as a JSON body)
     *   - form:    array<string,mixed>   (sent as application/x-www-form-urlencoded)
     *   - query:   array<string,string>
     * @return array<string,mixed>
     *
     * @throws ProviderException on transport failure or a non-2xx status.
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $client = $this->client();

        $opts = [
            'http_errors'     => false,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout'         => self::TIMEOUT,
            'headers'         => $options['headers'] ?? [],
        ];
        if (isset($options['query'])) {
            $opts['query'] = $options['query'];
        }
        if (isset($options['json'])) {
            $opts['json'] = $options['json'];
        }
        if (isset($options['form'])) {
            $opts['form_params'] = $options['form'];
        }

        try {
            $response = $client->request(strtoupper($method), $url, $opts);
        } catch (Throwable $e) {
            throw new ProviderException(
                'transport error: ' . $e->getMessage(),
                0,
                $this->provider,
            );
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = $body !== '' ? json_decode($body, true) : [];
        if (! is_array($data)) {
            $data = ['raw' => $body];
        }

        if ($status < 200 || $status >= 300) {
            throw new ProviderException(
                $this->extractError($data, $status),
                $status,
                $this->provider,
            );
        }

        return $data;
    }

    /** Overridable for tests. */
    protected function client(): CURLRequest
    {
        return Services::curlrequest([], null, null, false);
    }

    /**
     * Pull the most useful human message out of a provider error body. Different
     * platforms nest their message differently; try the common shapes.
     *
     * @param array<string,mixed> $data
     */
    private function extractError(array $data, int $status): string
    {
        $candidates = [
            $data['error']['message'] ?? null,          // Google/YouTube, Facebook
            $data['error_description'] ?? null,          // OAuth token endpoints
            is_string($data['error'] ?? null) ? $data['error'] : null,
            $data['message'] ?? null,                    // Twitch, generic
            $data['raw'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return "HTTP {$status}: {$c}";
            }
        }

        return "HTTP {$status}: request failed";
    }
}
