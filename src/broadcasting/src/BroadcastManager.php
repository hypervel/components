<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Ably\AblyRest;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Router\DispatcherFactory as RouterDispatcherFactory;
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
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Cache\Factory as Cache;
use Hypervel\Contracts\Queue\Factory as Queue;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Foundation\Http\Middleware\VerifyCsrfToken;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Redis\RedisFactory;
use InvalidArgumentException;
use Hypervel\Contracts\Container\Container;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;

/**
 * @mixin \Hypervel\Broadcasting\Broadcasters\Broadcaster
 */
class BroadcastManager implements BroadcastingFactoryContract
{
    use HasPoolProxy;

    /**
     * The array of resolved broadcast drivers.
     */
    protected array $drivers = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * The pool proxy class.
     */
    protected string $poolProxyClass = BroadcastPoolProxy::class;

    /**
     * The array of drivers which will be wrapped as pool proxies.
     */
    protected array $poolables = ['ably', 'pusher'];

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
    public function routes(array $attributes = []): void
    {
        if ($this->app->has(Kernel::class)) {
            $attributes = $attributes ?: [
                'middleware' => ['web'],
                'without_middleware' => [VerifyCsrfToken::class],
            ];
        }

        $kernels = $this->app->get('config')
            ->get('server.kernels', []);
        foreach (array_keys($kernels) as $kernel) {
            $this->app->get(RouterDispatcherFactory::class)
                ->getRouter($kernel)
                ->addRoute(
                    ['GET', 'POST'],
                    '/broadcasting/auth',
                    [BroadcastController::class, 'authenticate'],
                    $attributes,
                );
        }
    }

    /**
     * Register the routes for handling broadcast user authentication.
     */
    public function userRoutes(?array $attributes = null): void
    {
        $attributes = $attributes ?: [
            'middleware' => ['web'],
            'without_middleware' => [VerifyCsrfToken::class],
        ];

        $this->app->get(RouterDispatcherFactory::class)->getRouter()
            ->addRoute(
                ['GET', 'POST'],
                '/broadcasting/user-auth',
                [BroadcastController::class, 'authenticateUser'],
                $attributes,
            );
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
    public function socket(?RequestInterface $request = null): ?string
    {
        $request ??= $this->app->get(RequestInterface::class);

        return $request?->header('X-Socket-ID');
    }

    /**
     * Begin sending an anonymous broadcast to the given channels.
     */
    public function on(array|Channel|string $channels): AnonymousEvent
    {
        return new AnonymousEvent($this, $channels);
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
            $this->app->get(EventDispatcherInterface::class),
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
            $this->app->get(Dispatcher::class)->dispatchNow(new BroadcastEvent(clone $event));
            return;
        }

        $queue = match (true) {
            method_exists($event, 'broadcastQueue') => $event->broadcastQueue(),
            isset($event->broadcastQueue) => $event->broadcastQueue,
            isset($event->queue) => $event->queue,
            default => null,
        };

        $broadcastEvent = new BroadcastEvent(clone $event);

        if ($event instanceof ShouldBeUnique) {
            $broadcastEvent = new UniqueBroadcastEvent(clone $event);

            if ($this->mustBeUniqueAndCannotAcquireLock($broadcastEvent)) {
                return;
            }
        }

        $this->app->get(Queue::class)
            ->connection($event->connection ?? null)
            ->pushOn($queue, $broadcastEvent);
    }

    /**
     * Determine if the broadcastable event must be unique and determine if we can acquire the necessary lock.
     */
    protected function mustBeUniqueAndCannotAcquireLock(UniqueBroadcastEvent $event): bool
    {
        return ! (new UniqueLock($event->uniqueVia()))->acquire($event);
    }

    /**
     * Get a driver instance.
     */
    public function connection(?string $driver = null): Broadcaster
    {
        return $this->driver($driver);
    }

    /**
     * Get a driver instance.
     */
    public function driver(?string $name = null): Broadcaster
    {
        $name = $name ?: $this->getDefaultDriver();

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

        return in_array($config['driver'], $this->poolables)
            ? $this->createPoolProxy(
                $name,
                fn () => $this->doResolve($config),
                $config['pool'] ?? []
            )
            : $this->doResolve($config);
    }

    /**
     * Resolve the given broadcaster.
     *
     * @throws InvalidArgumentException
     */
    protected function doResolve(array $config): Broadcaster
    {
        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create' . ucfirst($config['driver']) . 'Driver';

        if (! method_exists($this, $driverMethod)) {
            throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
        }

        return $this->{$driverMethod}($config);
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
        return new PusherBroadcaster($this->app, $this->pusher($config));
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
            $pusher->setLogger($this->app->get(LoggerInterface::class));
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
        return new RedisBroadcaster(
            $this->app,
            $this->app->get(RedisFactory::class),
            $config['connection'] ?? 'default',
            $this->app->get('config')->get('database.redis.options.prefix', ''),
        );
    }

    /**
     * Create an instance of the driver.
     */
    protected function createLogDriver(array $config): Broadcaster
    {
        return new LogBroadcaster($this->app->get(LoggerInterface::class));
    }

    /**
     * Create an instance of the driver.
     */
    protected function createNullDriver(array $config): Broadcaster
    {
        return new NullBroadcaster();
    }

    /**
     * Get the connection configuration.
     */
    protected function getConfig(string $name): ?array
    {
        if ($name !== 'null') {
            return $this->app->get('config')->get("broadcasting.connections.{$name}");
        }

        return ['driver' => 'null'];
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app->get('config')->get('broadcasting.default');
    }

    /**
     * Set the default driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->app->get('config')->set('broadcasting.default', $name);
    }

    /**
     * Disconnect the given disk and remove from local cache.
     */
    public function purge(?string $name = null): void
    {
        $name ??= $this->getDefaultDriver();

        unset($this->drivers[$name]);
    }

    /**
     * Register a custom driver creator Closure.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
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
     */
    public function setApplication(Container $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Forget all of the resolved driver instances.
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
