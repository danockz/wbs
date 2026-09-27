<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

use WBS\Integrations\Providers\ProviderException;
use WBS\Integrations\Providers\ProviderHttp;

/**
 * SMS channel transport over Nalo Solutions' reseller SMS API — the SECOND
 * provider in the `sms` chain (mNotify is primary; see {@see SmsProviderChain}).
 *
 *   POST https://api.nalosolutions.com/smsbackend/clientapi/ResellerAPI/send_sms/
 *   (form) username=…&password=…&message=…&sender_id=…&recipients=233244000111
 *
 * Same seam as {@see MNotifySmsTransport}: the dispatcher hands over an
 * already-rendered {@see TransportMessage} and gets back a {@see TransportResult}
 * — accepted, rejected (permanent, no retry, NO failover) or failed (transient;
 * the chain hands the message to the next provider and the circuit breaker sees a
 * fault).
 *
 * Nalo specifics this adapter respects:
 *
 *  - Credentials are a reseller USERNAME + PASSWORD (some accounts are issued a
 *    single API/auth key instead — when one is configured it is sent as
 *    `auth_key` and the username/password pair is omitted).
 *  - The body is `application/x-www-form-urlencoded`, not JSON, and `recipients`
 *    is a COMMA-SEPARATED string (one call still carries a whole blast, so a
 *    campaign stays one provider call and one charge).
 *  - Numbers go in INTERNATIONAL form with no `+` and no trunk `0`
 *    (`233244000111`) — the opposite of mNotify's local `0XXXXXXXXX` shape, so
 *    this class normalizes independently rather than sharing mNotify's helper.
 *  - `sender_id` must be pre-approved in the Nalo portal and is at most 11
 *    characters, so it is truncated defensively instead of being rejected.
 *  - Like mNotify, Nalo can answer HTTP 200 with an error body, so acceptance is
 *    decided by the BODY, permissively: a success-ish `status`, or an id field
 *    with no error text, counts as accepted (deployments vary in envelope shape).
 *
 * The base URL and path are constructor-injected (env `NALO_SMS_API_BASE_URL` /
 * `NALO_SMS_API_PATH`) and every outbound field name lives in {@see payload()},
 * so an account whose reseller endpoint differs is a configuration change, not a
 * code change.
 *
 * Not configured is a TRANSIENT failure, and {@see isConfigured()} lets the chain
 * skip this provider entirely rather than burning an attempt on it.
 */
final class NaloSmsTransport implements ChannelTransport
{
    /** Body statuses that mean "the provider took the message". */
    private const OK_STATUSES = ['success', 'ok', 'sent', 'accepted', '1', 'true', '200'];

    /**
     * Body conditions that can never succeed on retry → permanent rejection (and
     * deliberately NO failover: a second provider would refuse the same number).
     * Narrow on purpose — bad credentials or no credit must stay RETRYABLE.
     */
    private const PERMANENT_PATTERNS = [
        'invalid recipient', 'invalid number', 'invalid msisdn', 'wrong number',
        'invalid sender', 'sender id not', 'sender not registered', 'sender_id not',
        'message is empty', 'empty message', 'message too long',
    ];

    /** Nalo sender IDs are pre-approved and at most 11 characters. */
    private const SENDER_ID_MAX = 11;

    public function __construct(
        private readonly ProviderHttp $http,
        private readonly string $apiBaseUrl = 'https://api.nalosolutions.com',
        private readonly string $apiPath = '/smsbackend/clientapi/ResellerAPI/send_sms/',
        private readonly string $username = '',
        private readonly string $password = '',
        /** Single-key accounts: sent as `auth_key` instead of username/password. */
        private readonly string $authKey = '',
        private readonly string $senderId = '',
        /** Country code used to lift a local trunk number into international form. */
        private readonly string $defaultCountryCode = '233',
    ) {
    }

    public function channel(): string
    {
        return 'sms';
    }

    /** Provider name, so the chain can report which hop did what. */
    public function provider(): string
    {
        return 'nalo';
    }

    /**
     * Whether this provider can even be attempted. The chain skips an
     * unconfigured provider instead of counting it as a failure — but when NO
     * provider is configured the chain still fails loudly (never "sent").
     */
    public function isConfigured(): bool
    {
        return $this->authKey !== '' || ($this->username !== '' && $this->password !== '');
    }

    public function deliver(TransportMessage $message): TransportResult
    {
        $recipients = $this->recipientList($message);
        if ($recipients === []) {
            // A bad/absent number can never be fixed by retrying — or by another
            // provider, so the chain will not failover on this either.
            return TransportResult::rejected('invalid or missing recipient phone number');
        }

        $body = trim($message->body);
        if ($body === '') {
            return TransportResult::rejected('empty SMS body');
        }

        if (! $this->isConfigured()) {
            // Misconfiguration is transient: fix env, the queue retries.
            return TransportResult::failed('nalo sms transport not configured (missing credentials)');
        }

        try {
            $resp = $this->http->request('POST', $this->endpoint(), [
                'form'    => $this->payload($recipients, $body, $message),
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (ProviderException $e) {
            return $this->classifyFailure($e);
        }

        return $this->classifyBody($resp);
    }

    // ---------------------------------------------------------------- helpers

    private function endpoint(): string
    {
        $path = $this->apiPath === '' ? '/smsbackend/clientapi/ResellerAPI/send_sms/' : $this->apiPath;

        return rtrim($this->apiBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * The outbound form fields — the ONE place Nalo's field names live, so an
     * account whose reseller API differs is a small edit (or an env override of
     * the base/path) rather than a hunt through the adapter.
     *
     * @param list<string> $recipients normalized international numbers
     * @return array<string,string>
     */
    private function payload(array $recipients, string $body, TransportMessage $message): array
    {
        $form = ['message' => $body, 'recipients' => implode(',', $recipients)];

        $sender = trim($this->senderId);
        if ($sender !== '') {
            $form['sender_id'] = mb_substr($sender, 0, self::SENDER_ID_MAX);
        }

        if ($this->authKey !== '') {
            $form['auth_key'] = $this->authKey;
        } else {
            $form['username'] = $this->username;
            $form['password'] = $this->password;
        }

        // Scheduling is opt-in (same rule as mNotify): only when the caller asked
        // for a future send. A past/unparsable value sends now.
        $at = $this->scheduleDate($message);
        if ($at !== null) {
            $form['is_schedule']   = '1';
            $form['schedule_date'] = $at;
        }

        return $form;
    }

    /**
     * Every number this message should reach: its own recipient plus any explicit
     * `meta['recipients']` blast list, normalized and de-duplicated.
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
     * Normalize a human-entered phone number to the INTERNATIONAL digits Nalo
     * wants: no `+`, no trunk `0` (a Ghana number is `233XXXXXXXXX`).
     *
     * Rules: drop separators and a leading `+`; fold an `00` international prefix
     * away; lift a trunk-prefixed local number (`0` + national digits, whatever
     * their length) into `cc` + those digits; otherwise keep the digits as given
     * (foreign numbers pass through). Null when it cannot be a phone number at all
     * (E.164 allows 15 digits max; fewer than 7 is nonsense) or when a trunk-form
     * number arrives with no country code to lift it with.
     *
     * @return string|null normalized digits, or null when invalid
     */
    public static function normalizePhone(string $input, string $defaultCountryCode = '233'): ?string
    {
        $digits = preg_replace('/\D+/', '', ltrim(trim($input), '+')) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $cc = preg_replace('/\D+/', '', $defaultCountryCode) ?? '';

        // A trunk-prefixed LOCAL number (0 + national digits) is lifted into
        // international form. The rule is the trunk prefix, not a fixed length:
        // Ghana is 0 + 9, Nigeria 0 + 10 — and Nalo must never receive the
        // leading 0. Without a country code to lift it with, fail closed rather
        // than hand the provider a number it will silently misroute.
        if (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
            if ($cc === '' || strlen($national) < 7 || strlen($national) > 15) {
                return null;
            }

            return $cc . $national;
        }

        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    /** A future send time, when the caller asked for one ("Y-m-d H:i:s"). */
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
     * Classify the response body. Nalo, like mNotify, can report errors with HTTP
     * 200, and its envelope shape varies between account types — so acceptance is
     * read PERMISSIVELY: a success-ish status, or a message/campaign id with no
     * error text. Anything unrecognisable is a transient failure (retry), never a
     * claimed success.
     *
     * @param array<string,mixed> $resp
     */
    private function classifyBody(array $resp): TransportResult
    {
        $status = strtolower(trim((string) ($resp['status'] ?? $resp['Status'] ?? '')));
        $reason = trim((string) ($resp['message'] ?? $resp['Message']
            ?? ($resp['error'] ?? $resp['ErrorMessage'] ?? '')));
        $id     = $this->messageId($resp);

        if (in_array($status, self::OK_STATUSES, true)) {
            return TransportResult::accepted($id);
        }

        // No status field at all: an id with no error text means the send landed.
        if ($status === '' && $id !== null && $reason === '') {
            return TransportResult::accepted($id);
        }

        if ($status !== '' || $reason !== '') {
            return $this->isPermanent($reason !== '' ? $reason : $status)
                ? TransportResult::rejected($reason !== '' ? $reason : $status)
                : TransportResult::failed('provider: ' . ($reason !== '' ? $reason : $status));
        }

        return TransportResult::failed('unrecognised provider response');
    }

    /** The provider's handle for this send, under whichever key it arrives. */
    private function messageId(array $resp): ?string
    {
        foreach (['message_id', 'MessageId', 'messageId', 'id', '_id', 'campaign_id'] as $k) {
            if (isset($resp[$k]) && is_scalar($resp[$k]) && trim((string) $resp[$k]) !== '') {
                return trim((string) $resp[$k]);
            }
        }
        $data = $resp['data'] ?? null;
        if (is_array($data)) {
            return $this->messageId($data);
        }
        if (is_scalar($data) && trim((string) $data) !== '') {
            return trim((string) $data);
        }

        return null;
    }

    /**
     * Map a provider HTTP error onto retryable vs permanent, mirroring the other
     * transports: 4xx is permanent EXCEPT 408 (timeout) and 429 (rate limit).
     * 401/403 (bad credentials) and 402 (no credit) stay retryable so the breaker
     * trips and the queue replays once the account is fixed.
     */
    private function classifyFailure(ProviderException $e): TransportResult
    {
        $status = $e->httpStatus;
        $msg    = $e->getMessage();

        if ($status === 401 || $status === 402 || $status === 403) {
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
