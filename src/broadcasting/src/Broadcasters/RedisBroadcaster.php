<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Broadcasters;

use Hypervel\Broadcasting\BroadcastException;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Http\Request;
use Hypervel\Pool\Exceptions\ConnectionException;
use Hypervel\Support\Arr;
use RedisException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RedisBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    /**
     * Create a new broadcaster instance.
     */
    public function __construct(
        protected Container $container,
        protected Redis $factory,
        protected string $connection = 'default',
        protected string $prefix = ''
    ) {
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @throws AccessDeniedHttpException
     */
    public function auth(Request $request): mixed
    {
        $channelName = $this->normalizeChannelName(
            str_replace($this->prefix, '', $request->input('channel_name'))
        );

        if (empty($request->input('channel_name'))
            || ($this->isGuardedChannel($request->input('channel_name')) && ! $this->retrieveUser($request, $channelName))
        ) {
            throw new AccessDeniedHttpException();
        }

        return parent::verifyUserCanAccessChannel(
            $request,
            $channelName
        );
    }

    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        if (is_bool($result)) {
            return json_encode($result);
        }

        $channelName = $this->normalizeChannelName($request->input('channel_name'));

        $user = $this->retrieveUser($request, $channelName);

        $broadcastIdentifier = method_exists($user, 'getAuthIdentifierForBroadcasting')
            ? $user->getAuthIdentifierForBroadcasting()
            : $user->getAuthIdentifier();

        return json_encode(['channel_data' => [
            'user_id' => $broadcastIdentifier,
            'user_info' => $result,
        ]]);
    }

    /**
     * Broadcast the given event.
     *
     * @throws BroadcastException
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        if (empty($channels)) {
            return;
        }

        $connection = $this->factory->connection($this->connection);

        $socket = Arr::pull($payload, 'socket');

        $payload = json_encode([
            'event' => $event,
            'data' => $payload,
            'socket' => $socket,
        ]);

        try {
            if ($connection->isCluster()) {
                foreach ($this->formatChannels($channels) as $channel) {
                    $connection->publish($channel, $payload);
                }
            } else {
                $connection->eval(
                    $this->broadcastMultipleChannelsScript(),
                    0,
                    $payload,
                    ...$this->formatChannels($channels),
                );
            }
        } catch (ConnectionException|RedisException $e) {
            throw new BroadcastException(
                sprintf('Redis error: %s.', $e->getMessage())
            );
        }
    }

    /**
     * Get the Lua script for broadcasting to multiple channels.
     *
     * ARGV[1] - The payload
     * ARGV[2...] - The channels
     */
    protected function broadcastMultipleChannelsScript(): string
    {
        return <<<'LUA'
            for i = 2, #ARGV do
              redis.call('publish', ARGV[i], ARGV[1])
            end
        LUA;
    }

    /**
     * Format the channel array into an array of strings.
     */
    protected function formatChannels(array $channels): array
    {
        return array_map(function ($channel) {
            return $this->prefix . $channel;
        }, parent::formatChannels($channels));
    }
}
