<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Contributions\Services\WebhookSignatureVerifier;

/**
 * Locks the payment-webhook trust gate (SRS FR-VBCS-006): every payment state
 * change is guarded by this HMAC-SHA256 check, so it MUST accept a correctly
 * signed body and reject everything else, failing CLOSED on missing inputs.
 *
 * A regression here would let a spoofed "payment.succeeded" mutate the ledger,
 * so these are among the most security-critical assertions in the suite.
 * Pure crypto — no DB, no HTTP.
 *
 * @internal
 */
final class WebhookSignatureVerifierTest extends CIUnitTestCase
{
    private WebhookSignatureVerifier $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = new WebhookSignatureVerifier();
    }

    public function testValidSignatureIsAccepted(): void
    {
        $body   = '{"event_id":"evt_1","type":"payment.succeeded","intent_id":"pi_1"}';
        $secret = 'whsec_test';
        $sig    = hash_hmac('sha256', $body, $secret);

        $this->assertTrue($this->v->verify($body, $sig, $secret));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $secret = 'whsec_test';
        $sig    = hash_hmac('sha256', '{"amount":100}', $secret);

        // Same secret, different body (an attacker changed the amount).
        $this->assertFalse($this->v->verify('{"amount":999999}', $sig, $secret));
    }

    public function testWrongSecretIsRejected(): void
    {
        $body = '{"type":"payment.succeeded"}';
        $sig  = hash_hmac('sha256', $body, 'real_secret');

        $this->assertFalse($this->v->verify($body, $sig, 'attacker_secret'));
    }

    public function testEmptySignatureFailsClosed(): void
    {
        $this->assertFalse($this->v->verify('{"a":1}', '', 'whsec_test'));
    }

    public function testEmptySecretFailsClosed(): void
    {
        // An unconfigured provider must never be implicitly trusted.
        $body = '{"a":1}';
        $this->assertFalse($this->v->verify($body, hash_hmac('sha256', $body, ''), ''));
    }

    public function testSignatureIsSensitiveToWhitespaceInRawBody(): void
    {
        // Verification is over the RAW body; re-serialization must not validate.
        $secret = 'whsec_test';
        $raw    = '{"type":"payment.succeeded","intent_id":"pi_1"}';
        $sig    = hash_hmac('sha256', $raw, $secret);
        $reserialized = '{ "type": "payment.succeeded", "intent_id": "pi_1" }';

        $this->assertTrue($this->v->verify($raw, $sig, $secret));
        $this->assertFalse($this->v->verify($reserialized, $sig, $secret));
    }
}
