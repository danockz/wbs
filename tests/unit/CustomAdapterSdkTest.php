<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Integrations\Sdk\AbstractCustomAdapter;
use WBS\Integrations\Sdk\AdapterManifest;
use WBS\Integrations\Sdk\ContractTestSuite;
use WBS\Integrations\Sdk\Examples\ExampleRestNotificationAdapter;
use WBS\Integrations\Sdk\ManifestSigner;
use WBS\Integrations\Sdk\OperationRequest;
use WBS\Integrations\Sdk\OperationResult;
use WBS\Shared\Security\KeyProvider;

/**
 * Locks the custom-adapter SDK + contract-test suite (SRS FR-INT-013).
 *
 * These are the guarantees that let a NON-conforming provider be onboarded as a
 * reviewed module without endangering core:
 *  - a manifest is only valid against the finite canonical vocabulary + the
 *    HTTPS/SSRF host rules (config data, never code),
 *  - the contract-test suite CERTIFIES a conforming adapter and REJECTS one that
 *    lies about a capability or leaks secret material,
 *  - the undeclared-operation guard cannot be bypassed, and
 *  - a certified manifest is signed and tamper-evident (rotation-safe).
 *
 * Pure logic — no DB. The signer uses an in-memory KeyProvider double.
 *
 * @internal
 */
final class CustomAdapterSdkTest extends CIUnitTestCase
{
    private function keys(): KeyProvider
    {
        return new class implements KeyProvider {
            public function activeKeyId(): string
            {
                return 'k1';
            }

            public function keyFor(string $keyId): string
            {
                if ($keyId !== 'k1') {
                    throw new \RuntimeException('unknown key');
                }

                return str_repeat('A', 32);
            }
        };
    }

    public function testExampleAdapterManifestIsValid(): void
    {
        $this->assertTrue((new ExampleRestNotificationAdapter())->manifest()->isValid());
    }

    public function testManifestRejectsNonCanonicalCapability(): void
    {
        $m = new AdapterManifest('x_v1', 1, 'notification', 'rest_json_notification', 'X', ['notARealOp', 'healthCheck']);
        $this->assertFalse($m->isValid());
    }

    public function testManifestRequiresHealthCheck(): void
    {
        $m = new AdapterManifest('y_v1', 1, 'notification', 'rest_json_notification', 'Y', ['sendNotification']);
        $this->assertFalse($m->isValid());
    }

    public function testManifestRejectsIpLiteralHostForSsrf(): void
    {
        $m = new AdapterManifest('z_v1', 1, 'notification', 'rest_json_notification', 'Z', ['sendNotification', 'healthCheck'], [], ['10.0.0.1']);
        $this->assertFalse($m->isValid());
    }

    public function testContractSuiteCertifiesConformingAdapter(): void
    {
        $report = (new ContractTestSuite())->run(
            new ExampleRestNotificationAdapter(),
            ['sendNotification' => ['to' => 'u1', 'message' => 'hello']],
        );
        $this->assertTrue($report->passed(), $report->summary());
    }

    public function testContractSuiteRejectsAdapterThatLiesAboutCapability(): void
    {
        $bad = new class extends AbstractCustomAdapter {
            protected function buildManifest(): AdapterManifest
            {
                // Declares sendNotification but never implements opSendNotification.
                return new AdapterManifest('bad_v1', 1, 'notification', 'rest_json_notification', 'Bad', ['sendNotification', 'healthCheck']);
            }
        };
        $this->assertFalse((new ContractTestSuite())->run($bad)->passed());
    }

    public function testContractSuiteRejectsSecretLeak(): void
    {
        $leaky = new class extends AbstractCustomAdapter {
            protected function buildManifest(): AdapterManifest
            {
                return new AdapterManifest('leak_v1', 1, 'notification', 'rest_json_notification', 'Leaky', ['sendNotification', 'healthCheck']);
            }

            protected function opSendNotification(OperationRequest $r): OperationResult
            {
                return OperationResult::ok(['api_key' => $r->secret('api_key')]);
            }
        };
        $report = (new ContractTestSuite())->run($leaky, ['sendNotification' => ['to' => 'x', 'message' => 'y']]);
        $this->assertFalse($report->passed());
    }

    public function testUndeclaredOperationIsRejected(): void
    {
        $res = (new ExampleRestNotificationAdapter())->perform(new OperationRequest('createCheckout'));
        $this->assertFalse($res->ok);
        $this->assertSame('OP_NOT_DECLARED', $res->errorCode);
    }

    public function testManifestSignatureRoundTrips(): void
    {
        $signer   = new ManifestSigner($this->keys());
        $manifest = (new ExampleRestNotificationAdapter())->manifest();
        $sig      = $signer->sign($manifest);
        $this->assertTrue($signer->verify($manifest, $sig));
    }

    public function testTamperedManifestFailsSignature(): void
    {
        $signer = new ManifestSigner($this->keys());
        $sig    = $signer->sign((new ExampleRestNotificationAdapter())->manifest());
        $altered = new AdapterManifest('example_rest_notify_v1', 1, 'notification', 'rest_json_notification', 'EVIL', ['sendNotification', 'healthCheck']);
        $this->assertFalse($signer->verify($altered, $sig));
    }

    public function testMalformedSignatureIsRejectedFailClosed(): void
    {
        $signer   = new ManifestSigner($this->keys());
        $manifest = (new ExampleRestNotificationAdapter())->manifest();
        $this->assertFalse($signer->verify($manifest, 'garbage'));
        $this->assertFalse($signer->verify($manifest, 'v1:k1:'));
        $this->assertFalse($signer->verify($manifest, 'v9:k1:AAAA'));
    }
}
