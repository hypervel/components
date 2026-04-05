<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Managers;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;

/**
 * Immutable scoped proxy for the ArrayChannelManager.
 *
 * Captures the Application and delegates all operations to the
 * underlying manager with the app ID. This is coroutine-safe —
 * each caller gets its own proxy instance with a fixed app scope.
 */
class ScopedChannelManager
{
    /**
     * Create a new scoped channel manager instance.
     */
    public function __construct(
        private readonly Application $application,
        private readonly ArrayChannelManager $manager,
    ) {
    }

    /**
     * Get the application instance.
     */
    public function app(): Application
    {
        return $this->application;
    }

    /**
     * Get all the channels.
     *
     * @return array<string, Channel>
     */
    public function all(): array
    {
        return $this->manager->channels($this->application->id());
    }

    /**
     * Determine whether the given channel exists.
     */
    public function exists(string $channel): bool
    {
        return $this->manager->channelExists($this->application->id(), $channel);
    }

    /**
     * Find the given channel.
     */
    public function find(string $channel): ?Channel
    {
        return $this->manager->findChannel($this->application->id(), $channel);
    }

    /**
     * Find the given channel or create it if it doesn't exist.
     */
    public function findOrCreate(string $channel): Channel
    {
        return $this->manager->findOrCreateChannel($this->application->id(), $channel);
    }

    /**
     * Get all connections for the given channels.
     *
     * @return array<string, ChannelConnection>
     */
    public function connections(?string $channel = null): array
    {
        return $this->manager->channelConnections($this->application->id(), $channel);
    }

    /**
     * Unsubscribe from all channels.
     */
    public function unsubscribeFromAll(Connection $connection): void
    {
        $this->manager->unsubscribeFromAllChannels($this->application->id(), $connection);
    }

    /**
     * Remove the given channel.
     */
    public function remove(Channel $channel): void
    {
        $this->manager->removeChannel($this->application->id(), $channel);
    }
}
