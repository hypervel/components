<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactoryContract;

/**
 * @method static void routes(array $attributes = [])
 * @method static void userRoutes(array|null $attributes = null)
 * @method static void channelRoutes(array|null $attributes = null)
 * @method static string|null socket(\Hypervel\HttpServer\Contracts\RequestInterface|null $request = null)
 * @method static \Hypervel\Broadcasting\AnonymousEvent on(\Hypervel\Broadcasting\Channel|array|string $channels)
 * @method static \Hypervel\Broadcasting\AnonymousEvent private(string $channel)
 * @method static \Hypervel\Broadcasting\AnonymousEvent presence(string $channel)
 * @method static \Hypervel\Broadcasting\PendingBroadcast event(mixed $event = null)
 * @method static void queue(mixed $event)
 * @method static \Hypervel\Contracts\Broadcasting\Broadcaster connection(string|null $driver = null)
 * @method static \Hypervel\Contracts\Broadcasting\Broadcaster driver(string|null $name = null)
 * @method static \Pusher\Pusher pusher(array $config)
 * @method static \Ably\AblyRest ably(array $config)
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static void purge(string|null $name = null)
 * @method static \Hypervel\Broadcasting\BroadcastManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Contracts\Container\Container getApplication()
 * @method static \Hypervel\Broadcasting\BroadcastManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static \Hypervel\Broadcasting\BroadcastManager forgetDrivers()
 * @method static \Hypervel\Broadcasting\BroadcastManager setReleaseCallback(string $driver, \Closure $callback)
 * @method static \Closure|null getReleaseCallback(string $driver)
 * @method static \Hypervel\Broadcasting\BroadcastManager addPoolable(string $driver)
 * @method static \Hypervel\Broadcasting\BroadcastManager removePoolable(string $driver)
 * @method static array getPoolables()
 * @method static \Hypervel\Broadcasting\BroadcastManager setPoolables(array $poolables)
 * @method static array|null resolveAuthenticatedUser(\Hypervel\HttpServer\Contracts\RequestInterface $request)
 * @method static void resolveAuthenticatedUserUsing(\Closure $callback)
 * @method static \Hypervel\Broadcasting\Broadcasters\Broadcaster channel(\Hypervel\Contracts\Broadcasting\HasBroadcastChannel|string $channel, callable|string $callback, array $options = [])
 * @method static \Hypervel\Support\Collection getChannels()
 * @method static void flushChannels()
 * @method static mixed auth(\Hypervel\HttpServer\Contracts\RequestInterface $request)
 * @method static mixed validAuthenticationResponse(\Hypervel\HttpServer\Contracts\RequestInterface $request, mixed $result)
 * @method static void broadcast(array $channels, string $event, array $payload = [])
 *
 * @see \Hypervel\Broadcasting\BroadcastManager
 * @see \Hypervel\Broadcasting\Broadcasters\Broadcaster
 */
class Broadcast extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return BroadcastingFactoryContract::class;
    }
}
