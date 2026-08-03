<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Ably\AblyRest;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use Hypervel\Broadcasting\Broadcasters\AblyBroadcaster;
use Hypervel\Broadcasting\Broadcasters\LogBroadcaster;
use Hypervel\Broadcasting\Broadcasters\NullBroadcaster;
use Hypervel\Broadcasting\Broadcasters\PusherBroadcaster;
use Hypervel\Broadcasting\Broadcasters\RedisBroadcaster;
use Hypervel\Bus\UniqueLock;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactoryContract;
use Hypervel\Contracts\Broadcasting\ShouldBeUnique;
use Hypervel\Contracts\Broadcasting\ShouldBroadcastNow;
use Hypervel\Contracts\Broadcasting\ShouldRescue;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Foundation\CachesRoutes;
use Hypervel\Contracts\Queue\Factory as Queue;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Queue\Attributes\Connection as ConnectionAttribute;
use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Redis\RedisConfig;
use Hypervel\Routing\Router;
use Hypervel\Support\Arr;
use Hypervel\Support\Queue\Concerns\ResolvesQueueRoutes;
use Hypervel\Support\RebindsCallbacksToSelf;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;
use ReflectionException;
use RuntimeException;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Broadcasting\Broadcasters\Broadcaster
 */
class BroadcastManager implements BroadcastingFactoryContract
{
    use HasPoolProxy;
    use ReadsQueueAttributes;
    use RebindsCallbacksToSelf;
    use ResolvesQueueRoutes;

    /**
     * The array of resolved broadcast drivers.
     */
    protected array $drivers = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * The array of drivers which will be wrapped as pool proxies.
     */
    protected array $poolables = [];

    /**
     * Create a new manager instance.
     */
    public function __construct(
        protected Container $app,
    ) {
    }

    /**
     * Register the routes for handling broadcast channel authentication and sockets.
     */
    public function routes(?array $attributes = null): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $attributes = $attributes ?: ['middleware' => ['web']];

        /** @var Router $router */
        $router = $this->app->make('router');

        $router->group($attributes, function ($router) {
            $router->match(
                ['get', 'post'],
                '/broadcasting/auth',
                '\\' . BroadcastController::class . '@authenticate'
            )->withoutMiddleware([PreventRequestForgery::class]);
        });
    }

    /**
     * Register the routes for handling broadcast user authentication.
     */
    public function userRoutes(?array $attributes = null): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $attributes = $attributes ?: ['middleware' => ['web']];

        /** @var Router $router */
        $router = $this->app->make('router');

        $router->group($attributes, function ($router) {
            $router->match(
                ['get', 'post'],
                '/broadcasting/user-auth',
                '\\' . BroadcastController::class . '@authenticateUser'
            )->withoutMiddleware([PreventRequestForgery::class]);
        });
    }

    /**
     * Register the routes for handling broadcast authentication and sockets.
     *
     * Alias of "routes" method.
     */
    public function channelRoutes(?array $attributes = null): void
    {
        $this->routes($attributes);
    }

    /**
     * Get the socket ID for the given request.
     */
    public function socket(?Request $request = null): ?string
    {
        if (! $request && ! $this->app->bound('request')) {
            return null;
        }

        /** @var Request $request */
        $request = $request ?: $this->app->make('request');

        return $request->header('X-Socket-ID');
    }

    /**
     * Begin sending an anonymous broadcast to the given channels.
     */
    public function on(array|Channel|string $channels): AnonymousEvent
    {
        return new AnonymousEvent($channels);
    }

    /**
     * Begin sending an anonymous broadcast to the given private channels.
     */
    public function private(string $channel): AnonymousEvent
    {
        return $this->on(new PrivateChannel($channel));
    }

    /**
     * Begin sending an anonymous broadcast to the given presence channels.
     */
    public function presence(string $channel): AnonymousEvent
    {
        return $this->on(new PresenceChannel($channel));
    }

    /**
     * Begin broadcasting an event.
     */
    public function event(mixed $event = null): PendingBroadcast
    {
        return new PendingBroadcast(
            $this->app->make('events'),
            $event,
        );
    }

    /**
     * Queue the given event for broadcast.
     */
    public function queue(mixed $event): void
    {
        if ($event instanceof ShouldBroadcastNow
            || (is_object($event) && method_exists($event, 'shouldBroadcastNow') && $event->shouldBroadcastNow())
        ) {
            $dispatch = fn () => $this->app->make(Dispatcher::class)->dispatchNow(
                new BroadcastEvent($event instanceof UnitEnum ? $event : clone $event)
            );

            $event instanceof ShouldRescue
                ? $this->rescue($dispatch)
                : $dispatch();

            return;
        }

        $queue = null;

        if (method_exists($event, 'broadcastQueue')) {
            $queue = $event->broadcastQueue();
        } elseif (isset($event->broadcastQueue)) {
            $queue = $event->broadcastQueue;
        } elseif (isset($event->queue)) {
            $queue = $event->queue;
        }

        if (is_null($queue)) {
            $queue = $this->getAttributeValue($event, QueueAttribute::class, 'queue')
                ?? $this->resolveQueueFromQueueRoute($event)
                ?? null;
        }

        $broadcastEvent = $event instanceof ShouldBeUnique
            ? new UniqueBroadcastEvent($event instanceof UnitEnum ? $event : clone $event)
            : new BroadcastEvent($event instanceof UnitEnum ? $event : clone $event);

        if ($event instanceof ShouldBeUnique && $this->mustBeUniqueAndCannotAcquireLock($broadcastEvent)) {
            return;
        }

        $push = fn () => $this->app->make(Queue::class)
            ->connection(
                $event->connection
                    ?? $this->getAttributeValue($event, ConnectionAttribute::class, 'connection')
                    ?? $this->resolveConnectionFromQueueRoute($event)
                    ?? null
            )
            ->pushOn($queue, $broadcastEvent);

        $event instanceof ShouldRescue
            ? $this->rescue($push)
            : $push();
    }

    /**
     * Determine if the broadcastable event must be unique and determine if we can acquire the necessary lock.
     */
    protected function mustBeUniqueAndCannotAcquireLock(mixed $event): bool
    {
        return ! (new UniqueLock(
            method_exists($event, 'uniqueVia')
                ? $event->uniqueVia()
                : $this->app->make(Cache::class)
        ))->acquire($event);
    }

    /**
     * Get a driver instance.
     */
    public function connection(UnitEnum|string|null $driver = null): Broadcaster
    {
        return $this->driver($driver);
    }

    /**
     * Get a driver instance.
     */
    public function driver(UnitEnum|string|null $name = null): Broadcaster
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name = $name === null || $name === ''
            ? $this->getDefaultDriver()
            : $name;

        return $this->drivers[$name] = $this->get($name);
    }

    /**
     * Attempt to get the connection from the local cache.
     */
    protected function get(string $name): Broadcaster
    {
        return $this->drivers[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given broadcaster with Pool Proxy if need.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): Broadcaster
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Broadcast connection [{$name}] is not defined.");
        }

        $constructionConfig = Arr::except($config, ['pool']);

        return in_array($config['driver'], $this->poolables, true)
            ? $this->createPoolProxy(
                $config['driver'],
                fn () => $this->doResolve(null, $constructionConfig),
                $this->poolDefinition($config['driver'], $config['pool'] ?? [], $constructionConfig),
                BroadcastPoolProxy::class,
            )
            : $this->doResolve($name, $constructionConfig);
    }

    /**
     * Resolve the given broadcaster.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function doResolve(?string $name, array $config): Broadcaster
    {
        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create' . ucfirst($config['driver']) . 'Driver';

        if (! method_exists($this, $driverMethod)) {
            throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
        }

        try {
            return $this->{$driverMethod}($config);
        } catch (Throwable $e) {
            $resource = $name === null
                ? "driver \"{$config['driver']}\""
                : "connection \"{$name}\"";

            throw new RuntimeException(
                "Failed to create broadcaster for {$resource} with error: {$e->getMessage()}.",
                0,
                $e,
            );
        }
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(array $config): Broadcaster
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Create an instance of the driver.
     */
    protected function createReverbDriver(array $config): Broadcaster
    {
        return $this->createPusherDriver($config);
    }

    /**
     * Create an instance of the driver.
     */
    protected function createPusherDriver(array $config): Broadcaster
    {
        return new PusherBroadcaster(
            $this->app,
            $this->pusher($config),
            (bool) ($config['jsonp'] ?? false),
        );
    }

    /**
     * Get a Pusher instance for the given configuration.
     */
    public function pusher(array $config): Pusher
    {
        $guzzleClient = new GuzzleClient(
            array_merge(
                [
                    'connect_timeout' => 10,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    'timeout' => 30,
                ],
                $config['client_options'] ?? [],
            ),
        );

        $pusher = new Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            $config['options'] ?? [],
            $guzzleClient,
        );

        if ($config['log'] ?? false) {
            $pusher->setLogger($this->app->make(LoggerInterface::class));
        }

        return $pusher;
    }

    /**
     * Create an instance of the driver.
     */
    protected function createAblyDriver(array $config): Broadcaster
    {
        return new AblyBroadcaster($this->app, $this->ably($config));
    }

    /**
     * Get an Ably instance for the given configuration.
     */
    public function ably(array $config): AblyRest
    {
        return new AblyRest($config);
    }

    /**
     * Create an instance of the driver.
     */
    protected function createRedisDriver(array $config): Broadcaster
    {
        /** @var RedisFactory $redis */
        $redis = $this->app->make('redis');
        $connectionName = $config['connection'] ?? 'default';
        $redisConfig = $this->app->make(RedisConfig::class)->connectionConfig($connectionName);

        return new RedisBroadcaster(
            $this->app,
            $redis,
            $connectionName,
            (string) ($redisConfig['options']['prefix'] ?? ''),
        );
    }

    /**
     * Create an instance of the driver.
     */
    protected function createLogDriver(array $config): Broadcaster
    {
        return new LogBroadcaster($this->app->make(LoggerInterface::class));
    }

    /**
     * Create an instance of the driver.
     */
    protected function createNullDriver(array $config): Broadcaster
    {
        return new NullBroadcaster;
    }

    /**
     * Get the connection configuration.
     */
    protected function getConfig(string $name): ?array
    {
        if ($name !== 'null') {
            return $this->app->make('config')->get("broadcasting.connections.{$name}");
        }

        return ['driver' => 'null'];
    }

    /**
     * Get the shared object-pool factory.
     */
    protected function poolFactory(): PoolFactory
    {
        return $this->app->make(PoolFactory::class);
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app->make('config')->string('broadcasting.default');
    }

    /**
     * Set the default broadcast driver name.
     *
     * Boot-only. Mutates process-global config; per-request use races across coroutines.
     */
    public function setDefaultDriver(UnitEnum|string $name): void
    {
        $name = $name instanceof UnitEnum ? (string) enum_value($name) : $name;

        $this->app->make('config')->set('broadcasting.default', $name);
    }

    /**
     * Disconnect the given driver and remove it from the local cache.
     *
     * Boot or tests only, plus operational recovery for explicitly pooled drivers.
     * Direct drivers are only removed from the manager cache; an explicitly pooled
     * driver also invalidates its shared pool.
     */
    public function purge(UnitEnum|string|null $name = null): void
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name ??= $this->getDefaultDriver();
        $driver = $this->drivers[$name] ?? null;

        unset($this->drivers[$name]);

        if ($driver !== null) {
            if ($driver instanceof BroadcastPoolProxy) {
                $driver->invalidatePool();
            }

            return;
        }

        $config = $this->getConfig($name);

        if (is_null($config) || ! in_array($config['driver'], $this->poolables, true)) {
            return;
        }

        $constructionConfig = Arr::except($config, ['pool']);
        $definition = $this->poolDefinition(
            $config['driver'],
            $config['pool'] ?? [],
            $constructionConfig,
        );

        $this->poolFactory()->remove($definition->identity);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * Boot-only. The callback persists in the singleton's customCreators array
     * for the worker lifetime and applies to every subsequent broadcaster
     * resolution.
     */
    public function extend(string $driver, Closure $callback): static
    {
        try {
            $callback = $this->bindCallbackToSelf($callback)
                ?? throw new RuntimeException('Unable to bind custom driver callback');
        } catch (ReflectionException $e) {
            throw new RuntimeException('Unable to bind custom driver callback', previous: $e);
        }

        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Execute the given callback using "rescue" if possible.
     */
    protected function rescue(Closure $callback): mixed
    {
        return rescue($callback);
    }

    /**
     * Get the application instance used by the manager.
     */
    public function getApplication(): Container
    {
        return $this->app;
    }

    /**
     * Set the application instance used by the manager.
     *
     * Tests only. Swaps the singleton's container reference; per-request use
     * races across coroutines and breaks every concurrent request resolving
     * broadcasters through this manager.
     */
    public function setApplication(Container $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Forget all of the resolved driver instances.
     *
     * Boot or tests only. This is cache-only: pooled broadcasters remain
     * shared resources until purged or reclaimed by their idle TTL.
     */
    public function forgetDrivers(): static
    {
        $this->drivers = [];

        return $this;
    }

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->driver()->{$method}(...$parameters);
    }
}
