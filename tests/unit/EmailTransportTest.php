<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Integrations\Providers\ProviderException;
use WBS\Integrations\Providers\ProviderHttp;
use WBS\Notifications\Transport\EmailTransport;
use WBS\Notifications\Transport\TransportMessage;
use WBS\Notifications\Transport\TransportResult;

/**
 * Verifies the reference channel transport performs a real send call and maps
 * provider outcomes onto the retry/reject/accept contract that keeps a bad
 * recipient from tripping the circuit breaker (FR-NOT-*, FR-INT-012).
 *
 * ProviderHttp::request() is stubbed (no network) via a tiny subclass so the
 * transport's HTTP payload and status handling are exercised directly.
 *
 * @internal
 */
final class EmailTransportTest extends CIUnitTestCase
{
    private function message(string $recipient = 'user@example.org'): TransportMessage
    {
        return new TransportMessage(
            deliveryId: 'del-1',
            organizationId: 'org-1',
            channel: 'email',
            category: 'community',
            recipient: $recipient,
            body: '<p>hi</p>',
            subject: 'Hello',
        );
    }

    public function testAcceptedReturnsProviderMessageId(): void
    {
        $http = new class ('email') extends ProviderHttp {
            public array $lastOptions = [];

            public function request(string $method, string $url, array $options = []): array
            {
                $this->lastOptions = $options;

                return ['message_id' => 'prov-123'];
            }
        };

        $t   = new EmailTransport($http, 'https://api.mail.test', 'key', 'from@example.org', 'WBS');
        $res = $t->deliver($this->message());

        $this->assertTrue($res->isAccepted());
        $this->assertSame('prov-123', $res->providerRequestId);
        // Idempotency + real payload were sent.
        $this->assertSame('del-1', $http->lastOptions['headers']['Idempotency-Key']);
        $this->assertSame('Hello', $http->lastOptions['json']['subject']);
        $this->assertSame('user@example.org', $http->lastOptions['json']['to'][0]['email']);
    }

    public function testInvalidRecipientIsRejectedWithoutHttpCall(): void
    {
        $http = new class ('email') extends ProviderHttp {
            public bool $called = false;

            public function request(string $method, string $url, array $options = []): array
            {
                $this->called = true;

                return [];
            }
        };

        $t   = new EmailTransport($http, 'https://api.mail.test', 'key', 'from@example.org');
        $res = $t->deliver($this->message('not-an-email'));

        $this->assertTrue($res->isRejected());
        $this->assertFalse($http->called);
    }

    public function testNotConfiguredIsTransientFailure(): void
    {
        $http = new class ('email') extends ProviderHttp {
            public function request(string $method, string $url, array $options = []): array
            {
                return [];
            }
        };

        $t   = new EmailTransport($http, '', '', 'from@example.org');
        $res = $t->deliver($this->message());

        $this->assertSame(TransportResult::FAILED, $res->outcome);
        $this->assertFalse($res->isAccepted());
        $this->assertFalse($res->isRejected());
    }

    public function testPermanent4xxIsRejected(): void
    {
        $http = new class ('email') extends ProviderHttp {
            public function request(string $method, string $url, array $options = []): array
            {
                throw new ProviderException('HTTP 422: bad payload', 422, 'email');
            }
        };

        $t   = new EmailTransport($http, 'https://api.mail.test', 'key', 'from@example.org');
        $res = $t->deliver($this->message());

        $this->assertTrue($res->isRejected());
    }

    public function testRateLimitAndServerErrorsAreRetryable(): void
    {
        foreach ([429, 500, 503, 408] as $status) {
            $http = new class ('email', $status) extends ProviderHttp {
                public function __construct(string $p, private int $status)
                {
                    parent::__construct($p);
                }

                public function request(string $method, string $url, array $options = []): array
                {
                    throw new ProviderException('HTTP ' . $this->status, $this->status, 'email');
                }
            };

            $t   = new EmailTransport($http, 'https://api.mail.test', 'key', 'from@example.org');
            $res = $t->deliver($this->message());

            $this->assertSame(TransportResult::FAILED, $res->outcome, "status {$status} should be retryable");
        }
    }
}
