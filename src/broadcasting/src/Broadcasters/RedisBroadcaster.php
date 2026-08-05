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
        $channelName = $request->input('channel_name');

        if (empty($channelName)) {
            throw new AccessDeniedHttpException;
        }

        $channelName = $this->removeLeadingPrefix($channelName);

        return parent::verifyUserCanAccessChannel(
            $request,
            $this->normalizeChannelName($channelName),
            $this->isGuardedChannel($channelName),
        );
    }

    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return $this->validAuthenticationResponseForChannel(
            $request,
            $result,
            $this->normalizeChannelName(
                $this->removeLeadingPrefix($request->input('channel_name'))
            ),
        );
    }

    /**
     * Return the valid authentication response for the authorized channel.
     */
    protected function validAuthenticationResponseForChannel(
        Request $request,
        mixed $result,
        string $channel,
    ): mixed {
        if (is_bool($result)) {
            return json_encode($result);
        }

        $user = $this->retrieveUser($request, $channel);

        $broadcastIdentifier = method_exists($user, 'getAuthIdentifierForBroadcasting')
            ? $user->getAuthIdentifierForBroadcasting()
            : $user->getAuthIdentifier();

        return json_encode(
            ['channel_data' => [
                'user_id' => $broadcastIdentifier,
                'user_info' => $result,
            ]],
            JSON_THROW_ON_ERROR,
        );
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

        $payload = json_encode(
            [
                'event' => $event,
                'data' => $payload,
                'socket' => $socket,
            ],
            JSON_THROW_ON_ERROR,
        );

        try {
            if ($connection->isCluster()) {
                // Native phpredis publish applies the connection prefix, so parent::
                // deliberately skips this class's prefix-adding formatChannels() override.
                foreach (parent::formatChannels($channels) as $channel) {
                    $connection->publish($channel, $payload);
                }
            } else {
                // Lua receives channels as ARGV, which phpredis does not prefix.
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

    /**
     * Remove the configured Redis prefix from the start of the channel name.
     */
    private function removeLeadingPrefix(string $channel): string
    {
        return $this->prefix !== '' && str_starts_with($channel, $this->prefix)
            ? substr($channel, strlen($this->prefix))
            : $channel;
    }
}
