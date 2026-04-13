<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Managers;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelBroker;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager as ChannelManagerInterface;
use Hypervel\Support\Arr;

class ArrayChannelManager implements ChannelManagerInterface
{
    /**
     * The underlying array of applications and their channels.
     *
     * @var array<string, array<string, Channel>>
     */
    protected array $applications = [];

    /**
     * Get a scoped channel manager for the given application.
     */
    public function for(Application $application): ScopedChannelManager
    {
        return new ScopedChannelManager($application, $this);
    }

    /**
     * Get the channels for an application, optionally filtered by name.
     *
     * @return null|array<string, Channel>|Channel
     */
    public function channels(string $appId, ?string $channel = null): Channel|array|null
    {
        $channels = $this->applications[$appId] ?? [];

        if (isset($channel)) {
            return $channels[$channel] ?? null;
        }

        return $channels;
    }

    /**
     * Determine whether the given channel exists for the application.
     */
    public function channelExists(string $appId, string $channel): bool
    {
        return isset($this->applications[$appId][$channel]);
    }

    /**
     * Find the given channel for the application.
     */
    public function findChannel(string $appId, string $channel): ?Channel
    {
        return $this->channels($appId, $channel);
    }

    /**
     * Find the given channel or create it if it doesn't exist.
     *
     * Note: ChannelCreated event dispatch is handled by Channel::subscribe()
     * via SharedState, not here.
     */
    public function findOrCreateChannel(string $appId, string $channelName): Channel
    {
        if ($channel = $this->findChannel($appId, $channelName)) {
            return $channel;
        }

        $channel = ChannelBroker::create($channelName);

        $this->applications[$appId][$channel->name()] = $channel;

        return $channel;
    }

    /**
     * Get all connections for the given channels.
     *
     * @return array<string, ChannelConnection>
     */
    public function channelConnections(string $appId, ?string $channel = null): array
    {
        $channels = Arr::wrap($this->channels($appId, $channel));

        return array_reduce($channels, function (array $carry, Channel $channel) {
            return $carry + $channel->connections();
        }, []);
    }

    /**
     * Unsubscribe a connection from all channels for the application.
     */
    public function unsubscribeFromAllChannels(string $appId, Connection $connection): void
    {
        foreach ($this->channels($appId) as $channel) {
            $channel->unsubscribe($connection);
        }
    }

    /**
     * Remove the given channel from the application.
     *
     * Note: ChannelRemoved event dispatch is handled by Channel::unsubscribe()
     * via SharedState, not here.
     */
    public function removeChannel(string $appId, Channel $channel): void
    {
        unset($this->applications[$appId][$channel->name()]);
    }

    /**
     * Flush the channel manager repository.
     */
    public function flush(): void
    {
        app(ApplicationProvider::class)
            ->all()
            ->each(function (Application $application) {
                $this->applications[$application->id()] = [];
            });
    }
}
