<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Concerns;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Protocols\Pusher\Channels\CacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Channels\Concerns\InteractsWithPresenceChannels;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;

trait InteractsWithChannelInformation
{
    /**
     * Get meta / status information for the given channels.
     */
    protected function infoForChannels(
        Application $application,
        array $channels,
        string $info,
        bool $includeUserIds = false,
    ): array {
        return collect($channels)->mapWithKeys(function ($channel) use ($application, $info, $includeUserIds) {
            $name = $channel instanceof Channel ? $channel->name() : $channel;

            return [$name => $this->info($application, $name, $info, $includeUserIds)];
        })->all();
    }

    /**
     * Get meta / status information for the given channel.
     *
     * @return array<string, mixed>
     */
    protected function info(
        Application $application,
        string $channel,
        string $info,
        bool $includeUserIds = false,
    ): array {
        $info = explode(',', $info);

        $channel = app(ChannelManager::class)->for($application)->find($channel);

        return array_filter(
            $channel ? $this->occupiedInfo($channel, $info, $includeUserIds) : $this->unoccupiedInfo($info),
            fn ($item) => $item !== null
        );
    }

    /**
     * Get channel information for the given occupied channel.
     */
    private function occupiedInfo(Channel $channel, array $info, bool $includeUserIds): array
    {
        $count = count($channel->connections());
        $presence = $this->isPresenceChannel($channel);

        $cache = null;
        if (in_array('cache', $info, true) && $channel instanceof CacheChannel) {
            $cache = $channel->cachedPayload();
        }

        return [
            'occupied' => in_array('occupied', $info, true) ? $count > 0 : null,
            'user_count' => in_array('user_count', $info, true) && $presence ? $this->userCount($channel) : null,
            'subscription_count' => in_array('subscription_count', $info, true) && ! $presence ? $count : null,
            'cache' => $cache,
            'reverb_user_ids' => $includeUserIds && $presence ? $this->userIds($channel) : null,
        ];
    }

    /**
     * Get channel information for the given unoccupied channel.
     */
    private function unoccupiedInfo(array $info): array
    {
        return [
            'occupied' => in_array('occupied', $info, true) ? false : null,
        ];
    }

    /**
     * Determine if the given channel is a presence channel.
     */
    protected function isPresenceChannel(Channel $channel): bool
    {
        return in_array(InteractsWithPresenceChannels::class, class_uses_recursive($channel), true);
    }

    /**
     * Determine if the given channel is a cache channel.
     */
    protected function isCacheChannel(Channel $channel): bool
    {
        return $channel instanceof CacheChannel;
    }

    /**
     * Get the number of unique users subscribed to the presence channel.
     */
    protected function userCount(Channel $channel): int
    {
        return count($this->userIds($channel));
    }

    /**
     * Get the unique user IDs subscribed to the presence channel.
     */
    protected function userIds(Channel $channel): array
    {
        return collect($channel->connections())
            ->map(fn ($connection) => $connection->data())
            ->unique('user_id')
            ->pluck('user_id')
            ->values()
            ->all();
    }
}
