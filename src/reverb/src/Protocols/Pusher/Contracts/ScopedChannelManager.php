<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Contracts;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;

interface ScopedChannelManager
{
    /**
     * Get the application instance.
     */
    public function app(): Application;

    /**
     * Get all the channels.
     *
     * @return array<string, Channel>
     */
    public function all(): array;

    /**
     * Determine whether the given channel exists.
     */
    public function exists(string $channel): bool;

    /**
     * Find the given channel.
     */
    public function find(string $channel): ?Channel;

    /**
     * Find the given channel or create it if it doesn't exist.
     */
    public function findOrCreate(string $channel): Channel;

    /**
     * Get all connections for the given channels.
     *
     * @return array<string, ChannelConnection>
     */
    public function connections(?string $channel = null): array;

    /**
     * Find a connection by its socket ID.
     */
    public function findConnection(string $socketId): ?ChannelConnection;

    /**
     * Unsubscribe from all channels.
     */
    public function unsubscribeFromAll(Connection $connection): void;

    /**
     * Remove the given channel.
     */
    public function remove(Channel $channel): void;
}
