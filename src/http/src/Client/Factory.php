<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @mixin \Hypervel\Http\Client\PendingRequest
 */
class Factory
{
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

    /**
     * The URL patterns that are allowed as stray requests.
     */
    protected array $allowedStrayRequestUrls = [];

    /**
     * The configuration for all registered connections.
     */
    protected array $connectionConfigs = [];

    /**
     * The resolved low-level transport handlers for registered connections.
     *
     * @var array<string, callable>
     */
    protected array $connectionHandlers = [];

    /**
     * Create a new factory instance.
     */
    public function __construct(protected ?Dispatcher $dispatcher = null)
    {
        $this->stubCallbacks = new Collection;
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
     *
     * Boot-only. The options persist on the factory for the worker lifetime
     * and affect every subsequently created pending request.
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
        mixed $body = null,
        int $status = 200,
        array $headers = []
    ): PromiseInterface {
        return Create::promiseFor(
            static::psr7Response($body, $status, $headers)
        );
    }

    /**
     * Create a new PSR-7 response instance for use during stubbing.
     *
     * @throws InvalidArgumentException
     */
    public static function psr7Response(
        mixed $body = null,
        int $status = 200,
        array $headers = []
    ): Psr7Response {
        if (is_array($body)) {
            try {
                $body = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('HTTP fake response body could not be JSON encoded.', previous: $exception);
            }

            $headers['Content-Type'] = 'application/json';
        }

        if (! is_string($body) && ! is_null($body)) {
            throw new InvalidArgumentException('HTTP fake response body must be a string, array, or null.');
        }

        return new Psr7Response($status, static::normalizeResponseHeaders($headers), $body);
    }

    /**
     * Normalize the given fake response headers.
     *
     * @throws InvalidArgumentException
     */
    protected static function normalizeResponseHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                if ($value === []) {
                    $headers[$name] = '';

                    continue;
                }

                foreach ($value as $key => $item) {
                    $value[$key] = match (true) {
                        $item === null => '',
                        is_scalar($item) => static::normalizeScalarString($item),
                        $item instanceof Stringable => $item->toString(),
                        default => throw new InvalidArgumentException('HTTP fake response header values must be scalar, null, Hypervel Stringable, or arrays of scalar, null, or Hypervel Stringable values.'),
                    };
                }

                $headers[$name] = $value;

                continue;
            }

            $headers[$name] = match (true) {
                $value === null => '',
                is_scalar($value) => static::normalizeScalarString($value),
                $value instanceof Stringable => $value->toString(),
                default => throw new InvalidArgumentException('HTTP fake response header values must be scalar, null, Hypervel Stringable, or arrays of scalar, null, or Hypervel Stringable values.'),
            };
        }

        return $headers;
    }

    /**
     * Normalize a scalar to a string without triggering PHP 8.5 non-finite float warnings.
     */
    protected static function normalizeScalarString(bool|float|int|string $value): string
    {
        if (is_float($value) && ! is_finite($value)) {
            return match (true) {
                is_nan($value) => 'NAN',
                $value > 0 => 'INF',
                default => '-INF',
            };
        }

        return (string) $value;
    }

    /**
     * Create a new RequestException instance for use during stubbing.
     */
    public static function failedRequest(
        mixed $body = null,
        int $status = 200,
        array $headers = []
    ): RequestException {
        return new RequestException(new Response(static::psr7Response($body, $status, $headers)));
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

                if ($response instanceof PromiseInterface && ($options['on_stats'] ?? null) instanceof Closure) {
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
            $pattern = Str::start($url, '*');
            $requestUrl = $request->url();

            if (! Str::is($pattern, $requestUrl) && ! Str::is($pattern, Str::finish($requestUrl, '/'))) {
                return;
            }

            if (is_int($callback)) {
                if ($callback >= 100 && $callback < 600) {
                    return static::response(status: $callback);
                }

                throw new InvalidArgumentException('HTTP status code must be between 100 and 599.');
            }

            if (is_string($callback)) {
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
    public function allowStrayRequests(?array $only = null): static
    {
        if (is_null($only)) {
            $this->preventStrayRequests(false);
            $this->allowedStrayRequestUrls = [];
        } else {
            $this->allowedStrayRequestUrls = array_values($only);
        }

        return $this;
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
            return new Collection;
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
            $request
                ->stub($this->stubCallbacks)
                ->preventStrayRequests($this->preventStrayRequests)
                ->allowStrayRequests($this->allowedStrayRequestUrls);
        });
    }

    /**
     * Instantiate a new pending request instance for this factory.
     */
    protected function newPendingRequest(): PendingRequest
    {
        $options = value($this->globalOptions);

        if (! is_array($options)) {
            throw new InvalidArgumentException('The global HTTP client options callback must return an array.');
        }

        return new PendingRequest($this, $this->globalMiddleware, $options);
    }

    /**
     * Get the current event dispatcher implementation.
     */
    public function getDispatcher(): ?Dispatcher
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
     *
     * Boot-only. The connection preset and shared transport handler registry
     * persist on the factory for the worker lifetime.
     */
    public function registerConnection(string $name, array $config = []): static
    {
        return $this->setConnectionConfig($name, $config);
    }

    /**
     * Determine if the given HTTP client connection is registered.
     */
    public function hasConnection(string $name): bool
    {
        return array_key_exists($name, $this->connectionConfigs);
    }

    /**
     * Get the shared low-level transport handler for a connection.
     */
    public function getConnectionHandler(string $name): callable
    {
        $this->ensureConnectionIsRegistered($name);

        return $this->connectionHandlers[$name] ??= $this->createConnectionHandler(
            Arr::only($this->connectionConfigs[$name], ['transport_sharing'])
        );
    }

    /**
     * Create a low-level transport handler for a connection.
     */
    protected function createConnectionHandler(array $options): callable
    {
        return Utils::chooseHandler($options);
    }

    /**
     * Create new Guzzle client.
     */
    public function createClient(HandlerStack $handlerStack, CookieJar $cookies): ClientInterface
    {
        return new Client([
            'handler' => $handlerStack,
            'cookies' => $cookies,
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
     * Get the request-option preset for a registered connection.
     */
    public function getConnectionOptions(string $name): array
    {
        $this->ensureConnectionIsRegistered($name);

        return Arr::except($this->connectionConfigs[$name], ['transport_sharing']);
    }

    /**
     * Set the configuration for a specific connection.
     *
     * Boot-only. Reconfiguration replaces the worker-lifetime preset and
     * invalidates its shared transport handler for subsequent requests.
     */
    public function setConnectionConfig(string $name, array $config): static
    {
        $this->validateConnectionConfig($config);

        $this->connectionConfigs[$name] = $config;
        unset($this->connectionHandlers[$name]);

        return $this;
    }

    /**
     * Ensure the given HTTP client connection is registered.
     */
    protected function ensureConnectionIsRegistered(string $name): void
    {
        if (! $this->hasConnection($name)) {
            throw new InvalidArgumentException("Connection [{$name}] is not registered.");
        }
    }

    /**
     * Validate a registered connection configuration.
     */
    protected function validateConnectionConfig(array $config): void
    {
        ReservedOptions::reject($config, true, 'registered connection configuration');
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
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
