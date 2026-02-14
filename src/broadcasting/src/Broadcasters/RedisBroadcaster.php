<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Broadcasters;

use Hypervel\Broadcasting\BroadcastException;
use Hypervel\Contracts\Container\Container;
use Hypervel\HttpMessage\Exceptions\AccessDeniedHttpException;
use Hypervel\HttpServer\Contracts\RequestInterface;
use Hypervel\Pool\Exception\ConnectionException;
use Hypervel\Redis\RedisFactory;
use Hypervel\Support\Arr;
use RedisException;

class RedisBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    /**
     * Create a new broadcaster instance.
     */
    public function __construct(
        protected Container $container,
        protected RedisFactory $factory,
        protected string $connection = 'default',
        protected string $prefix = ''
    ) {
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @throws AccessDeniedHttpException
     */
    public function auth(RequestInterface $request): mixed
    {
        $channelName = $request->input('channel_name');
        $normalizeChannelName = $this->normalizeChannelName(
            str_replace($this->prefix, '', $channelName)
        );

        if (empty($channelName)
            || ($this->isGuardedChannel($channelName) && ! $this->retrieveUser($normalizeChannelName))
        ) {
            throw new AccessDeniedHttpException();
        }

        return parent::verifyUserCanAccessChannel(
            $request,
            $normalizeChannelName
        );
    }

    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(RequestInterface $request, mixed $result): mixed
    {
        if (is_bool($result)) {
            return json_encode($result);
        }

        $channelName = $this->normalizeChannelName($request->input('channel_name'));

        $user = $this->retrieveUser($channelName);

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

        $connection = $this->factory->get($this->connection);

        $payload = json_encode([
            'event' => $event,
            'data' => $payload,
            'socket' => Arr::pull($payload, 'socket'),
        ]);

        try {
            $connection->eval(
                $this->broadcastMultipleChannelsScript(),
                0,
                $payload,
                ...$this->formatChannels($channels),
            );
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
