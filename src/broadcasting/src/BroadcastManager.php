<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Ably\AblyRest;
use Closure;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use Hyperf\Collection\Arr;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Router\DispatcherFactory as RouterDispatcherFactory;
use Hyperf\Redis\RedisFactory;
use Hypervel\Auth\AuthManager;
use Hypervel\Broadcasting\Broadcasters\AblyBroadcaster;
use Hypervel\Broadcasting\Broadcasters\LogBroadcaster;
use Hypervel\Broadcasting\Broadcasters\NullBroadcaster;
use Hypervel\Broadcasting\Broadcasters\PusherBroadcaster;
use Hypervel\Broadcasting\Broadcasters\RedisBroadcaster;
use Hypervel\Broadcasting\Contracts\Broadcaster;
use Hypervel\Broadcasting\Contracts\Factory as BroadcastingFactoryContract;
use Hypervel\Broadcasting\Contracts\HasBroadcastChannel;
use Hypervel\Broadcasting\Contracts\ShouldBeUnique;
use Hypervel\Broadcasting\Contracts\ShouldBroadcastNow;
use Hypervel\Bus\Contracts\Dispatcher;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Contracts\Factory as Cache;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\HttpMessage\Exceptions\AccessDeniedHttpException;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Queue\Contracts\Factory as Queue;
use Hypervel\Router\Contracts\UrlRoutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Reflector;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;

/**
 * @mixin \Hypervel\Broadcasting\Contracts\Broadcaster
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
     * The registered channel authenticators.
     *
     * Channels are stored here in the singleton BroadcastManager rather than
     * on individual Broadcaster instances to ensure they're shared across all
     * pooled broadcaster instances.
     */
    protected array $channels = [];

    /**
     * The registered channel options.
     */
    protected array $channelOptions = [];

    /**
     * Create a new manager instance.
     */
    public function __construct(
        protected ContainerInterface $app,
    ) {
    }

    /**
     * Register the routes for handling broadcast channel authentication and sockets.
     */
    public function routes(array $attributes = []): void
    {
        if ($this->app->has(Kernel::class)) {
            $attributes = $attributes ?: ['middleware' => ['web']];
        }

        $kernels = $this->app->get(ConfigInterface::class)
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
        $attributes = $attributes ?: ['middleware' => ['web']];

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
     * Register a channel authenticator.
     *
     * We handle this here instead of proxying to the driver to ensure channels are
     * stored in the singleton manager and shared across all pooled broadcaster instances.
     */
    public function channel(HasBroadcastChannel|string $channel, callable|string $callback, array $options = []): static
    {
        if ($channel instanceof HasBroadcastChannel) {
            $channel = $channel->broadcastChannelRoute();
        } elseif (is_string($channel) && class_exists($channel) && is_a($channel, HasBroadcastChannel::class, true)) {
            $channel = (new $channel())->broadcastChannelRoute();
        }

        $this->channels[$channel] = $callback;
        $this->channelOptions[$channel] = $options;

        return $this;
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * Authorization logic is here in order to access the shared channels array.
     * Only signature generation is delegated to the driver.
     *
     * @throws AccessDeniedHttpException
     */
    public function auth(RequestInterface $request): mixed
    {
        $channelName = $request->input('channel_name');

        if (empty($channelName)) {
            throw new AccessDeniedHttpException();
        }

        $normalizedChannel = $this->normalizeChannelName($channelName);

        // Check if this is a guarded channel (private or presence)
        if ($this->isGuardedChannel($channelName)) {
            $user = $this->retrieveUser($normalizedChannel);
            if (! $user) {
                throw new AccessDeniedHttpException();
            }
        }

        // Verify the user can access this channel using the registered callbacks
        return $this->verifyUserCanAccessChannel($request, $normalizedChannel);
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @throws AccessDeniedHttpException
     */
    protected function verifyUserCanAccessChannel(RequestInterface $request, string $channel): mixed
    {
        foreach ($this->channels as $pattern => $callback) {
            if (! $this->channelNameMatchesPattern($channel, $pattern)) {
                continue;
            }

            $parameters = $this->extractAuthParameters($pattern, $channel, $callback);
            $handler = $this->normalizeChannelHandlerToCallable($callback);
            $result = $handler($this->retrieveUser($channel), ...$parameters);

            if ($result === false) {
                throw new AccessDeniedHttpException();
            }

            if ($result) {
                // Delegate signature generation to the driver (which may be pooled)
                return $this->driver()->validAuthenticationResponse($request, $result);
            }
        }

        throw new AccessDeniedHttpException();
    }

    /**
     * Normalize the channel name by removing private-/presence- prefix.
     */
    protected function normalizeChannelName(string $channelName): string
    {
        if (str_starts_with($channelName, 'private-')) {
            return substr($channelName, 8);
        }

        if (str_starts_with($channelName, 'presence-')) {
            return substr($channelName, 9);
        }

        return $channelName;
    }

    /**
     * Determine if the channel is a private or presence channel.
     */
    protected function isGuardedChannel(string $channelName): bool
    {
        return str_starts_with($channelName, 'private-')
            || str_starts_with($channelName, 'presence-');
    }

    /**
     * Extract the parameters from the given pattern and channel.
     */
    protected function extractAuthParameters(string $pattern, string $channel, callable|string $callback): array
    {
        $callbackParameters = $this->extractParameters($callback);

        return collect($this->extractChannelKeys($pattern, $channel))
            ->reject(fn ($value, $key) => is_numeric($key))
            ->map(fn ($value, $key) => $this->resolveBinding($key, $value, $callbackParameters))
            ->values()
            ->all();
    }

    /**
     * Extracts the parameters out of what the user passed to handle the channel authentication.
     *
     * @return ReflectionParameter[]
     */
    protected function extractParameters(callable|string $callback): array
    {
        return match (true) {
            is_callable($callback) => (new ReflectionFunction($callback))->getParameters(),
            is_string($callback) => $this->extractParametersFromClass($callback),
            default => [],
        };
    }

    /**
     * Extracts the parameters out of a class channel's "join" method.
     *
     * @return ReflectionParameter[]
     *
     * @throws Exception
     */
    protected function extractParametersFromClass(string $callback): array
    {
        $reflection = new ReflectionClass($callback);

        if (! $reflection->hasMethod('join')) {
            throw new Exception('Class based channel must define a "join" method.');
        }

        return $reflection->getMethod('join')->getParameters();
    }

    /**
     * Extract the channel keys from the incoming channel name.
     */
    protected function extractChannelKeys(string $pattern, string $channel): array
    {
        preg_match('/^' . preg_replace('/\{(.*?)\}/', '(?<$1>[^\.]+)', $pattern) . '/', $channel, $keys);

        return $keys;
    }

    /**
     * Resolve the given parameter binding.
     */
    protected function resolveBinding(string $key, string $value, array $callbackParameters): mixed
    {
        return $this->resolveImplicitBindingIfPossible($key, $value, $callbackParameters);
    }

    /**
     * Resolve an implicit parameter binding if applicable.
     *
     * @throws AccessDeniedHttpException
     */
    protected function resolveImplicitBindingIfPossible(string $key, string $value, array $callbackParameters): mixed
    {
        foreach ($callbackParameters as $parameter) {
            if (! $this->isImplicitlyBindable($key, $parameter)) {
                continue;
            }

            $className = Reflector::getParameterClassName($parameter);

            if (is_null($model = (new $className())->resolveRouteBinding($value))) {
                throw new AccessDeniedHttpException();
            }

            return $model;
        }

        return $value;
    }

    /**
     * Determine if a given key and parameter is implicitly bindable.
     */
    protected function isImplicitlyBindable(string $key, ReflectionParameter $parameter): bool
    {
        return $parameter->getName() === $key
            && Reflector::isParameterSubclassOf($parameter, UrlRoutable::class);
    }

    /**
     * Normalize the given callback into a callable.
     */
    protected function normalizeChannelHandlerToCallable(callable|string $callback): callable
    {
        return is_callable($callback) ? $callback : function (...$args) use ($callback) {
            return $this->app->get($callback)->join(...$args);
        };
    }

    /**
     * Retrieve the authenticated user using the configured guard (if any).
     */
    protected function retrieveUser(string $channel): mixed
    {
        $options = $this->retrieveChannelOptions($channel);
        $guards = $options['guards'] ?? null;

        $auth = $this->app->get(AuthManager::class);

        if (is_null($guards)) {
            return $auth->user();
        }

        foreach (Arr::wrap($guards) as $guard) {
            $user = $auth->guard($guard)->user();
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Retrieve options for a certain channel.
     */
    protected function retrieveChannelOptions(string $channel): array
    {
        foreach ($this->channelOptions as $pattern => $options) {
            if (! $this->channelNameMatchesPattern($channel, $pattern)) {
                continue;
            }

            return $options;
        }

        return [];
    }

    /**
     * Check if the channel name from the request matches a pattern from registered channels.
     */
    protected function channelNameMatchesPattern(string $channel, string $pattern): bool
    {
        return (bool) preg_match('/^' . preg_replace('/\{(.*?)\}/', '([^\.]+)', $pattern) . '$/', $channel);
    }

    /**
     * Get all of the registered channels.
     */
    public function getChannels(): Collection
    {
        return Collection::make($this->channels);
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
        return ! (new UniqueLock(
            method_exists($event, 'uniqueVia')
                ? $event->uniqueVia()
                : $this->app->get(Cache::class)
        ))->acquire($event);
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
            $this->app->get(ConfigInterface::class)->get('database.redis.options.prefix', ''),
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
        if (! is_null($name) && $name !== 'null') {
            return $this->app->get(ConfigInterface::class)->get("broadcasting.connections.{$name}");
        }

        return ['driver' => 'null'];
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app->get(ConfigInterface::class)->get('broadcasting.default');
    }

    /**
     * Set the default driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->app->get(ConfigInterface::class)->set('broadcasting.default', $name);
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
    public function getApplication(): ContainerInterface
    {
        return $this->app;
    }

    /**
     * Set the application instance used by the manager.
     */
    public function setApplication(ContainerInterface $app): static
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
