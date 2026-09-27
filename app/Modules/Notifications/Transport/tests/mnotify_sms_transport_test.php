<?php

declare(strict_types=1);

/**
 * MNotifySmsTransport — real SMS delivery over mNotify's Quick Bulk SMS API.
 *
 * The `sms` channel previously had no transport, so NotificationDispatcher threw
 * "No transport for channel sms" on every SMS job. This proves the adapter that
 * fills that seam, against a RECORDING fake of ProviderHttp (no network):
 *
 *   • the request shape mNotify expects: POST /api/sms/quick, key in the QUERY
 *     STRING, JSON body {recipient:[…], sender, message, is_schedule, schedule_date};
 *   • phone normalization (+233/233/00233 → local 0XXXXXXXXX; foreign kept;
 *     separators dropped; garbage ⇒ rejected with NO HTTP call);
 *   • `sms_type: "otp"` is OPT-IN — sent for an explicit meta flag or an
 *     unmistakable OTP category, never for an ordinary blast (it costs extra);
 *   • mNotify's HTTP-200-with-error-body trap: only `status: success` /
 *     `code: 2000` is accepted, and `summary._id` becomes the campaign id;
 *   • outcome mapping — invalid recipient ⇒ rejected (no retry), bad key / no
 *     credit / 5xx / 429 / unconfigured ⇒ failed (retry + breaker);
 *   • scheduling, bulk recipients (one campaign, de-duplicated) and the
 *     delivery-report lookup.
 *
 *   php app/Modules/Notifications/Transport/tests/mnotify_sms_transport_test.php
 */

namespace WBS\Integrations\Providers {
    /**
     * Recording stand-in for the shared provider HTTP layer. The real class is
     * deliberately NOT required: this declares the same FQCN first so the
     * transport's type hint resolves to it (the pattern used across the
     * standalone suite for CodeIgniter\Database\BaseConnection).
     */
    class ProviderHttp
    {
        /** @var list<array{method:string,url:string,options:array<string,mixed>}> */
        public array $calls = [];

        /** @var array<string,mixed> canned JSON body to return */
        public array $response = ['status' => 'success', 'code' => '2000'];

        /** When set, request() throws this instead of returning. */
        public ?ProviderException $throw = null;

        public function __construct(private readonly string $provider = '') {}

        public function request(string $method, string $url, array $options = []): array
        {
            $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];
            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->response;
        }
    }

    /** Same shape as the real exception (message + httpStatus + provider). */
    class ProviderException extends \RuntimeException
    {
        public function __construct(
            string $message = '',
            public readonly int $httpStatus = 0,
            public readonly string $provider = '',
        ) {
            parent::__construct($message);
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Notifications/Transport/ChannelTransport.php';
require_once $root . '/app/Modules/Notifications/Transport/TransportMessage.php';
require_once $root . '/app/Modules/Notifications/Transport/TransportResult.php';
require_once $root . '/app/Modules/Notifications/Transport/MNotifySmsTransport.php';

use WBS\Integrations\Providers\ProviderException;
use WBS\Integrations\Providers\ProviderHttp;
use WBS\Notifications\Transport\MNotifySmsTransport;
use WBS\Notifications\Transport\TransportMessage;
use WBS\Notifications\Transport\TransportResult;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

/** A transport wired to a recording ProviderHttp. */
$make = static function (array $resp = [], string $key = 'KEY123', string $sender = 'WBS'): array {
    $http = new ProviderHttp('mnotify');
    if ($resp !== []) {
        $http->response = $resp;
    }
    $t = new MNotifySmsTransport($http, 'https://api.mnotify.com', $key, $sender, '233');

    return [$t, $http];
};

$msg = static function (
    string $recipient = '0241234567',
    string $body = 'Service starts at 9am.',
    string $category = 'event.reminder',
    array $meta = [],
): TransportMessage {
    return new TransportMessage(
        deliveryId: 'dlv-1',
        organizationId: 'org-1',
        channel: 'sms',
        category: $category,
        recipient: $recipient,
        body: $body,
        meta: $meta,
    );
};

$success = [
    'status'  => 'success',
    'code'    => '2000',
    'message' => 'messages sent successfully',
    'summary' => ['_id' => 'A59CCB70-662D-45EF-9976-1EFAD249793D', 'type' => 'API QUICK SMS', 'total_sent' => 1],
];

echo "1. channel + request shape mNotify expects\n";
[$t, $http] = $make($success);
$r = $t->deliver($msg());
chk('channel is sms', $t->channel() === 'sms');
chk('accepted', $r->outcome === TransportResult::ACCEPTED, $r->outcome . ' / ' . (string) $r->reason);
chk('campaign id captured as provider request id',
    $r->providerRequestId === 'A59CCB70-662D-45EF-9976-1EFAD249793D', (string) $r->providerRequestId);
$call = $http->calls[0];
chk('POSTs to /api/sms/quick', $call['method'] === 'POST' && $call['url'] === 'https://api.mnotify.com/api/sms/quick', $call['url']);
chk('API key rides in the QUERY STRING, not a header',
    ($call['options']['query']['key'] ?? null) === 'KEY123'
    && ! isset($call['options']['headers']['Authorization']),
    json_encode($call['options']['query'] ?? null));
chk('JSON content type', ($call['options']['headers']['Content-Type'] ?? null) === 'application/json');
$payload = $call['options']['json'];
chk('recipient is an ARRAY', is_array($payload['recipient']) && $payload['recipient'] === ['0241234567'], json_encode($payload['recipient']));
chk('sender id passed through', $payload['sender'] === 'WBS');
chk('message body passed through', $payload['message'] === 'Service starts at 9am.');
chk('not scheduled by default', $payload['is_schedule'] === false && $payload['schedule_date'] === '');

echo "2. phone number normalization\n";
chk('local Ghana number kept', MNotifySmsTransport::normalizePhone('0241234567') === '0241234567');
chk('+233 international → local 0 form', MNotifySmsTransport::normalizePhone('+233241234567') === '0241234567',
    (string) MNotifySmsTransport::normalizePhone('+233241234567'));
chk('233 without + → local 0 form', MNotifySmsTransport::normalizePhone('233241234567') === '0241234567');
chk('00 international prefix → local 0 form', MNotifySmsTransport::normalizePhone('00233241234567') === '0241234567');
chk('spaces / dashes dropped', MNotifySmsTransport::normalizePhone('024-123 4567') === '0241234567');
chk('foreign number kept in international digits', MNotifySmsTransport::normalizePhone('+14155551234') === '14155551234');
chk('too short ⇒ invalid', MNotifySmsTransport::normalizePhone('12345') === null);
chk('non-digits ⇒ invalid', MNotifySmsTransport::normalizePhone('call me') === null);

echo "3. an invalid recipient is REJECTED without any HTTP call\n";
[$t3, $http3] = $make($success);
$r3 = $t3->deliver($msg('not-a-number'));
chk('rejected (permanent)', $r3->outcome === TransportResult::REJECTED, $r3->outcome);
chk('no provider call made', $http3->calls === [], json_encode($http3->calls));

echo "4. sms_type=otp is opt-in (it costs extra per campaign)\n";
[$t4, $http4] = $make($success);
$t4->deliver($msg('0241234567', 'Your code is 1234', 'event.reminder'));
chk('ordinary blast carries NO sms_type', ! array_key_exists('sms_type', $http4->calls[0]['options']['json']),
    json_encode($http4->calls[0]['options']['json']));

[$t4b, $http4b] = $make($success);
$t4b->deliver($msg('0241234567', 'Your code is 1234', 'auth.mfa.otp'));
chk('OTP category sets sms_type=otp', ($http4b->calls[0]['options']['json']['sms_type'] ?? null) === 'otp');

[$t4c, $http4c] = $make($success);
$t4c->deliver($msg('0241234567', 'Your code is 1234', 'event.reminder', ['sms_type' => 'otp']));
chk('explicit meta sms_type=otp honoured', ($http4c->calls[0]['options']['json']['sms_type'] ?? null) === 'otp');

[$t4d, $http4d] = $make($success);
$t4d->deliver($msg('0241234567', 'Your code is 1234', 'auth.mfa.otp', ['sms_type' => 'normal']));
chk('explicit non-otp type overrides the category',
    ! array_key_exists('sms_type', $http4d->calls[0]['options']['json']));

echo "5. HTTP 200 with an ERROR body is not a success\n";
[$t5, $http5] = $make(['status' => 'error', 'code' => '4006', 'message' => 'Invalid API key']);
$r5 = $t5->deliver($msg());
chk('bad key ⇒ retryable failure (fix env, replay)', $r5->outcome === TransportResult::FAILED, $r5->outcome);
chk('failure carries the provider reason', str_contains((string) $r5->reason, 'Invalid API key'), (string) $r5->reason);

[$t5b] = $make(['status' => 'error', 'code' => '4002', 'message' => 'Invalid recipient number']);
$r5b = $t5b->deliver($msg());
chk('invalid recipient ⇒ permanent rejection', $r5b->outcome === TransportResult::REJECTED, $r5b->outcome);

[$t5c] = $make(['status' => 'error', 'code' => '4007', 'message' => 'Insufficient credit']);
$r5c = $t5c->deliver($msg());
chk('no credit ⇒ retryable (top up then replay)', $r5c->outcome === TransportResult::FAILED, $r5c->outcome);

echo "6. unconfigured transport fails loudly, never claims sent\n";
[$t6, $http6] = $make($success, '');
$r6 = $t6->deliver($msg());
chk('missing API key ⇒ failed', $r6->outcome === TransportResult::FAILED, $r6->outcome);
chk('no HTTP call attempted', $http6->calls === []);

echo "7. transport faults map onto retryable vs permanent\n";
foreach ([
    ['500 provider blew up', 500, TransportResult::FAILED],
    ['429 rate limited', 429, TransportResult::FAILED],
    ['408 timeout', 408, TransportResult::FAILED],
    ['401 unauthorized key', 401, TransportResult::FAILED],
    ['400 bad request', 400, TransportResult::REJECTED],
] as [$label, $status, $expected]) {
    [$tt, $hh] = $make($success);
    $hh->throw = new ProviderException("HTTP {$status}: nope", $status, 'mnotify');
    $rr = $tt->deliver($msg());
    chk($label . ' ⇒ ' . $expected, $rr->outcome === $expected, $rr->outcome);
}

echo "8. scheduling uses mNotify's datetime format\n";
[$t8, $http8] = $make($success);
$when = date('Y-m-d H:i:s', time() + 86400);
$t8->deliver($msg('0241234567', 'Reminder', 'event.reminder', ['schedule_date' => $when]));
$p8 = $http8->calls[0]['options']['json'];
chk('is_schedule true', $p8['is_schedule'] === true);
chk('schedule_date formatted Y-m-d H:i:s', $p8['schedule_date'] === $when, $p8['schedule_date']);

[$t8b, $http8b] = $make($success);
$t8b->deliver($msg('0241234567', 'Late', 'event.reminder', ['send_at' => '2020-01-01 00:00:00']));
$p8b = $http8b->calls[0]['options']['json'];
chk('a past send_at is ignored (sends now)', $p8b['is_schedule'] === false && $p8b['schedule_date'] === '');

echo "9. a blast is ONE campaign, de-duplicated\n";
[$t9, $http9] = $make($success);
$t9->deliver($msg('+233241234567', 'Bulk', 'campaign.broadcast', [
    'recipients' => ['0241234567', '020 123 4567', '233241234567', 'call me'],
]));
$p9 = $http9->calls[0]['options']['json'];
chk('one HTTP call for the whole blast', count($http9->calls) === 1);
chk('all valid numbers in one recipient array, deduped',
    $p9['recipient'] === ['0241234567', '0201234567'], json_encode($p9['recipient']));

echo "10. delivery report lookup (status of an earlier campaign)\n";
[$t10, $http10] = $make(['status' => 'success', 'report' => ['_id' => 60711577, 'status' => 'DELIVERED']]);
$rep = $t10->deliveryReport('60711577');
chk('GETs /api/status/{id}', $http10->calls[0]['method'] === 'GET'
    && $http10->calls[0]['url'] === 'https://api.mnotify.com/api/status/60711577', $http10->calls[0]['url']);
chk('key in the query string', ($http10->calls[0]['options']['query']['key'] ?? null) === 'KEY123');
chk('report returned', ($rep['report']['status'] ?? null) === 'DELIVERED');
[$t10b, $http10b] = $make($success, '');
chk('unconfigured ⇒ null, no call', $t10b->deliveryReport('60711577') === null && $http10b->calls === []);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
