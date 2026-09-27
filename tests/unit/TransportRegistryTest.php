<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use WBS\Notifications\Transport\ChannelTransport;
use WBS\Notifications\Transport\InAppTransport;
use WBS\Notifications\Transport\TransportRegistry;

/**
 * The registry is the "add a channel = add a factory" seam: it resolves lazily,
 * memoizes, is case-insensitive, and fails loudly for an unregistered channel
 * (so the dispatcher never silently marks a delivery "sent" for a channel with
 * no real transport).
 *
 * @internal
 */
final class TransportRegistryTest extends CIUnitTestCase
{
    public function testResolvesRegisteredChannelCaseInsensitively(): void
    {
        $reg = new TransportRegistry([
            'inapp' => static fn (): ChannelTransport => new InAppTransport(),
        ]);

        $this->assertTrue($reg->has('inapp'));
        $this->assertTrue($reg->has('InApp'));
        $this->assertInstanceOf(InAppTransport::class, $reg->for('INAPP'));
    }

    public function testFactoryIsInvokedOnceAndMemoized(): void
    {
        $calls = 0;
        $reg   = new TransportRegistry([
            'inapp' => static function () use (&$calls): ChannelTransport {
                $calls++;

                return new InAppTransport();
            },
        ]);

        $a = $reg->for('inapp');
        $b = $reg->for('inapp');

        $this->assertSame($a, $b);
        $this->assertSame(1, $calls);
    }

    public function testUnknownChannelIsHardFailure(): void
    {
        $reg = new TransportRegistry([]);

        $this->assertFalse($reg->has('email'));
        $this->expectException(RuntimeException::class);
        $reg->for('email');
    }
}
