<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

use WBS\Integrations\Providers\ProviderException;
use WBS\Integrations\Providers\ProviderHttp;

/**
 * Reference {@see ChannelTransport}: real email delivery over a transactional
 * email provider's HTTP API (SendGrid/Mailgun/Postmark/SES-style JSON send).
 *
 * This is the concrete implementation that replaces the previous "mark sent
 * without ever calling a transport" stub in the dispatch handler. It is also the
 * template every other channel adapter follows:
 *
 *  - HTTP goes through the shared {@see ProviderHttp} (5s connect / 15s timeout,
 *    surfaces the provider's own error body), never a bare curl.
 *  - The API key is app-level config injected from env — the vendor endpoint
 *    and sender identity are not per-group secrets, so they come from env rather
 *    than the per-connection CredentialVault (which is for per-group OAuth).
 *  - Provider outcomes are mapped onto {@see TransportResult} so the dispatcher
 *    can retry transient faults but permanently fail bad recipients:
 *      • malformed/empty recipient      → rejected (no HTTP call)
 *      • 2xx                            → accepted (+ provider message id)
 *      • 4xx (except 408/429)           → rejected (permanent; won't retry)
 *      • 408/429/5xx/transport error    → failed  (retryable; trips breaker)
 *
 * Not configured (no base URL / key) is treated as a transient failure so a
 * misconfigured environment retries rather than silently dropping mail — the
 * old stub's "always sent" lie is gone either way.
 */
final class EmailTransport implements ChannelTransport
{
    public function __construct(
        private readonly ProviderHttp $http,
        private readonly string $apiBaseUrl,
        private readonly string $apiKey,
        private readonly string $fromAddress,
        private readonly string $fromName = '',
    ) {
    }

    public function channel(): string
    {
        return 'email';
    }

    public function deliver(TransportMessage $message): TransportResult
    {
        $to = trim($message->recipient);
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            // Bad address: retrying can never help — permanent, not a provider fault.
            return TransportResult::rejected('invalid recipient email');
        }

        if ($this->apiBaseUrl === '' || $this->apiKey === '') {
            // Misconfiguration is transient (fix env + retry), never a silent success.
            return TransportResult::failed('email transport not configured');
        }

        $payload = [
            'from'    => $this->fromName !== ''
                ? ['email' => $this->fromAddress, 'name' => $this->fromName]
                : ['email' => $this->fromAddress],
            'to'      => [['email' => $to]],
            'subject' => $message->subject ?? $this->defaultSubject($message),
            'content' => [[
                'type'  => 'text/html',
                'value' => $message->body,
            ]],
            // Provider-side idempotency: a redelivered dispatch job must not send twice.
            'custom_args' => ['delivery_id' => $message->deliveryId],
        ];

        try {
            $resp = $this->http->request('POST', rtrim($this->apiBaseUrl, '/') . '/mail/send', [
                'headers' => [
                    'Authorization'   => 'Bearer ' . $this->apiKey,
                    'Content-Type'    => 'application/json',
                    'Idempotency-Key' => $message->deliveryId,
                ],
                'json' => $payload,
            ]);
        } catch (ProviderException $e) {
            return $this->classifyFailure($e);
        }

        // Providers return their message handle under varying keys.
        $providerId = null;
        foreach (['message_id', 'id', 'messageId'] as $k) {
            if (isset($resp[$k]) && is_scalar($resp[$k])) {
                $providerId = (string) $resp[$k];
                break;
            }
        }
        if ($providerId === null && isset($resp['raw']) && is_string($resp['raw'])) {
            $providerId = null; // accepted but no id surfaced; that's fine
        }

        return TransportResult::accepted($providerId);
    }

    /**
     * Map a provider HTTP error onto retryable vs permanent. 4xx is a permanent
     * per-message rejection (bad payload/address/plan) EXCEPT 408 (timeout) and
     * 429 (rate limit), which are transient. Everything else (5xx, transport)
     * is a retryable provider fault.
     */
    private function classifyFailure(ProviderException $e): TransportResult
    {
        $status = $e->httpStatus;

        if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
            return TransportResult::rejected($e->getMessage());
        }

        return TransportResult::failed($e->getMessage());
    }

    private function defaultSubject(TransportMessage $message): string
    {
        // A readable fallback when a caller didn't pass an explicit subject.
        return ucwords(str_replace(['_', '.'], ' ', $message->category));
    }
}
