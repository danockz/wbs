<?php

declare(strict_types=1);

/**
 * NaloSmsTransport + SmsProviderChain — the SMS FALLBACK path.
 *
 * mNotify was the platform's only SMS provider, so one aggregator outage meant no
 * SMS at all. This proves the second provider and the chain that fronts them,
 * against a RECORDING fake of ProviderHttp (no network):
 *
 *   • Nalo's request shape: form-encoded POST to the reseller endpoint, reseller
 *     username/password (or a single auth_key), comma-separated recipients,
 *     sender_id truncated to the 11 characters Nalo approves;
 *   • phone normalization to INTERNATIONAL digits (233XXXXXXXXX — no "+", no
 *     trunk 0), which is the opposite of mNotify's local form, so it is pinned
 *     separately; garbage ⇒ rejected with NO HTTP call;
 *   • Nalo's HTTP-200-with-error-body trap, read PERMISSIVELY (envelope shape
 *     varies by account): a success-ish status, or an id with no error text, is
 *     accepted; anything unrecognisable is a transient failure, never "sent";
 *   • outcome mapping — bad number/sender ⇒ rejected (no retry), 5xx/429/401/402
 *     /unconfigured ⇒ failed (retry + breaker), other 4xx ⇒ rejected;
 *   • the CHAIN rule that matters: fail over on a provider FAULT, never on a
 *     permanent rejection (that would just send an undeliverable message
 *     somewhere else), skip unconfigured providers, and fail loudly when none is
 *     configured;
 *   • registry wiring: the `sms` channel resolves to the chain, mNotify first,
 *     order overridable by SMS_PROVIDER_ORDER, each factory credential-aware;
 *   • PER-GROUP credentials: the chain sends on the account the sending body
 *     provided or was granted (its own sender id, its own secrets, its own
 *     provider order), skips a provider that body has no usable credential for,
 *     and REFUSES — rather than borrows another body's account — when it has
 *     none at all.
 *
 *   php app/Modules/Notifications/Transport/tests/nalo_sms_chain_test.php
 */

namespace WBS\Integrations\Providers {
    /**
     * Recording stand-in for the shared provider HTTP layer. The real class is
     * deliberately NOT required: this declares the same FQCN first so the
     * transports' type hints resolve to it (the pattern used across the standalone
     * suite for CodeIgniter\Database\BaseConnection).
     */
    class ProviderHttp
    {
        /** @var list<array{method:string,url:string,options:array<string,mixed>}> */
        public array $calls = [];

        /** @var array<string,mixed> canned JSON body to return */
        public array $response = ['status' => 'success'];

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
require_once $root . '/app/Modules/Notifications/Transport/NaloSmsTransport.php';
require_once $root . '/app/Modules/Notifications/Transport/MNotifySmsTransport.php';
require_once $root . '/app/Modules/Notifications/Services/ResolvedCredential.php';
require_once $root . '/app/Modules/Notifications/Services/GroupCredentialSource.php';
require_once $root . '/app/Modules/Notifications/Transport/SmsProviderChain.php';

use WBS\Integrations\Providers\ProviderException;
use WBS\Integrations\Providers\ProviderHttp;
use WBS\Notifications\Services\GroupCredentialSource;
use WBS\Notifications\Services\ResolvedCredential;
use WBS\Notifications\Transport\ChannelTransport;
use WBS\Notifications\Transport\MNotifySmsTransport;
use WBS\Notifications\Transport\NaloSmsTransport;
use WBS\Notifications\Transport\SmsProviderChain;
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

/** Build a Nalo transport over a recording HTTP fake. */
$make = static function (array $response = ['status' => 'success'], string $user = 'reseller', string $pass_ = 'secret', string $authKey = ''): array {
    $http = new ProviderHttp('nalo');
    $http->response = $response;
    $t = new NaloSmsTransport(
        $http,
        'https://api.nalosolutions.com',
        '/smsbackend/clientapi/ResellerAPI/send_sms/',
        $user,
        $pass_,
        $authKey,
        'WBS',
        '233',
    );

    return [$t, $http];
};

$msg = static function (string $recipient = '0244000111', string $body = 'Hello Esi', string $category = 'event.reminder', array $meta = []): TransportMessage {
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

/** A stub provider for chain semantics: canned outcome, counts its attempts. */
$stub = static function (string $name, TransportResult $result, bool $configured = true): object {
    return new class ($name, $result, $configured) implements ChannelTransport {
        public int $attempts = 0;

        public function __construct(
            public readonly string $name,
            private readonly TransportResult $result,
            private readonly bool $configured,
        ) {
        }

        public function channel(): string { return 'sms'; }

        public function isConfigured(): bool { return $this->configured; }

        public function deliver(TransportMessage $message): TransportResult
        {
            $this->attempts++;

            return $this->result;
        }
    };
};

// ══════════════════════════════════════════════════════════════════════════
echo "1. the request Nalo's reseller API expects\n";
[$t, $http] = $make();
$r = $t->deliver($msg());
chk('accepted', $r->outcome === TransportResult::ACCEPTED, $r->outcome . ' ' . (string) $r->reason);
chk('one POST', count($http->calls) === 1 && $http->calls[0]['method'] === 'POST');
chk('to the reseller send_sms endpoint',
    $http->calls[0]['url'] === 'https://api.nalosolutions.com/smsbackend/clientapi/ResellerAPI/send_sms/',
    $http->calls[0]['url']);
chk('form-encoded, not JSON', isset($http->calls[0]['options']['form']) && ! isset($http->calls[0]['options']['json']));
$form = $http->calls[0]['options']['form'];
chk('carries reseller username + password', ($form['username'] ?? '') === 'reseller' && ($form['password'] ?? '') === 'secret');
chk('carries the rendered body', ($form['message'] ?? '') === 'Hello Esi');
chk('recipients is a comma-separated string', ($form['recipients'] ?? '') === '233244000111', json_encode($form['recipients'] ?? null));
chk('sender_id included', ($form['sender_id'] ?? '') === 'WBS');
chk('no scheduling fields for an immediate send',
    ! isset($form['is_schedule']) && ! isset($form['schedule_date']));
chk('accepts JSON', ($http->calls[0]['options']['headers']['Accept'] ?? '') === 'application/json');
chk('channel is sms', $t->channel() === 'sms' && $t->provider() === 'nalo');

echo "2. a single-key account sends auth_key instead\n";
[$t2, $http2] = $make(['status' => 'success'], '', '', 'AUTHKEY-1');
$t2->deliver($msg());
$f2 = $http2->calls[0]['options']['form'];
chk('auth_key sent', ($f2['auth_key'] ?? '') === 'AUTHKEY-1');
chk('username/password omitted', ! isset($f2['username']) && ! isset($f2['password']));
chk('isConfigured true on a key alone', $t2->isConfigured());

echo "3. sender IDs are truncated to what Nalo approves (11 chars)\n";
[$t3, $http3] = $make();
$t3b = new NaloSmsTransport($http3, 'https://api.nalosolutions.com', '/smsbackend/clientapi/ResellerAPI/send_sms/',
    'u', 'p', '', 'WINBUILDSENDLONG', '233');
$t3b->deliver($msg());
chk('truncated', ($http3->calls[0]['options']['form']['sender_id'] ?? '') === 'WINBUILDSEN',
    (string) ($http3->calls[0]['options']['form']['sender_id'] ?? ''));
$unused = $t3->channel();
chk('transport still reports its channel', $unused === 'sms');

echo "4. phone normalization — INTERNATIONAL digits (no +, no trunk 0)\n";
$norm = static fn (string $in): ?string => NaloSmsTransport::normalizePhone($in);
chk('local trunk form lifted to 233…', $norm('0244000111') === '233244000111', (string) $norm('0244000111'));
chk('+233 international kept', $norm('+233 244 000 111') === '233244000111', (string) $norm('+233 244 000 111'));
chk('233 without + kept', $norm('233244000111') === '233244000111');
chk('00 international prefix folded', $norm('00233244000111') === '233244000111', (string) $norm('00233244000111'));
chk('separators dropped', $norm('024-400-0111') === '233244000111');
chk('a foreign number passes through', $norm('+1 (555) 123-4567') === '15551234567', (string) $norm('+1 (555) 123-4567'));
chk('too short ⇒ null', $norm('123') === null);
chk('too long ⇒ null', $norm('1234567890123456') === null);
chk('non-numeric ⇒ null', $norm('ask me') === null);
chk('empty ⇒ null', $norm('') === null);
chk('a different default country code is honoured',
    NaloSmsTransport::normalizePhone('08031234567', '234') === '2348031234567',
    (string) NaloSmsTransport::normalizePhone('08031234567', '234'));

echo "5. bad recipients and empty bodies never reach the network\n";
[$t5, $http5] = $make();
$r5 = $t5->deliver($msg('ask me'));
chk('garbage recipient ⇒ rejected', $r5->outcome === TransportResult::REJECTED, $r5->outcome);
chk('and NO HTTP call', $http5->calls === []);
[$t5b, $http5b] = $make();
$r5b = $t5b->deliver($msg('0244000111', '   '));
chk('empty body ⇒ rejected', $r5b->outcome === TransportResult::REJECTED);
chk('and NO HTTP call', $http5b->calls === []);

echo "6. a blast is ONE call, comma-joined and de-duplicated\n";
[$t6, $http6] = $make();
$t6->deliver($msg('+233244000111', 'Bulk', 'campaign.broadcast', [
    'recipients' => ['0244000111', '020 123 4567', '233244000111', 'call me'],
]));
chk('one HTTP call', count($http6->calls) === 1);
chk('valid numbers joined, deduped, international',
    ($http6->calls[0]['options']['form']['recipients'] ?? '') === '233244000111,233201234567',
    (string) ($http6->calls[0]['options']['form']['recipients'] ?? ''));
[$t6b, $http6b] = $make();
$t6b->deliver($msg('0244000111', 'Bulk', 'campaign.broadcast', ['recipients' => '0201234567;0277654321']));
chk('a separated string list is accepted too',
    ($http6b->calls[0]['options']['form']['recipients'] ?? '') === '233244000111,233201234567,233277654321',
    (string) ($http6b->calls[0]['options']['form']['recipients'] ?? ''));

echo "7. scheduling is opt-in and uses Nalo's datetime format\n";
[$t7, $http7] = $make();
$when = date('Y-m-d H:i:s', time() + 86400);
$t7->deliver($msg('0244000111', 'Reminder', 'event.reminder', ['schedule_date' => $when]));
$f7 = $http7->calls[0]['options']['form'];
chk('is_schedule=1 + schedule_date', ($f7['is_schedule'] ?? '') === '1' && ($f7['schedule_date'] ?? '') === $when, json_encode($f7));
[$t7b, $http7b] = $make();
$t7b->deliver($msg('0244000111', 'Late', 'event.reminder', ['send_at' => '2020-01-01 00:00:00']));
chk('a past send_at is ignored (sends now)',
    ! isset($http7b->calls[0]['options']['form']['is_schedule']));

echo "8. HTTP 200 with an error body — the envelope decides, permissively\n";
$cases = [
    'status success'                    => [['status' => 'success'], TransportResult::ACCEPTED],
    'status SUCCESS (case-insensitive)' => [['status' => 'SUCCESS'], TransportResult::ACCEPTED],
    'status ok'                         => [['status' => 'ok'], TransportResult::ACCEPTED],
    'status 1'                          => [['status' => '1'], TransportResult::ACCEPTED],
    'status 200'                        => [['status' => '200'], TransportResult::ACCEPTED],
    'id only, no status, no error'      => [['message_id' => 'NALO-77'], TransportResult::ACCEPTED],
    'nested data id'                    => [['data' => ['id' => 'NALO-88']], TransportResult::ACCEPTED],
    'invalid recipient ⇒ rejected'      => [['status' => 'error', 'message' => 'Invalid recipient number'], TransportResult::REJECTED],
    'sender id not approved ⇒ rejected' => [['status' => 'error', 'message' => 'Sender ID not registered'], TransportResult::REJECTED],
    'message too long ⇒ rejected'       => [['status' => 'failed', 'message' => 'Message too long'], TransportResult::REJECTED],
    'insufficient credit ⇒ failed'      => [['status' => 'error', 'message' => 'Insufficient credit'], TransportResult::FAILED],
    'invalid credentials ⇒ failed'      => [['status' => 'error', 'message' => 'Invalid username or password'], TransportResult::FAILED],
    'error field only ⇒ failed'         => [['status' => 'error', 'error' => 'rate limited'], TransportResult::FAILED],
    'unrecognisable body ⇒ failed'      => [[], TransportResult::FAILED],
];
foreach ($cases as $label => [$body, $expected]) {
    [$tt, $hh] = $make($body);
    $rr = $tt->deliver($msg());
    chk($label . ' ⇒ ' . $expected, $rr->outcome === $expected, $rr->outcome . ' / ' . (string) $rr->reason);
}
[$tid, $httpid] = $make(['status' => 'success', 'message_id' => 'NALO-77']);
$rid = $tid->deliver($msg());
chk('the provider message id becomes the request id', $rid->providerRequestId === 'NALO-77', (string) $rid->providerRequestId);

echo "9. HTTP errors: retryable vs permanent\n";
$httpCases = [
    '500 provider fault'      => [500, TransportResult::FAILED],
    '502 bad gateway'         => [502, TransportResult::FAILED],
    '503 unavailable'         => [503, TransportResult::FAILED],
    '408 timeout'             => [408, TransportResult::FAILED],
    '429 rate limited'        => [429, TransportResult::FAILED],
    '401 bad credentials'     => [401, TransportResult::FAILED],
    '402 no credit'           => [402, TransportResult::FAILED],
    '403 forbidden'           => [403, TransportResult::FAILED],
    '400 bad request'         => [400, TransportResult::REJECTED],
    '404 unknown endpoint'    => [404, TransportResult::REJECTED],
    '422 unprocessable'       => [422, TransportResult::REJECTED],
];
foreach ($httpCases as $label => [$status, $expected]) {
    [$tt, $hh] = $make();
    $hh->throw = new ProviderException('HTTP ' . $status, $status, 'nalo');
    $rr = $tt->deliver($msg());
    chk($label . ' ⇒ ' . $expected, $rr->outcome === $expected, $rr->outcome);
}

echo "10. unconfigured is transient (and skippable by the chain)\n";
[$tu, $hu] = $make(['status' => 'success'], '', '');
chk('isConfigured false without credentials', $tu->isConfigured() === false);
$ru = $tu->deliver($msg());
chk('unconfigured ⇒ failed, not rejected', $ru->outcome === TransportResult::FAILED, $ru->outcome);
chk('and NO HTTP call', $hu->calls === []);
[$tu2, $hu2] = $make(['status' => 'success'], 'reseller', '');
chk('a username without a password is still unconfigured', $tu2->isConfigured() === false);

echo "11. the endpoint is configurable (reseller accounts differ)\n";
$httpc = new ProviderHttp('nalo');
$tc = new NaloSmsTransport($httpc, 'https://sms.example.gh/', 'v2/send', 'u', 'p', '', 'WBS', '233');
$tc->deliver($msg());
chk('base + path joined once, no double slash', $httpc->calls[0]['url'] === 'https://sms.example.gh/v2/send', $httpc->calls[0]['url']);
$httpd = new ProviderHttp('nalo');
$td = new NaloSmsTransport($httpd, 'https://sms.example.gh', '', 'u', 'p', '', 'WBS', '233');
$td->deliver($msg());
chk('an empty path falls back to the documented reseller path',
    $httpd->calls[0]['url'] === 'https://sms.example.gh/smsbackend/clientapi/ResellerAPI/send_sms/', $httpd->calls[0]['url']);

// ══════════════════════════════════════════════════════════════════════════
echo "12. the chain: order\n";
$chain = new SmsProviderChain([
    'mnotify' => static fn () => $stub('mnotify', TransportResult::accepted('M-1')),
    'nalo'    => static fn () => $stub('nalo', TransportResult::accepted('N-1')),
], SmsProviderChain::parseOrder('mnotify,nalo'));
chk('channel is sms', $chain->channel() === 'sms');
chk('order follows the env list', $chain->order() === ['mnotify', 'nalo'], json_encode($chain->order()));
chk('parseOrder tolerates spaces, case and dupes',
    SmsProviderChain::parseOrder(' Nalo , MNOTIFY,nalo ') === ['nalo', 'mnotify'],
    json_encode(SmsProviderChain::parseOrder(' Nalo , MNOTIFY,nalo ')));
chk('parseOrder of an empty value is empty (registration order stands)', SmsProviderChain::parseOrder('') === []);
chk('parseOrder of null is empty', SmsProviderChain::parseOrder(null) === []);
$rev = new SmsProviderChain([
    'mnotify' => static fn () => $stub('mnotify', TransportResult::accepted('M-1')),
    'nalo'    => static fn () => $stub('nalo', TransportResult::accepted('N-1')),
], SmsProviderChain::parseOrder('nalo,mnotify'));
chk('the order is overridable (Nalo primary)', $rev->order() === ['nalo', 'mnotify'], json_encode($rev->order()));
$typo = new SmsProviderChain([
    'mnotify' => static fn () => $stub('mnotify', TransportResult::accepted('M-1')),
    'nalo'    => static fn () => $stub('nalo', TransportResult::accepted('N-1')),
], SmsProviderChain::parseOrder('mnotifyy'));
chk('an unknown name is ignored', $typo->order() === ['mnotify', 'nalo'], json_encode($typo->order()));
$omit = new SmsProviderChain([
    'mnotify' => static fn () => $stub('mnotify', TransportResult::accepted('M-1')),
    'nalo'    => static fn () => $stub('nalo', TransportResult::accepted('N-1')),
], SmsProviderChain::parseOrder('nalo'));
chk('an omitted provider is DEMOTED, never dropped', $omit->order() === ['nalo', 'mnotify'], json_encode($omit->order()));

echo "13. the chain: accepted stops the walk\n";
$primary   = $stub('mnotify', TransportResult::accepted('M-1'));
$fallback  = $stub('nalo', TransportResult::accepted('N-1'));
$chain13   = new SmsProviderChain(['mnotify' => static fn () => $primary, 'nalo' => static fn () => $fallback], ['mnotify', 'nalo']);
$r13       = $chain13->deliver($msg());
chk('accepted from the primary', $r13->outcome === TransportResult::ACCEPTED && $r13->providerRequestId === 'M-1', json_encode([$r13->outcome, $r13->providerRequestId]));
chk('the primary was attempted once', $primary->attempts === 1);
chk('the fallback was NEVER attempted (no double send, no double charge)', $fallback->attempts === 0);

echo "14. the chain: a provider FAULT fails over\n";
$primary  = $stub('mnotify', TransportResult::failed('HTTP 503'));
$fallback = $stub('nalo', TransportResult::accepted('N-1'));
$chain14  = new SmsProviderChain(['mnotify' => static fn () => $primary, 'nalo' => static fn () => $fallback], ['mnotify', 'nalo']);
$r14      = $chain14->deliver($msg());
chk('delivered by the fallback', $r14->outcome === TransportResult::ACCEPTED && $r14->providerRequestId === 'N-1', json_encode([$r14->outcome, $r14->providerRequestId]));
chk('both were attempted, in order', $primary->attempts === 1 && $fallback->attempts === 1);

echo "15. the chain: a permanent REJECTION does NOT fail over\n";
$primary  = $stub('mnotify', TransportResult::rejected('invalid recipient number'));
$fallback = $stub('nalo', TransportResult::accepted('N-1'));
$chain15  = new SmsProviderChain(['mnotify' => static fn () => $primary, 'nalo' => static fn () => $fallback], ['mnotify', 'nalo']);
$r15      = $chain15->deliver($msg('ask me'));
chk('rejected, not retried elsewhere', $r15->outcome === TransportResult::REJECTED, $r15->outcome);
chk('the rejection reason survives', str_contains((string) $r15->reason, 'invalid recipient'), (string) $r15->reason);
chk('the fallback was NEVER attempted', $fallback->attempts === 0);

echo "16. the chain: every provider faulting is a failure\n";
$primary  = $stub('mnotify', TransportResult::failed('HTTP 500'));
$fallback = $stub('nalo', TransportResult::failed('HTTP 502'));
$chain16  = new SmsProviderChain(['mnotify' => static fn () => $primary, 'nalo' => static fn () => $fallback], ['mnotify', 'nalo']);
$r16      = $chain16->deliver($msg());
chk('failed (the queue retries)', $r16->outcome === TransportResult::FAILED, $r16->outcome);
chk('both were attempted', $primary->attempts === 1 && $fallback->attempts === 1);
chk('the reason names BOTH hops', str_contains((string) $r16->reason, 'mnotify') && str_contains((string) $r16->reason, 'nalo'), (string) $r16->reason);

echo "17. the chain: unconfigured providers are skipped\n";
$primary  = $stub('mnotify', TransportResult::failed('not configured'), false);
$fallback = $stub('nalo', TransportResult::accepted('N-1'));
$chain17  = new SmsProviderChain(['mnotify' => static fn () => $primary, 'nalo' => static fn () => $fallback], ['mnotify', 'nalo']);
$r17      = $chain17->deliver($msg());
chk('the configured fallback delivers', $r17->outcome === TransportResult::ACCEPTED && $r17->providerRequestId === 'N-1', json_encode([$r17->outcome, $r17->reason]));
chk('the unconfigured primary was never called', $primary->attempts === 0);

echo "18. the chain: nothing configured fails loudly (never 'sent')\n";
$p18 = $stub('mnotify', TransportResult::failed('x'), false);
$p18b = $stub('nalo', TransportResult::failed('x'), false);
$chain18 = new SmsProviderChain(['mnotify' => static fn () => $p18, 'nalo' => static fn () => $p18b], ['mnotify', 'nalo']);
$r18 = $chain18->deliver($msg());
chk('failed', $r18->outcome === TransportResult::FAILED, $r18->outcome);
chk('says no provider is configured', str_contains((string) $r18->reason, 'no sms provider configured'), (string) $r18->reason);
chk('and it is NOT a rejection (so the queue retries)', ! $r18->isRejected());

echo "19. the chain over the REAL transports (mNotify 503 ⇒ Nalo delivers)\n";
$httpM = new ProviderHttp('mnotify');
$httpM->throw = new ProviderException('HTTP 503', 503, 'mnotify');
$httpN = new ProviderHttp('nalo');
$httpN->response = ['status' => 'success', 'message_id' => 'NALO-99'];
$real = new SmsProviderChain([
    'mnotify' => static fn (): ChannelTransport => new MNotifySmsTransport($httpM, 'https://api.mnotify.com', 'KEY-M', 'WBS', '233'),
    'nalo'    => static fn (): ChannelTransport => new NaloSmsTransport($httpN, 'https://api.nalosolutions.com', '/smsbackend/clientapi/ResellerAPI/send_sms/', 'u', 'p', '', 'WBS', '233'),
], ['mnotify', 'nalo']);
$r19 = $real->deliver($msg('0244000111'));
chk('accepted via Nalo', $r19->outcome === TransportResult::ACCEPTED && $r19->providerRequestId === 'NALO-99', json_encode([$r19->outcome, $r19->providerRequestId, $r19->reason]));
chk('mNotify was tried first', count($httpM->calls) === 1);
chk('Nalo got INTERNATIONAL digits while mNotify got LOCAL ones',
    ($httpN->calls[0]['options']['form']['recipients'] ?? '') === '233244000111'
    && ($httpM->calls[0]['options']['json']['recipient'][0] ?? '') === '0244000111',
    json_encode([$httpN->calls[0]['options']['form']['recipients'] ?? null, $httpM->calls[0]['options']['json']['recipient'] ?? null]));

echo "20. registry wiring: the sms channel is the chain, mNotify first\n";
$svc = (string) file_get_contents($root . '/app/Modules/Notifications/Config/Services.php');
chk("the 'sms' factory builds an SmsProviderChain", str_contains($svc, "'sms' => static fn (): ChannelTransport => new SmsProviderChain("));
$at = strpos($svc, "'sms' => static fn (): ChannelTransport => new SmsProviderChain(");
$block = $at === false ? '' : substr($svc, $at, 6000);
$factorySig = 'static function (?ResolvedCredential $cred = null, array $secrets = []): ChannelTransport {';
chk('mnotify is registered', str_contains($block, "'mnotify' => " . $factorySig));
chk('nalo is registered', str_contains($block, "'nalo' => " . $factorySig));
chk('mNotify is listed BEFORE Nalo',
    strpos($block, "'mnotify' =>") < strpos($block, "'nalo' =>"));
chk('the order comes from SMS_PROVIDER_ORDER with an mnotify,nalo default',
    str_contains($block, "SmsProviderChain::parseOrder(getenv('SMS_PROVIDER_ORDER') ?: 'mnotify,nalo')"));
chk('the chain is given the per-group credential resolver',
    str_contains($block, 'self::notificationCredentials(),'));
chk('Nalo base + path are per-connection settings, env-overridable',
    str_contains($block, "NALO_SMS_API_BASE_URL") && str_contains($block, "NALO_SMS_API_PATH")
    && str_contains($block, '$cred?->setting(\'api_base_url\')') && str_contains($block, '$cred?->setting(\'api_path\')'));
chk("Nalo's sender identity comes from the group's connection first",
    str_contains($block, '$cred?->senderId')
    && str_contains($block, "getenv('NALO_SMS_SENDER_ID') ?: getenv('SMS_SENDER_ID')"));
chk('Nalo secrets come from the VAULT, and from env only when there is no group credential',
    str_contains($block, '$secrets[\'username\']') && str_contains($block, '$secrets[\'password\']')
    && str_contains($block, '$secrets[\'auth_key\']') && str_contains($block, 'if ($cred === null) {'));
chk('mNotify key: vault first, env only on the legacy path',
    str_contains($block, '$secrets[\'api_key\']') && str_contains($block, "getenv('MNOTIFY_API_KEY')")
    && str_contains($block, "if (\$apiKey === '' && \$cred === null)"));
chk('the resolver factory wires the vault + the shared group scope',
    str_contains($svc, 'public static function notificationCredentials(')
    && str_contains($svc, 'IntegrationServices::credentialVault()')
    && substr_count($svc, 'SharedServices::groupScope()') >= 2);
chk('and send() can stamp the delivery with its group',
    str_contains($svc, 'new NotificationService(') );

$envSrc = (string) file_get_contents($root . '/.env.example');
foreach (['SMS_PROVIDER_ORDER', 'NALO_SMS_USERNAME', 'NALO_SMS_PASSWORD', 'NALO_SMS_AUTH_KEY', 'NALO_SMS_SENDER_ID', 'NALO_SMS_API_BASE_URL', 'NALO_SMS_API_PATH'] as $k) {
    chk(".env.example documents $k", str_contains($envSrc, $k));
}
chk('.env.example documents the failover rule', str_contains($envSrc, 'provider FAULT only'));

$seeder = (string) file_get_contents($root . '/app/Modules/Integrations/Database/Seeds/AdapterCatalogSeeder.php');
chk('the adapter catalog lists nalo_sms_v1', str_contains($seeder, "'nalo_sms_v1'"));
chk('on the sms channel, notification category', str_contains($seeder, "'nalo_sms_v1', 'notification'")
    && str_contains($seeder, "['GHS'], ['sms']"));
chk('declaring only the capabilities Nalo really has (no verifyWebhook)',
    (bool) preg_match("/'nalo_sms_v1'.*?\['sendNotification', 'healthCheck'\]/s", $seeder));

// ══════════════════════════════════════════════════════════════════════════
echo "21. per-group credentials: send on the body's own account, or refuse\n";

/** A credential as the resolver would hand it over (no secret material on it). */
$cred = static function (string $connId, string $provider, ?string $sender, array $settings = [], string $via = 'own', int $specificity = 0): ResolvedCredential {
    return new ResolvedCredential(
        connectionId: $connId,
        provider: $provider,
        adapterCode: $provider . '_sms_v1',
        channel: 'sms',
        ownerGroupId: 'g-' . $connId,
        via: $via,
        grantId: $via === 'granted' ? 'gr-1' : null,
        scopeMode: 'self',
        senderId: $sender,
        settings: $settings,
        specificity: $specificity,
    );
};

/**
 * Canned credential source: resolves by group id, declares usability, and hands
 * over canned secrets — standing in for the vault without touching a database.
 */
$source = static function (array $byGroup, array $unusable = [], array $secrets = []): GroupCredentialSource {
    return new class ($byGroup, $unusable, $secrets) implements GroupCredentialSource {
        public int $resolveCalls = 0;
        /** @var list<string|null> */
        public array $askedFor = [];

        public function __construct(private array $byGroup, private array $unusable, private array $secrets)
        {
        }

        public function resolveAll(string $organizationId, ?string $groupId, string $channel): array
        {
            $this->resolveCalls++;
            $this->askedFor[] = $groupId;

            return $this->byGroup[$groupId ?? ''] ?? [];
        }

        public function isUsable(ResolvedCredential $credential): bool
        {
            return ! in_array($credential->connectionId, $this->unusable, true);
        }

        public function withSecrets(ResolvedCredential $credential, callable $fn): mixed
        {
            if (! $this->isUsable($credential)) {
                return null;
            }

            return $fn($this->secrets[$credential->connectionId] ?? []);
        }
    };
};

/** A stub transport that records the credential + secrets its factory received. */
$recorder = static function (string $name, TransportResult $result, array &$log): callable {
    $transport = new class ($name, $result) implements ChannelTransport {
        public int $attempts = 0;

        public function __construct(private string $name, private TransportResult $result)
        {
        }

        public function channel(): string
        {
            return 'sms';
        }

        public function deliver(TransportMessage $message): TransportResult
        {
            $this->attempts++;

            return $this->result;
        }
    };

    return static function (?ResolvedCredential $c = null, array $secrets = []) use ($transport, &$log, $name): ChannelTransport {
        $log[] = [
            'provider' => $name,
            'conn'     => $c?->connectionId,
            'sender'   => $c?->senderId,
            'via'      => $c?->via,
            'secrets'  => $secrets,
        ];

        return $transport;
    };
};

$groupMsg = static function (?string $groupId): TransportMessage {
    return new TransportMessage(
        deliveryId: 'dlv-9',
        organizationId: 'org-1',
        channel: 'sms',
        category: 'event.reminder',
        recipient: '0244000111',
        body: 'Service starts at 9am.',
        groupId: $groupId,
    );
};

// (a) The body's OWN account: its sender id, its secret, one hop, accepted.
$log = [];
$src = $source(
    ['g-cell' => [$cred('conn-cell', 'mnotify', 'CELL-1')]],
    [],
    ['conn-cell' => ['api_key' => 'KEY-CELL']],
);
$chain = new SmsProviderChain(
    ['mnotify' => $recorder('mnotify', TransportResult::accepted('M-1'), $log),
     'nalo'    => $recorder('nalo', TransportResult::accepted('N-1'), $log)],
    SmsProviderChain::parseOrder('mnotify,nalo'),
    $src,
);
$res = $chain->deliver($groupMsg('g-cell'));
chk('accepted on the group\'s own credential', $res->isAccepted() && $res->providerRequestId === 'M-1');
chk('the resolver was asked about the SENDING group', $src->resolveCalls === 1 && $src->askedFor === ['g-cell'],
    json_encode($src->askedFor));
chk('the factory got that group\'s connection, sender id and secret',
    ($log[0]['conn'] ?? '') === 'conn-cell' && ($log[0]['sender'] ?? '') === 'CELL-1'
    && ($log[0]['secrets']['api_key'] ?? '') === 'KEY-CELL', json_encode($log[0] ?? null));
chk('and only one hop ran (accepted stops the chain)', count($log) === 1);

// (b) Fail closed: a group nobody shared with sends on NOBODY's account.
$log = [];
$src = $source(['g-remote' => []]);
$chain = new SmsProviderChain(
    ['mnotify' => $recorder('mnotify', TransportResult::accepted('M-1'), $log),
     'nalo'    => $recorder('nalo', TransportResult::accepted('N-1'), $log)],
    SmsProviderChain::parseOrder('mnotify,nalo'),
    $src,
);
$res = $chain->deliver($groupMsg('g-remote'));
chk('no credential ⇒ a permanent rejection, not a borrowed account',
    $res->isRejected() && ! $res->isAccepted(), json_encode([$res->outcome, $res->reason]));
chk('the refusal names the group that has no account',
    str_contains((string) $res->reason, 'g-remote'), (string) $res->reason);
chk('and no provider was attempted at all', $log === [], json_encode($log));

// (c) A delivery with no group has no body behind it.
$log = [];
$src = $source(['g-cell' => [$cred('conn-cell', 'mnotify', 'CELL-1')]]);
$chain = new SmsProviderChain(['mnotify' => $recorder('mnotify', TransportResult::accepted('M-1'), $log)], null, $src);
$res = $chain->deliver($groupMsg(null));
chk('a groupless delivery is refused before any resolution', $res->isRejected() && $src->resolveCalls === 0);
chk('its reason says why', str_contains((string) $res->reason, 'no group'), (string) $res->reason);

// (d) A provider this body has no usable credential for is SKIPPED, not borrowed.
$log = [];
$src = $source(
    ['g-cell' => [$cred('conn-cell', 'mnotify', 'CELL-1'), $cred('conn-region', 'nalo', 'REGION', [], 'granted', 1)]],
    ['conn-cell'],                       // mNotify account exists but has no key
    ['conn-region' => ['auth_key' => 'REGION-KEY']],
);
$chain = new SmsProviderChain(
    ['mnotify' => $recorder('mnotify', TransportResult::accepted('M-1'), $log),
     'nalo'    => $recorder('nalo', TransportResult::accepted('N-1'), $log)],
    SmsProviderChain::parseOrder('mnotify,nalo'),
    $src,
);
$res = $chain->deliver($groupMsg('g-cell'));
chk('the funded fallback still delivers', $res->isAccepted() && $res->providerRequestId === 'N-1');
chk('the credential-less provider was skipped, not attempted',
    count($log) === 1 && ($log[0]['provider'] ?? '') === 'nalo', json_encode($log));
chk('and the granted account is marked as granted', ($log[0]['via'] ?? '') === 'granted');

// (e) The group's own provider order beats the org-wide one.
$log = [];
$src = $source(
    ['g-cell' => [
        $cred('conn-cell', 'mnotify', 'CELL-1', ['provider_order' => 'nalo,mnotify']),
        $cred('conn-region', 'nalo', 'REGION'),
    ]],
    [],
    ['conn-cell' => ['api_key' => 'KEY-CELL'], 'conn-region' => ['auth_key' => 'REGION-KEY']],
);
$chain = new SmsProviderChain(
    // The org-wide order would try mNotify first; this body's own connection says
    // Nalo first, and Nalo is the hop that faults.
    ['mnotify' => $recorder('mnotify', TransportResult::accepted('M-1'), $log),
     'nalo'    => $recorder('nalo', TransportResult::failed('HTTP 503'), $log)],
    SmsProviderChain::parseOrder('mnotify,nalo'),   // org says mNotify first
    $src,
);
$res = $chain->deliver($groupMsg('g-cell'));
chk("the group's settings.provider_order is honoured over the org-wide one",
    ($log[0]['provider'] ?? '') === 'nalo', json_encode(array_column($log, 'provider')));
chk('and a fault on the preferred hop still falls over to the other',
    $res->isAccepted() && $res->providerRequestId === 'M-1' && count($log) === 2,
    json_encode([$res->outcome, $res->providerRequestId, array_column($log, 'provider')]));

// (f) Both of the group's providers faulting is retryable, and names both hops.
$log = [];
$src = $source(
    ['g-cell' => [$cred('conn-cell', 'mnotify', 'CELL-1'), $cred('conn-region', 'nalo', 'REGION')]],
    [],
    ['conn-cell' => ['api_key' => 'KEY-CELL'], 'conn-region' => ['auth_key' => 'REGION-KEY']],
);
$chain = new SmsProviderChain(
    ['mnotify' => $recorder('mnotify', TransportResult::failed('HTTP 503'), $log),
     'nalo'    => $recorder('nalo', TransportResult::failed('HTTP 502'), $log)],
    null,
    $src,
);
$res = $chain->deliver($groupMsg('g-cell'));
chk('every provider faulting is a retryable failure', $res->outcome === 'failed');
chk('naming both hops', str_contains((string) $res->reason, 'mnotify: HTTP 503')
    && str_contains((string) $res->reason, 'nalo: HTTP 502'), (string) $res->reason);
chk('and never leaking a secret into the reason', ! str_contains((string) $res->reason, 'KEY-CELL')
    && ! str_contains((string) $res->reason, 'REGION-KEY'));

// (g) A permanent rejection still stops the chain on the per-group path.
$log = [];
$src = $source(
    ['g-cell' => [$cred('conn-cell', 'mnotify', 'CELL-1'), $cred('conn-region', 'nalo', 'REGION')]],
    [],
    ['conn-cell' => ['api_key' => 'KEY-CELL'], 'conn-region' => ['auth_key' => 'REGION-KEY']],
);
$chain = new SmsProviderChain(
    ['mnotify' => $recorder('mnotify', TransportResult::rejected('invalid number'), $log),
     'nalo'    => $recorder('nalo', TransportResult::accepted('N-1'), $log)],
    null,
    $src,
);
$res = $chain->deliver($groupMsg('g-cell'));
chk('a rejection does not fall over to the second account', $res->isRejected() && count($log) === 1);

// (h) planFor tells a leader what their group would actually send on.
$src = $source(
    ['g-cell' => [$cred('conn-cell', 'mnotify', 'CELL-1', ['provider_order' => 'nalo,mnotify']),
                  $cred('conn-region', 'nalo', 'REGION', [], 'granted', 1)]],
    ['conn-cell'],
);
$noop = static fn (): ChannelTransport => new class () implements ChannelTransport {
    public function channel(): string
    {
        return 'sms';
    }

    public function deliver(TransportMessage $message): TransportResult
    {
        return TransportResult::failed('not used');
    }
};
$chain = new SmsProviderChain(['mnotify' => $noop, 'nalo' => $noop], null, $src);
$plan = $chain->planFor('org-1', 'g-cell');
chk('the effective chain is readable without touching a secret', count($plan) === 2, json_encode($plan));
chk('in the group\'s own order', ($plan[0]['provider'] ?? '') === 'nalo' && ($plan[1]['provider'] ?? '') === 'mnotify');
chk('flagging which hop is not usable', ($plan[1]['usable'] ?? true) === false && ($plan[0]['usable'] ?? false) === true);
chk('and whose account each hop is', ($plan[0]['via'] ?? '') === 'granted' && ($plan[1]['via'] ?? '') === 'own');
chk('a chain with no resolver has no per-group plan', (new SmsProviderChain([], null, null))->planFor('org-1', 'g-cell') === []);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
