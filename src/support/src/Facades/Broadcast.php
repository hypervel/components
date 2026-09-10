<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactoryContract;

/**
 * @method static \Ably\AblyRest ably(array $config)
 * @method static \Hypervel\Broadcasting\BroadcastManager addPoolableDriver(string $driver)
 * @method static void channelRoutes(array|null $attributes = null)
 * @method static \Hypervel\Contracts\Broadcasting\Broadcaster connection(\UnitEnum|string|null $driver = null)
 * @method static \Hypervel\Contracts\Broadcasting\Broadcaster driver(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Broadcasting\PendingBroadcast event(mixed $event = null)
 * @method static \Hypervel\Broadcasting\BroadcastManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Broadcasting\BroadcastManager forgetDrivers()
 * @method static \Hypervel\Contracts\Container\Container getApplication()
 * @method static string getDefaultDriver()
 * @method static array getPoolableDrivers()
 * @method static \Closure|null getReleaseCallback(string $driver)
 * @method static \Hypervel\Broadcasting\AnonymousEvent on(\Hypervel\Broadcasting\Channel|array|string $channels)
 * @method static \Hypervel\Broadcasting\AnonymousEvent presence(string $channel)
 * @method static \Hypervel\Broadcasting\AnonymousEvent private(string $channel)
 * @method static void purge(\UnitEnum|string|null $name = null)
 * @method static \Pusher\Pusher pusher(array $config)
 * @method static void queue(mixed $event)
 * @method static \Hypervel\Broadcasting\BroadcastManager removePoolableDriver(string $driver)
 * @method static string|null resolveConnectionFromQueueRoute(object $queueable, \UnitEnum|string|null $queue = null)
 * @method static string|null resolveQueueFromQueueRoute(object $queueable)
 * @method static void routes(array|null $attributes = null)
 * @method static \Hypervel\Broadcasting\BroadcastManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static void setDefaultDriver(\UnitEnum|string $name)
 * @method static \Hypervel\Broadcasting\BroadcastManager setPoolableDrivers(array $poolableDrivers)
 * @method static \Hypervel\Broadcasting\BroadcastManager setReleaseCallback(string $driver, \Closure $callback)
 * @method static string|null socket(\Hypervel\Http\Request|null $request = null)
 * @method static void userRoutes(array|null $attributes = null)
 * @method static mixed auth(\Hypervel\Http\Request $request)
 * @method static void authorizeChannelsUsing(\Closure|null $callback)
 * @method static void broadcast(array $channels, string $event, array $payload = [])
 * @method static \Hypervel\Broadcasting\Broadcasters\Broadcaster channel(\Hypervel\Contracts\Broadcasting\HasBroadcastChannel|string $channel, callable|string $callback, array $options = [])
 * @method static void flushState()
 * @method static void formatChannelsUsing(\Closure|null $callback)
 * @method static \Hypervel\Support\Collection getChannels()
 * @method static array|null resolveAuthenticatedUser(\Hypervel\Http\Request $request)
 * @method static void resolveAuthenticatedUserUsing(\Closure|null $callback)
 * @method static mixed validAuthenticationResponse(\Hypervel\Http\Request $request, mixed $result)
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
