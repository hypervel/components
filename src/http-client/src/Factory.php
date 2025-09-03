<?php

declare(strict_types=1);

namespace Hypervel\HttpClient;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Hyperf\Macroable\Macroable;
use Hyperf\Stringable\Str;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function Hyperf\Tappable\tap;

/**
 * @mixin \Hypervel\HttpClient\PendingRequest
 */
class Factory
{
    use HasPoolProxy;
    use Macroable {
        __call as macroCall;
    }

    /**
     * The middleware to apply to every request.
     */
    protected array $globalMiddleware = [];

    /**
     * The options to apply to every request.
     */
    protected array|Closure $globalOptions = [];

    /**
     * The stub callables that will handle requests.
     */
    protected Collection $stubCallbacks;

    /**
     * Indicates if the factory is recording requests and responses.
     */
    protected bool $recording = false;

    /**
     * The recorded response array.
     */
    protected array $recorded = [];

    /**
     * All created response sequences.
     */
    protected array $responseSequences = [];

    /**
     * Indicates that an exception should be thrown if any request is not faked.
     */
    protected bool $preventStrayRequests = false;

    protected string $poolProxyClass = ClientPoolProxy::class;

    /**
     * The array of connections which will be wrapped as pool proxies.
     */
    protected array $poolables = [];

    /**
     * The array of resolved client connections.
     */
    protected array $connections = [];

    /**
     * The configuration for all registered connections.
     */
    protected array $connectionConfigs = [];

    /**
     * Create a new factory instance.
     */
    public function __construct(protected ?EventDispatcherInterface $dispatcher = null)
    {
        $this->stubCallbacks = new Collection();
    }

    /**
     * Add middleware to apply to every request.
     */
    public function globalMiddleware(callable $middleware): static
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    /**
     * Add request middleware to apply to every request.
     */
    public function globalRequestMiddleware(callable $middleware): static
    {
        $this->globalMiddleware[] = Middleware::mapRequest($middleware);

        return $this;
    }

    /**
     * Add response middleware to apply to every request.
     */
    public function globalResponseMiddleware(callable $middleware): static
    {
        $this->globalMiddleware[] = Middleware::mapResponse($middleware);

        return $this;
    }

    /**
     * Set the options to apply to every request.
     */
    public function globalOptions(array|Closure $options): static
    {
        $this->globalOptions = $options;

        return $this;
    }

    /**
     * Create a new response instance for use during stubbing.
     */
    public static function response(
        array|callable|int|PromiseInterface|Response|string|null $body = null,
        int $status = 200,
        array $headers = []
    ): PromiseInterface {
        if (is_array($body)) {
            $body = json_encode($body);

            $headers['Content-Type'] = 'application/json';
        }

        $response = new Psr7Response($status, $headers, $body);

        return Create::promiseFor($response);
    }

    /**
     * Create a new connection exception for use during stubbing.
     */
    public static function failedConnection(?string $message = null): Closure
    {
        return function ($request) use ($message) {
            return Create::rejectionFor(
                new ConnectException(
                    $message ?? "cURL error 6: Could not resolve host: {$request->toPsrRequest()->getUri()->getHost()} (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$request->toPsrRequest()->getUri()}.",
                    $request->toPsrRequest(),
                )
            );
        };
    }

    /**
     * Get an invokable object that returns a sequence of responses in order for use during stubbing.
     */
    public function sequence(array $responses = []): ResponseSequence
    {
        return $this->responseSequences[] = new ResponseSequence($responses);
    }

    /**
     * Register a stub callable that will intercept requests and be able to return stub responses.
     */
    public function fake(array|callable|null $callback = null): static
    {
        $this->record();

        $this->recorded = [];

        if (is_null($callback)) {
            $callback = function () {
                return static::response();
            };
        }

        if (is_array($callback)) {
            foreach ($callback as $url => $callable) {
                $this->stubUrl($url, $callable);
            }

            return $this;
        }
        $this->stubCallbacks = $this->stubCallbacks->merge(new Collection([
            function ($request, $options) use ($callback) {
                $response = $callback;

                while ($response instanceof Closure) {
                    $response = $response($request, $options);
                }

                if ($response instanceof PromiseInterface) {
                    $options['on_stats'](
                        new TransferStats(
                            $request->toPsrRequest(),
                            $response->wait(),
                        )
                    );
                }

                return $response;
            },
        ]));

        return $this;
    }

    /**
     * Register a response sequence for the given URL pattern.
     */
    public function fakeSequence(string $url = '*'): ResponseSequence
    {
        return tap($this->sequence(), function ($sequence) use ($url) {
            $this->fake([$url => $sequence]);
        });
    }

    /**
     * Stub the given URL using the given callback.
     */
    public function stubUrl(string $url, array|callable|int|PromiseInterface|Response|string $callback): static
    {
        return $this->fake(function ($request, $options) use ($url, $callback) {
            if (! Str::is(Str::start($url, '*'), $request->url())) {
                return;
            }

            if (is_int($callback) && $callback >= 100 && $callback < 600) {
                return static::response(status: $callback);
            }

            if (is_int($callback) || is_string($callback)) {
                return static::response($callback);
            }

            if ($callback instanceof Closure || $callback instanceof ResponseSequence) {
                return $callback($request, $options);
            }

            return $callback;
        });
    }

    /**
     * Indicate that an exception should be thrown if any request is not faked.
     */
    public function preventStrayRequests(bool $prevent = true): static
    {
        $this->preventStrayRequests = $prevent;

        return $this;
    }

    /**
     * Determine if stray requests are being prevented.
     */
    public function preventingStrayRequests(): bool
    {
        return $this->preventStrayRequests;
    }

    /**
     * Indicate that an exception should not be thrown if any request is not faked.
     */
    public function allowStrayRequests(): static
    {
        return $this->preventStrayRequests(false);
    }

    /**
     * Begin recording request / response pairs.
     */
    protected function record(): static
    {
        $this->recording = true;

        return $this;
    }

    /**
     * Record a request response pair.
     */
    public function recordRequestResponsePair(Request $request, ?Response $response): void
    {
        if ($this->recording) {
            $this->recorded[] = [$request, $response];
        }
    }

    /**
     * Assert that a request / response pair was recorded matching a given truth test.
     */
    public function assertSent(callable $callback): void
    {
        PHPUnit::assertTrue(
            $this->recorded($callback)->count() > 0,
            'An expected request was not recorded.'
        );
    }

    /**
     * Assert that the given request was sent in the given order.
     */
    public function assertSentInOrder(array $callbacks): void
    {
        $this->assertSentCount(count($callbacks));

        foreach ($callbacks as $index => $url) {
            $callback = is_callable($url) ? $url : function ($request) use ($url) {
                return $request->url() == $url;
            };

            PHPUnit::assertTrue(
                $callback(
                    $this->recorded[$index][0],
                    $this->recorded[$index][1]
                ),
                'An expected request (#' . ($index + 1) . ') was not recorded.'
            );
        }
    }

    /**
     * Assert that a request / response pair was not recorded matching a given truth test.
     */
    public function assertNotSent(callable $callback): void
    {
        PHPUnit::assertFalse(
            $this->recorded($callback)->count() > 0,
            'Unexpected request was recorded.'
        );
    }

    /**
     * Assert that no request / response pair was recorded.
     */
    public function assertNothingSent(): void
    {
        PHPUnit::assertEmpty(
            $this->recorded,
            'Requests were recorded.'
        );
    }

    /**
     * Assert how many requests have been recorded.
     */
    public function assertSentCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->recorded);
    }

    /**
     * Assert that every created response sequence is empty.
     */
    public function assertSequencesAreEmpty(): void
    {
        foreach ($this->responseSequences as $responseSequence) {
            PHPUnit::assertTrue(
                $responseSequence->isEmpty(),
                'Not all response sequences are empty.'
            );
        }
    }

    /**
     * Get a collection of the request / response pairs matching the given truth test.
     */
    public function recorded(?callable $callback = null): Collection
    {
        if (empty($this->recorded)) {
            return new Collection();
        }

        $callback = $callback ?: function () {
            return true;
        };

        return (new Collection($this->recorded))
            ->filter(fn ($pair) => $callback($pair[0], $pair[1]));
    }

    /**
     * Create a new pending request instance for this factory.
     */
    public function createPendingRequest(): PendingRequest
    {
        return tap($this->newPendingRequest(), function (PendingRequest $request) {
            $request->stub($this->stubCallbacks)->preventStrayRequests($this->preventStrayRequests);
        });
    }

    /**
     * Instantiate a new pending request instance for this factory.
     */
    protected function newPendingRequest(): PendingRequest
    {
        return (new PendingRequest($this, $this->globalMiddleware))->withOptions(value($this->globalOptions));
    }

    /**
     * Get the current event dispatcher implementation.
     */
    public function getDispatcher(): ?EventDispatcherInterface
    {
        return $this->dispatcher;
    }

    /**
     * Get the array of global middleware.
     */
    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * Register a connection with the given name and configuration.
     */
    public function registerConnection(string $name, array $config = []): static
    {
        $this->addPoolable($name);
        $this->setConnectionConfig($name, $config);

        return $this;
    }

    /**
     * Get the HTTP client for the specified connection.
     *
     * @throws Throwable
     */
    public function getClient(?string $connection, HandlerStack $handlerStack, ?array $config = null): ClientInterface
    {
        return $this->resolve($connection, $handlerStack, $config);
    }

    /**
     * Resolve the HTTP client for the specified connection.
     *
     * @throws Throwable
     */
    protected function resolve(?string $name, HandlerStack $handlerStack, ?array $config = null): ClientInterface
    {
        if (is_null($name)) {
            return $this->createClient($handlerStack);
        }

        if (! in_array($name, $this->poolables)) {
            throw new InvalidArgumentException("Connection [{$name}] is not registered.");
        }

        return $this->connections[$name] ??= $this->createPoolProxy(
            $name,
            fn () => $this->createClient($handlerStack),
            $config ?? $this->getConnectionConfig($name)
        );
    }

    /**
     * Create new Guzzle client.
     */
    public function createClient(HandlerStack $handlerStack): ClientInterface
    {
        return new Client([
            'handler' => $handlerStack,
            'cookies' => true,
        ]);
    }

    /**
     * Get the configuration for all connections.
     */
    public function getConnectionConfigs(): array
    {
        return $this->connectionConfigs;
    }

    /**
     * Get the configuration for a specific connection.
     */
    public function getConnectionConfig(string $name): array
    {
        return $this->connectionConfigs[$name] ?? [];
    }

    /**
     * Set the configuration for a specific connection.
     */
    public function setConnectionConfig(string $name, array $config): static
    {
        $this->connectionConfigs[$name] = $config;

        return $this;
    }

    /**
     * Execute a method against a new pending request instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->createPendingRequest()->{$method}(...$parameters);
    }
}
