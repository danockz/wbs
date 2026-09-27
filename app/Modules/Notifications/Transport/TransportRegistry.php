<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

/**
 * Lazy registry of {@see ChannelTransport} implementations, keyed by channel.
 *
 * Transports are provided as factories so a channel's client (HTTP wrapper,
 * credentials) is only constructed when a job for that channel actually runs —
 * matching the module's "wire only what a job needs" pattern.
 *
 * A channel with no registered transport is reported via {@see has()} so the
 * dispatcher can fail loudly rather than silently marking a delivery "sent"
 * against a channel that has no real implementation.
 */
final class TransportRegistry
{
    /** @var array<string,callable():ChannelTransport> */
    private array $factories;

    /** @var array<string,ChannelTransport> */
    private array $resolved = [];

    /**
     * @param array<string,callable():ChannelTransport> $factories channel => factory
     */
    public function __construct(array $factories = [])
    {
        $this->factories = $factories;
    }

    public function has(string $channel): bool
    {
        return isset($this->factories[strtolower($channel)]);
    }

    /**
     * Resolve (and memoize) the transport for a channel.
     *
     * @throws \RuntimeException when the channel has no registered transport
     */
    public function for(string $channel): ChannelTransport
    {
        $key = strtolower($channel);
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }
        if (! isset($this->factories[$key])) {
            throw new \RuntimeException(sprintf('No transport registered for channel "%s".', $channel));
        }

        return $this->resolved[$key] = ($this->factories[$key])();
    }
}
