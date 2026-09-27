<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

use WBS\Integrations\Providers\ProviderException;
use WBS\Integrations\Providers\ProviderHttp;

/**
 * SMS channel transport over the mNotify/BMS "Quick Bulk SMS" REST API.
 *
 *   POST https://api.mnotify.com/api/sms/quick?key=<API_KEY>
 *   {"recipient":["0241234567"],"sender":"WBS","message":"…",
 *    "is_schedule":false,"schedule_date":""}
 *
 * Follows the same seam as {@see EmailTransport}: the dispatcher hands over an
 * already-rendered {@see TransportMessage} and gets back a {@see TransportResult}
 * — accepted (delivery recorded + provider campaign id stored), rejected
 * (permanent, no retry) or failed (transient, the queue retries and the circuit
 * breaker sees a fault).
 *
 * mNotify specifics this adapter has to respect:
 *
 *  - The API key travels in the QUERY STRING (`?key=`), not a bearer header.
 *  - The provider answers **HTTP 200 with an error body** for several conditions
 *    (bad key, no credit, bad recipient), so a 2xx is NOT sufficient: the body's
 *    `status`/`code` decides acceptance. `code` "2000" / `status` "success" is
 *    the only accepted shape; `summary._id` is the campaign id (also the handle
 *    for the delivery-report endpoint).
 *  - `recipient` is an ARRAY — one call can carry a whole blast. We send the
 *    message's own recipient plus any `meta['recipients']` list so a campaign
 *    stays one provider call (and one charge) instead of N.
 *  - `sms_type: "otp"` costs extra (0.035/campaign off the main wallet) and must
 *    NOT be sent for ordinary blasts, so it is opt-in: only when
 *    `meta['sms_type'] === 'otp'` or the category is unmistakably a one-time
 *    code (otp/mfa/verification). Never inferred from message content.
 *  - Numbers are normalized to the form mNotify expects: a Ghana number in
 *    international shape (+233/233 + 9 digits) becomes local `0XXXXXXXXX`;
 *    anything already local or foreign is sent as digits (leading `+` dropped).
 *
 * Not configured (no key) is a TRANSIENT failure so a misconfigured environment
 * retries loudly instead of silently marking SMS "sent".
 */
final class MNotifySmsTransport implements ChannelTransport
{
    /** Provider's own success code (also seen as `status: "success"`). */
    private const CODE_OK = '2000';

    /**
     * Body conditions that can never succeed on retry → permanent rejection.
     * Deliberately narrow: an absent-credit or bad-key message must stay
     * RETRYABLE (fix the account, the queue replays), so neither appears here.
     */
    private const PERMANENT_PATTERNS = [
        'invalid recipient', 'invalid number', 'invalid msisdn', 'wrong number',
        'invalid sender', 'sender id not', 'sender not registered',
        'message is empty', 'empty message', 'message too long',
    ];

    /** Categories that unmistakably carry a one-time code (→ `sms_type: otp`). */
    private const OTP_CATEGORY_HINTS = ['otp', 'mfa', '2fa', 'verification', 'verify'];

    public function __construct(
        private readonly ProviderHttp $http,
        private readonly string $apiBaseUrl = 'https://api.mnotify.com',
        private readonly string $apiKey = '',
        private readonly string $senderId = '',
        /** Country code stripped/rewritten when normalizing local numbers. */
        private readonly string $defaultCountryCode = '233',
    ) {
    }

    public function channel(): string
    {
        return 'sms';
    }

    /** Provider name, so {@see SmsProviderChain} can report which hop did what. */
    public function provider(): string
    {
        return 'mnotify';
    }

    /**
     * Whether this provider can even be attempted. The chain skips an
     * unconfigured provider instead of burning a hop on it; when NO provider is
     * configured the chain still fails loudly rather than reporting "sent".
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function deliver(TransportMessage $message): TransportResult
    {
        $recipients = $this->recipientList($message);
        if ($recipients === []) {
            // A bad/absent number can never be fixed by retrying.
            return TransportResult::rejected('invalid or missing recipient phone number');
        }

        $body = trim($message->body);
        if ($body === '') {
            return TransportResult::rejected('empty SMS body');
        }

        if ($this->apiKey === '') {
            // Misconfiguration is transient: fix env, the queue retries.
            return TransportResult::failed('sms transport not configured (missing API key)');
        }

        $scheduleDate = $this->scheduleDate($message);

        $payload = [
            'recipient'     => $recipients,
            'sender'        => $this->senderId,
            'message'       => $body,
            'is_schedule'   => $scheduleDate !== null,
            'schedule_date' => $scheduleDate ?? '',
        ];
        if ($this->isOtp($message)) {
            // Extra charge — only ever sent when the caller/category says OTP.
            $payload['sms_type'] = 'otp';
        }

        try {
            $resp = $this->http->request('POST', $this->endpoint(), [
                // mNotify authenticates with `?key=`, not a header.
                'query'   => ['key' => $this->apiKey],
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'json'    => $payload,
            ]);
        } catch (ProviderException $e) {
            return $this->classifyFailure($e);
        }

        return $this->classifyBody($resp);
    }

    /**
     * Look up a previously-sent campaign's per-recipient delivery status.
     *
     *   GET https://api.mnotify.com/api/status/{campaignId}?key=…
     *
     * Returns the provider's report row(s), or null when unknown/unconfigured —
     * the delivery-report sweep uses this to move a "sent" delivery to
     * "delivered"/"failed" without re-sending anything.
     *
     * @return array<string,mixed>|null
     */
    public function deliveryReport(string $campaignId): ?array
    {
        $campaignId = trim($campaignId);
        if ($campaignId === '' || $this->apiKey === '') {
            return null;
        }

        try {
            $resp = $this->http->request(
                'GET',
                rtrim($this->apiBaseUrl, '/') . '/api/status/' . rawurlencode($campaignId),
                [
                    'query'   => ['key' => $this->apiKey],
                    'headers' => ['Accept' => 'application/json'],
                ],
            );
        } catch (ProviderException) {
            return null;
        }

        return $resp === [] ? null : $resp;
    }

    // ---------------------------------------------------------------- helpers

    private function endpoint(): string
    {
        return rtrim($this->apiBaseUrl, '/') . '/api/sms/quick';
    }

    /**
     * Every number this message should reach: its own recipient plus any
     * explicit `meta['recipients']` blast list, normalized and de-duplicated.
     *
     * @return list<string>
     */
    private function recipientList(TransportMessage $message): array
    {
        $raw = [$message->recipient];
        $extra = $message->meta['recipients'] ?? null;
        if (is_array($extra)) {
            foreach ($extra as $e) {
                if (is_scalar($e)) {
                    $raw[] = (string) $e;
                }
            }
        } elseif (is_string($extra) && $extra !== '') {
            // Tolerate a comma/semicolon-separated string.
            foreach (preg_split('/[;,]/', $extra) ?: [] as $e) {
                $raw[] = $e;
            }
        }

        // A caller may also pass several numbers in the recipient field itself.
        $flat = [];
        foreach ($raw as $r) {
            foreach (preg_split('/[;,]/', $r) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $flat[] = $part;
                }
            }
        }

        $out = [];
        foreach ($flat as $number) {
            $msisdn = self::normalizePhone($number, $this->defaultCountryCode);
            if ($msisdn !== null) {
                $out[$msisdn] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Normalize a human-entered phone number to the digits mNotify wants.
     *
     * Rules: drop separators and a leading `+`; rewrite an international
     * `defaultCountryCode` + 9 digits into the local `0` + 9 form (mNotify's own
     * samples use `0241234567`); otherwise keep the digits as given (foreign
     * numbers go through in international shape). Null when it cannot be a phone
     * number at all — E.164 allows 15 digits max, and fewer than 7 is nonsense.
     *
     * @return string|null normalized digits, or null when invalid
     */
    public static function normalizePhone(string $input, string $defaultCountryCode = '233'): ?string
    {
        $digits = preg_replace('/\D+/', '', ltrim(trim($input), '+')) ?? '';
        $digits = ltrim($digits, '+');
        if ($digits === '') {
            return null;
        }
        // A trunk-prefixed local number is already what the provider wants.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $cc = preg_replace('/\D+/', '', $defaultCountryCode) ?? '';
        if ($cc !== '' && str_starts_with($digits, $cc) && strlen($digits) === strlen($cc) + 9) {
            return '0' . substr($digits, strlen($cc));
        }

        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    /**
     * Opt-in OTP flag. mNotify charges extra for `sms_type: "otp"` and asks that
     * it NOT be sent for ordinary blasts, so it requires an explicit
     * `meta['sms_type']`/`meta['otp']` or an unmistakable OTP category — the
     * message text is never inspected.
     */
    private function isOtp(TransportMessage $message): bool
    {
        $type = strtolower(trim((string) ($message->meta['sms_type'] ?? '')));
        if ($type === 'otp') {
            return true;
        }
        if ($type !== '' && $type !== 'otp') {
            return false; // an explicit non-otp type wins
        }
        if (! empty($message->meta['otp'])) {
            return true;
        }

        $category = strtolower($message->category);

        foreach (self::OTP_CATEGORY_HINTS as $hint) {
            if (str_contains($category, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A future send time, when the caller asked for one. mNotify wants
     * "Y-m-d H:i:s"; a past/unparsable value is ignored (send now) rather than
     * scheduling something that can never fire.
     */
    private function scheduleDate(TransportMessage $message): ?string
    {
        $raw = $message->meta['schedule_date'] ?? $message->meta['send_at'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $ts = strtotime(trim($raw));
        if ($ts === false || $ts <= time()) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Classify a 2xx body. mNotify reports many errors with HTTP 200, so the
     * body — not the status line — decides.
     *
     * @param array<string,mixed> $resp
     */
    private function classifyBody(array $resp): TransportResult
    {
        $status = strtolower(trim((string) ($resp['status'] ?? '')));
        $code   = trim((string) ($resp['code'] ?? ''));

        $ok = $status === 'success' || $code === self::CODE_OK;
        if ($ok) {
            return TransportResult::accepted($this->campaignId($resp));
        }

        // Some deployments return only a `message` with no status/code; treat an
        // explicitly non-success status as an error and fall through otherwise.
        $reason = trim((string) ($resp['message'] ?? ($resp['error'] ?? 'provider returned no success status')));
        if ($status !== '' || $code !== '') {
            return $this->isPermanent($reason)
                ? TransportResult::rejected("HTTP {$code}: {$reason}")
                : TransportResult::failed("provider: {$reason} (code {$code})");
        }

        // No recognisable envelope at all: don't claim success, let it retry once.
        return TransportResult::failed('unrecognised provider response: ' . $reason);
    }

    /** The campaign id mNotify returns under `summary._id` (also `id`/`_id`). */
    private function campaignId(array $resp): ?string
    {
        $summary = $resp['summary'] ?? null;
        if (is_array($summary)) {
            foreach (['_id', 'id', 'campaign_id', 'message_id'] as $k) {
                if (isset($summary[$k]) && is_scalar($summary[$k]) && (string) $summary[$k] !== '') {
                    return (string) $summary[$k];
                }
            }
        }
        foreach (['_id', 'id', 'message_id'] as $k) {
            if (isset($resp[$k]) && is_scalar($resp[$k]) && (string) $resp[$k] !== '') {
                return (string) $resp[$k];
            }
        }

        return null;
    }

    /**
     * Map a provider HTTP error onto retryable vs permanent, mirroring
     * EmailTransport: 4xx is permanent EXCEPT 408 (timeout) and 429 (rate
     * limit). 401/403 (bad key) and 402 (no credit) stay retryable so the
     * breaker trips and the queue replays once the account is fixed.
     */
    private function classifyFailure(ProviderException $e): TransportResult
    {
        $status = $e->httpStatus;
        $msg    = $e->getMessage();

        if ($status === 401 || $status === 403 || $status === 402) {
            return TransportResult::failed($msg);
        }
        if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
            return TransportResult::rejected($msg);
        }

        return TransportResult::failed($msg);
    }

    /** Does this provider message describe a per-recipient/permanent problem? */
    private function isPermanent(string $reason): bool
    {
        $low = strtolower($reason);
        foreach (self::PERMANENT_PATTERNS as $p) {
            if (str_contains($low, $p)) {
                return true;
            }
        }

        return false;
    }
}
