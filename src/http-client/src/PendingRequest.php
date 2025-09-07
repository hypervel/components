<?php

declare(strict_types=1);

namespace Hypervel\HttpClient;

use Closure;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use GuzzleHttp\UriTemplate\UriTemplate;
use Hyperf\Conditionable\Conditionable;
use Hyperf\Contract\Arrayable;
use Hyperf\Macroable\Macroable;
use Hyperf\Stringable\Str;
use Hyperf\Stringable\Stringable;
use Hypervel\HttpClient\Events\ConnectionFailed;
use Hypervel\HttpClient\Events\RequestSending;
use Hypervel\HttpClient\Events\ResponseReceived;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use JsonSerializable;
use OutOfBoundsException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\VarDumper\VarDumper;
use Throwable;

class PendingRequest
{
    use Conditionable;
    use Macroable;

    /**
     * The Guzzle client instance.
     */
    protected ?ClientInterface $client = null;

    /**
     * The Guzzle HTTP handler.
     *
     * @var callable
     */
    protected $handler;

    /**
     * The base URL for the request.
     */
    protected string $baseUrl = '';

    /**
     * The parameters that can be substituted into the URL.
     */
    protected array $urlParameters = [];

    /**
     * The request body format.
     */
    protected string $bodyFormat;

    /**
     * The raw body for the request.
     */
    protected StreamInterface|string|null $pendingBody = null;

    /**
     * The pending files for the request.
     */
    protected array $pendingFiles = [];

    /**
     * The request cookies.
     */
    protected ?CookieJar $cookies = null;

    /**
     * The transfer stats for the request.
     */
    protected ?TransferStats $transferStats = null;

    /**
     * The request options.
     */
    protected array $options = [];

    /**
     * A callback to run when throwing if a server or client error occurs.
     */
    protected ?Closure $throwCallback = null;

    /**
     * A callback to check if an exception should be thrown when a server or client error occurs.
     */
    protected ?Closure $throwIfCallback = null;

    /**
     * The number of times to try the request.
     */
    protected array|int $tries = 1;

    /**
     * The number of milliseconds to wait between retries.
     */
    protected Closure|int $retryDelay = 100;

    /**
     * Whether to throw an exception when all retries fail.
     */
    protected bool $retryThrow = true;

    /**
     * The callback that will determine if the request should be retried.
     *
     * @var null|callable
     */
    protected $retryWhenCallback;

    /**
     * The callbacks that should execute before the request is sent.
     */
    protected Collection $beforeSendingCallbacks;

    /**
     * The stub callables that will handle requests.
     */
    protected ?Collection $stubCallbacks = null;

    /**
     * Indicates that an exception should be thrown if any request is not faked.
     */
    protected bool $preventStrayRequests = false;

    /**
     * The middleware callables added by users that will handle requests.
     */
    protected Collection $middleware;

    /**
     * Whether the requests should be asynchronous.
     */
    protected bool $async = false;

    /**
     * The pending request promise.
     */
    protected ?PromiseInterface $promise;

    /**
     * The sent request object, if a request has been made.
     */
    protected ?Request $request;

    /**
     * The current connection name for the pending request.
     */
    protected ?string $connection = null;

    /**
     * The current connection configuration for the pending request.
     */
    protected ?array $connectionConfig = null;

    /**
     * The Guzzle request options that are mergeable via array_merge_recursive.
     */
    protected array $mergeableOptions = [
        'cookies',
        'form_params',
        'headers',
        'json',
        'multipart',
        'query',
    ];

    /**
     * Create a new HTTP Client instance.
     */
    public function __construct(
        protected ?Factory $factory = null,
        array $middleware = []
    ) {
        $this->middleware = new Collection($middleware);

        $this->asJson();

        $this->options = [
            'connect_timeout' => 10,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            'http_errors' => false,
            'timeout' => 30,
        ];

        $this->beforeSendingCallbacks = new Collection([
            function (Request $request, array $options, PendingRequest $pendingRequest) {
                $pendingRequest->request = $request;
                $pendingRequest->cookies = $options['cookies'];

                $pendingRequest->dispatchRequestSendingEvent();
            },
        ]);
    }

    /**
     * Set the base URL for the pending request.
     */
    public function baseUrl(string $url): static
    {
        $this->baseUrl = $url;

        return $this;
    }

    /**
     * Attach a raw body to the request.
     */
    public function withBody(StreamInterface|string $content, string $contentType = 'application/json'): static
    {
        $this->bodyFormat('body');

        $this->pendingBody = $content;

        $this->contentType($contentType);

        return $this;
    }

    /**
     * Indicate the request contains JSON.
     */
    public function asJson(): static
    {
        return $this->bodyFormat('json')->contentType('application/json');
    }

    /**
     * Indicate the request contains form parameters.
     */
    public function asForm(): static
    {
        return $this->bodyFormat('form_params')->contentType('application/x-www-form-urlencoded');
    }

    /**
     * Attach a file to the request.
     *
     * @param resource|string $contents
     */
    public function attach(
        array|string $name,
        $contents = '',
        ?string $filename = null,
        array $headers = []
    ): static {
        if (is_array($name)) {
            foreach ($name as $file) {
                $this->attach(...$file);
            }

            return $this;
        }

        $this->asMultipart();

        $this->pendingFiles[] = array_filter([
            'name' => $name,
            'contents' => $contents,
            'headers' => $headers,
            'filename' => $filename,
        ]);

        return $this;
    }

    /**
     * Indicate the request is a multi-part form request.
     */
    public function asMultipart(): static
    {
        return $this->bodyFormat('multipart');
    }

    /**
     * Specify the body format of the request.
     */
    public function bodyFormat(string $format): static
    {
        return tap($this, function () use ($format) {
            $this->bodyFormat = $format;
        });
    }

    /**
     * Set the given query parameters in the request URI.
     */
    public function withQueryParameters(array $parameters): static
    {
        return tap($this, function () use ($parameters) {
            $this->options = array_merge_recursive($this->options, [
                'query' => $parameters,
            ]);
        });
    }

    /**
     * Specify the request's content type.
     */
    public function contentType(string $contentType): static
    {
        $this->options['headers']['Content-Type'] = $contentType;

        return $this;
    }

    /**
     * Indicate that JSON should be returned by the server.
     */
    public function acceptJson(): static
    {
        return $this->accept('application/json');
    }

    /**
     * Indicate the type of content that should be returned by the server.
     */
    public function accept(string $contentType): static
    {
        return $this->withHeaders(['Accept' => $contentType]);
    }

    /**
     * Add the given headers to the request.
     */
    public function withHeaders(array $headers): static
    {
        return tap($this, function () use ($headers) {
            $this->options = array_merge_recursive($this->options, [
                'headers' => $headers,
            ]);
        });
    }

    /**
     * Add the given header to the request.
     */
    public function withHeader(string $name, mixed $value): static
    {
        return $this->withHeaders([$name => $value]);
    }

    /**
     * Replace the given headers on the request.
     */
    public function replaceHeaders(array $headers): static
    {
        $this->options['headers'] = array_merge($this->options['headers'] ?? [], $headers);

        return $this;
    }

    /**
     * Specify the basic authentication username and password for the request.
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return tap($this, function () use ($username, $password) {
            $this->options['auth'] = [$username, $password];
        });
    }

    /**
     * Specify the digest authentication username and password for the request.
     */
    public function withDigestAuth(string $username, string $password): static
    {
        return tap($this, function () use ($username, $password) {
            $this->options['auth'] = [$username, $password, 'digest'];
        });
    }

    /**
     * Specify an authorization token for the request.
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return tap($this, function () use ($token, $type) {
            $this->options['headers']['Authorization'] = trim($type . ' ' . $token);
        });
    }

    /**
     * Specify the user agent for the request.
     */
    public function withUserAgent(bool|string $userAgent): static
    {
        return tap($this, function () use ($userAgent) {
            $this->options['headers']['User-Agent'] = trim($userAgent);
        });
    }

    /**
     * Specify the URL parameters that can be substituted into the request URL.
     */
    public function withUrlParameters(array $parameters = []): static
    {
        return tap($this, function () use ($parameters) {
            $this->urlParameters = $parameters;
        });
    }

    /**
     * Specify the cookies that should be included with the request.
     */
    public function withCookies(array $cookies, string $domain): static
    {
        return tap($this, function () use ($cookies, $domain) {
            $this->options = array_merge_recursive($this->options, [
                'cookies' => CookieJar::fromArray($cookies, $domain),
            ]);
        });
    }

    /**
     * Specify the maximum number of redirects to allow.
     */
    public function maxRedirects(int $max): static
    {
        return tap($this, function () use ($max) {
            $this->options['allow_redirects']['max'] = $max;
        });
    }

    /**
     * Indicate that redirects should not be followed.
     */
    public function withoutRedirecting(): static
    {
        return tap($this, function () {
            $this->options['allow_redirects'] = false;
        });
    }

    /**
     * Indicate that TLS certificates should not be verified.
     */
    public function withoutVerifying(): static
    {
        return tap($this, function () {
            $this->options['verify'] = false;
        });
    }

    /**
     * Specify the path where the body of the response should be stored.
     *
     * @param resource|string $to
     */
    public function sink($to): static
    {
        return tap($this, function () use ($to) {
            $this->options['sink'] = $to;
        });
    }

    /**
     * Specify the timeout (in seconds) for the request.
     */
    public function timeout(float|int $seconds): static
    {
        return tap($this, function () use ($seconds) {
            $this->options['timeout'] = $seconds;
        });
    }

    /**
     * Specify the connect timeout (in seconds) for the request.
     */
    public function connectTimeout(float|int $seconds): static
    {
        return tap($this, function () use ($seconds) {
            $this->options['connect_timeout'] = $seconds;
        });
    }

    /**
     * Specify the number of times the request should be attempted.
     */
    public function retry(
        array|int $times,
        Closure|int $sleepMilliseconds = 0,
        ?callable $when = null,
        bool $throw = true
    ): static {
        $this->tries = $times;
        $this->retryDelay = $sleepMilliseconds;
        $this->retryThrow = $throw;
        $this->retryWhenCallback = $when;

        return $this;
    }

    /**
     * Replace the specified options on the request.
     */
    public function withOptions(array $options): static
    {
        return tap($this, function () use ($options) {
            $this->options = array_replace_recursive(
                array_merge_recursive($this->options, Arr::only($options, $this->mergeableOptions)),
                $options
            );
        });
    }

    /**
     * Add new middleware the client handler stack.
     */
    public function withMiddleware(callable $middleware): static
    {
        $this->middleware->push($middleware);

        return $this;
    }

    /**
     * Add new request middleware the client handler stack.
     */
    public function withRequestMiddleware(callable $middleware): static
    {
        $this->middleware->push(Middleware::mapRequest($middleware));

        return $this;
    }

    /**
     * Add new response middleware the client handler stack.
     */
    public function withResponseMiddleware(callable $middleware): static
    {
        $this->middleware->push(Middleware::mapResponse($middleware));

        return $this;
    }

    /**
     * Add a new "before sending" callback to the request.
     */
    public function beforeSending(callable $callback): static
    {
        return tap($this, function () use ($callback) {
            $this->beforeSendingCallbacks[] = $callback;
        });
    }

    /**
     * Throw an exception if a server or client error occurs.
     */
    public function throw(?callable $callback = null): static
    {
        $this->throwCallback = $callback ?: fn () => null;

        return $this;
    }

    /**
     * Throw an exception if a server or client error occurred and the given condition evaluates to true.
     */
    public function throwIf(bool|callable $condition): static
    {
        if (is_callable($condition)) {
            $this->throwIfCallback = $condition;
        }

        return $condition ? $this->throw(func_get_args()[1] ?? null) : $this;
    }

    /**
     * Throw an exception if a server or client error occurred and the given condition evaluates to false.
     */
    public function throwUnless(bool|callable $condition): static
    {
        return $this->throwIf(! $condition);
    }

    /**
     * Dump the request before sending.
     */
    public function dump(): static
    {
        $values = func_get_args();

        return $this->beforeSending(function (Request $request, array $options) use ($values) {
            foreach (array_merge($values, [$request, $options]) as $value) {
                VarDumper::dump($value);
            }
        });
    }

    /**
     * Dump the request before sending and end the script.
     */
    public function dd(): static
    {
        $values = func_get_args();

        return $this->beforeSending(function (Request $request, array $options) use ($values) {
            foreach (array_merge($values, [$request, $options]) as $value) {
                VarDumper::dump($value);
            }

            exit(1);
        });
    }

    /**
     * Issue a GET request to the given URL.
     *
     * @throws ConnectionException
     */
    public function get(string $url, array|JsonSerializable|string|null $query = null): PromiseInterface|Response
    {
        return $this->send(
            'GET',
            $url,
            func_num_args() === 1 ? [] : [
                'query' => $query,
            ]
        );
    }

    /**
     * Issue a HEAD request to the given URL.
     *
     * @throws ConnectionException
     */
    public function head(string $url, array|string|null $query = null): PromiseInterface|Response
    {
        return $this->send(
            'HEAD',
            $url,
            func_num_args() === 1 ? [] : [
                'query' => $query,
            ]
        );
    }

    /**
     * Issue a POST request to the given URL.
     *
     * @throws ConnectionException
     */
    public function post(string $url, array|JsonSerializable $data = []): PromiseInterface|Response
    {
        return $this->send('POST', $url, [
            $this->bodyFormat => $data,
        ]);
    }

    /**
     * Issue a PATCH request to the given URL.
     *
     * @throws ConnectionException
     */
    public function patch(string $url, array $data = []): PromiseInterface|Response
    {
        return $this->send('PATCH', $url, [
            $this->bodyFormat => $data,
        ]);
    }

    /**
     * Issue a PUT request to the given URL.
     *
     * @throws ConnectionException
     */
    public function put(string $url, array $data = []): PromiseInterface|Response
    {
        return $this->send('PUT', $url, [
            $this->bodyFormat => $data,
        ]);
    }

    /**
     * Issue a DELETE request to the given URL.
     *
     * @throws ConnectionException
     */
    public function delete(string $url, array $data = []): PromiseInterface|Response
    {
        return $this->send(
            'DELETE',
            $url,
            empty($data) ? [] : [
                $this->bodyFormat => $data,
            ]
        );
    }

    /**
     * Send the request to the given URL.
     *
     * @throws Exception
     * @throws ConnectionException|Throwable
     */
    public function send(string $method, string $url, array $options = []): PromiseInterface|Response
    {
        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $url = ltrim(rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/'), '/');
        }

        $url = $this->expandUrlParameters($url);

        $options = $this->parseHttpOptions($options);

        [$this->pendingBody, $this->pendingFiles] = [null, []];

        if ($this->async) {
            return $this->makePromise($method, $url, $options);
        }

        $shouldRetry = null;

        return retry($this->tries ?? 1, function ($attempt) use ($method, $url, $options, &$shouldRetry) {
            try {
                return tap(
                    $this->newResponse($this->sendRequest($method, $url, $options)),
                    function (Response $response) use ($attempt, &$shouldRetry) {
                        $this->populateResponse($response);

                        $this->dispatchResponseReceivedEvent($response);

                        if (! $response->successful()) {
                            try {
                                $shouldRetry = $this->retryWhenCallback ? call_user_func(
                                    $this->retryWhenCallback,
                                    $response->toException(),
                                    $this
                                ) : true;
                            } catch (Exception $exception) {
                                $shouldRetry = false;

                                throw $exception;
                            }

                            if ($this->throwCallback
                                && ($this->throwIfCallback === null
                                    || call_user_func($this->throwIfCallback, $response))) {
                                $response->throw($this->throwCallback);
                            }

                            $potentialTries = is_array($this->tries)
                                ? count($this->tries) + 1
                                : $this->tries;

                            if ($attempt < $potentialTries && $shouldRetry) {
                                $response->throw();
                            }

                            if ($potentialTries > 1 && $this->retryThrow) {
                                $response->throw();
                            }
                        }
                    }
                );
            } catch (ConnectException $e) {
                $exception = new ConnectionException($e->getMessage(), 0, $e);
                $request = new Request($e->getRequest());

                $this->factory?->recordRequestResponsePair($request, null);

                $this->dispatchConnectionFailedEvent($request, $exception);

                throw $exception;
            }
        }, $this->retryDelay ?? 100, function ($exception) use (&$shouldRetry) {
            $result = $shouldRetry ?? ($this->retryWhenCallback ? call_user_func(
                $this->retryWhenCallback,
                $exception,
                $this
            ) : true);

            $shouldRetry = null;

            return $result;
        });
    }

    /**
     * Substitute the URL parameters in the given URL.
     */
    protected function expandUrlParameters(string $url): string
    {
        return UriTemplate::expand($url, $this->urlParameters);
    }

    /**
     * Parse the given HTTP options and set the appropriate additional options.
     */
    protected function parseHttpOptions(array $options): array
    {
        if (isset($options[$this->bodyFormat])) {
            if ($this->bodyFormat === 'multipart') {
                $options[$this->bodyFormat] = $this->parseMultipartBodyFormat($options[$this->bodyFormat]);
            } elseif ($this->bodyFormat === 'body') {
                $options[$this->bodyFormat] = $this->pendingBody;
            }

            if (is_array($options[$this->bodyFormat])) {
                $options[$this->bodyFormat] = array_merge(
                    $options[$this->bodyFormat],
                    $this->pendingFiles
                );
            }
        } else {
            $options[$this->bodyFormat] = $this->pendingBody;
        }

        return (new Collection($options))->map(function ($value, $key) {
            if ($key === 'json' && $value instanceof JsonSerializable) {
                return $value;
            }

            return $value instanceof Arrayable ? $value->toArray() : $value;
        })->all();
    }

    /**
     * Parse multi-part form data.
     *
     * @return array|array[]
     */
    protected function parseMultipartBodyFormat(array $data): array
    {
        return (new Collection($data))
            ->map(fn ($value, $key) => is_array($value) ? $value : ['name' => $key, 'contents' => $value])
            ->values()
            ->all();
    }

    /**
     * Send an asynchronous request to the given URL.
     *
     * @throws Exception
     */
    protected function makePromise(string $method, string $url, array $options = [], int $attempt = 1): PromiseInterface
    {
        return $this->promise = $this->sendRequest($method, $url, $options)
            ->then(function (ResponseInterface $message) {
                return tap($this->newResponse($message), function ($response) {
                    $this->populateResponse($response);
                    $this->dispatchResponseReceivedEvent($response);
                });
            })
            ->otherwise(function (OutOfBoundsException|TransferException $e) {
                if ($e instanceof ConnectException || ($e instanceof RequestException && ! $e->hasResponse())) {
                    $exception = new ConnectionException($e->getMessage(), 0, $e);

                    $this->dispatchConnectionFailedEvent(new Request($e->getRequest()), $exception);

                    return $exception;
                }

                return $e instanceof RequestException && $e->hasResponse() ? $this->populateResponse(
                    $this->newResponse($e->getResponse())
                ) : $e;
            })
            ->then(
                function (ConnectionException|Response|TransferException $response) use (
                    $method,
                    $url,
                    $options,
                    $attempt
                ) {
                    return $this->handlePromiseResponse($response, $method, $url, $options, $attempt);
                }
            );
    }

    /**
     * Handle the response of an asynchronous request.
     *
     * @throws Exception
     */
    protected function handlePromiseResponse(
        ConnectionException|Response|TransferException $response,
        string $method,
        string $url,
        array $options,
        int $attempt
    ): mixed {
        if ($response instanceof Response && $response->successful()) {
            return $response;
        }

        if ($response instanceof RequestException) {
            $response = $this->populateResponse($this->newResponse($response->getResponse()));
        }

        try {
            $shouldRetry = $this->retryWhenCallback ? call_user_func(
                $this->retryWhenCallback,
                $response instanceof Response ? $response->toException() : $response,
                $this
            ) : true;
        } catch (Exception $exception) {
            return $exception;
        }

        $potentialTries = is_array($this->tries)
            ? count($this->tries) + 1
            : $this->tries;

        if ($attempt < $potentialTries && $shouldRetry) {
            $options['delay'] = value(
                $this->retryDelay,
                $attempt,
                $response instanceof Response ? $response->toException() : $response
            );

            return $this->makePromise($method, $url, $options, $attempt + 1);
        }

        if ($response instanceof Response
            && $this->throwCallback
            && ($this->throwIfCallback === null || call_user_func($this->throwIfCallback, $response))) {
            try {
                $response->throw($this->throwCallback);
            } catch (Exception $exception) {
                return $exception;
            }
        }

        if ($potentialTries > 1 && $this->retryThrow) {
            return $response instanceof Response ? $response->toException() : $response;
        }

        return $response;
    }

    /**
     * Send a request either synchronously or asynchronously.
     *
     * @throws Exception
     */
    protected function sendRequest(string $method, string $url, array $options = []): PromiseInterface|ResponseInterface
    {
        $clientMethod = $this->async ? 'requestAsync' : 'request';

        $data = $this->parseRequestData($method, $url, $options);
        $onStats = function (TransferStats $transferStats) {
            if (($callback = ($this->options['on_stats'] ?? false)) instanceof Closure) {
                $transferStats = $callback($transferStats) ?: $transferStats;
            }

            $this->transferStats = $transferStats;
        };

        $mergedOptions = $this->normalizeRequestOptions($this->mergeOptions([
            'data' => $data,
            'on_stats' => $onStats,
        ], $options));

        return $this->buildClient()->{$clientMethod}($method, $url, $mergedOptions);
    }

    /**
     * Get the request data as an array so that we can attach it to the request for convenient assertions.
     */
    protected function parseRequestData(string $method, string $url, array $options): array
    {
        if ($this->bodyFormat === 'body') {
            return [];
        }

        $data = $options[$this->bodyFormat] ?? $options['query'] ?? [];

        $urlString = (new Stringable($url));

        if (empty($data) && $method === 'GET' && $urlString->contains('?')) {
            $data = (string) $urlString->after('?');
        }

        if (is_string($data)) {
            parse_str($data, $parsedData);

            $data = is_array($parsedData) ? $parsedData : [];
        }

        if ($data instanceof JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Normalize the given request options.
     */
    protected function normalizeRequestOptions(array $options): array
    {
        foreach ($options as $key => $value) {
            $options[$key] = match (true) {
                is_array($value) => $this->normalizeRequestOptions($value),
                $value instanceof Stringable => $value->toString(),
                default => $value,
            };
        }

        return $options;
    }

    /**
     * Populate the given response with additional data.
     */
    protected function populateResponse(Response $response): Response
    {
        $response->cookies = $this->cookies;

        $response->transferStats = $this->transferStats;

        return $response;
    }

    /**
     * Build the Guzzle client.
     */
    public function buildClient(): ClientInterface
    {
        return $this->client ?? $this->factory->getClient($this->getConnection(), $this->buildHandlerStack(), $this->getConnectionConfig());
    }

    /**
     * Determine if a reusable client is required.
     */
    protected function requestsReusableClient(): bool
    {
        return ! is_null($this->client) || $this->async;
    }

    /**
     * Retrieve a reusable Guzzle client.
     */
    protected function getReusableClient(): ClientInterface
    {
        return $this->client ??= $this->factory->getClient($this->getConnection(), $this->buildHandlerStack(), $this->getConnectionConfig());
    }

    /**
     * Build the Guzzle client handler stack.
     */
    public function buildHandlerStack(): HandlerStack
    {
        return $this->pushHandlers(HandlerStack::create($this->handler));
    }

    /**
     * Add the necessary handlers to the given handler stack.
     */
    public function pushHandlers(HandlerStack $handlerStack): HandlerStack
    {
        return tap($handlerStack, function ($stack) {
            $this->middleware->each(function ($middleware) use ($stack) {
                $stack->push($middleware);
            });

            $stack->push($this->buildBeforeSendingHandler());
            $stack->push($this->buildRecorderHandler());
            $stack->push($this->buildStubHandler());
        });
    }

    /**
     * Build the before sending handler.
     */
    public function buildBeforeSendingHandler(): Closure
    {
        return function ($handler) {
            return function ($request, $options) use ($handler) {
                return $handler($this->runBeforeSendingCallbacks($request, $options), $options);
            };
        };
    }

    /**
     * Build the recorder handler.
     */
    public function buildRecorderHandler(): Closure
    {
        return function ($handler) {
            return function ($request, $options) use ($handler) {
                $promise = $handler($request, $options);

                return $promise->then(function ($response) use ($request, $options) {
                    $this->factory?->recordRequestResponsePair(
                        (new Request($request))->withData($options['data']),
                        $this->newResponse($response)
                    );

                    return $response;
                });
            };
        };
    }

    /**
     * Build the stub handler.
     */
    public function buildStubHandler(): Closure
    {
        return function ($handler) {
            return function ($request, $options) use ($handler) {
                $response = ($this->stubCallbacks ?? new Collection())
                    ->map
                    ->__invoke((new Request($request))->withData($options['data']), $options)
                    ->filter()
                    ->first();

                if (is_null($response)) {
                    if ($this->preventStrayRequests) {
                        throw new RuntimeException(
                            'Attempted request to [' . (string) $request->getUri() . '] without a matching fake.'
                        );
                    }

                    return $handler($request, $options);
                }

                $response = is_array($response) ? Factory::response($response) : $response;

                $sink = $options['sink'] ?? null;

                if ($sink) {
                    $response->then($this->sinkStubHandler($sink));
                }

                return $response;
            };
        };
    }

    /**
     * Get the sink stub handler callback.
     *
     * @param resource|string $sink
     */
    protected function sinkStubHandler($sink): Closure
    {
        return function ($response) use ($sink) {
            $body = $response->getBody()->getContents();

            if (is_string($sink)) {
                file_put_contents($sink, $body);

                return;
            }

            fwrite($sink, $body);
            rewind($sink);
        };
    }

    /**
     * Execute the "before sending" callbacks.
     */
    public function runBeforeSendingCallbacks(RequestInterface $request, array $options): RequestInterface
    {
        return tap($request, function (&$request) use ($options) {
            $this->beforeSendingCallbacks->each(function ($callback) use (&$request, $options) {
                $callbackResult = call_user_func(
                    $callback,
                    (new Request($request))->withData($options['data']),
                    $options,
                    $this
                );

                if ($callbackResult instanceof RequestInterface) {
                    $request = $callbackResult;
                } elseif ($callbackResult instanceof Request) {
                    $request = $callbackResult->toPsrRequest();
                }
            });
        });
    }

    /**
     * Replace the given options with the current request options.
     */
    public function mergeOptions(...$options): array
    {
        return array_replace_recursive(
            array_merge_recursive($this->options, Arr::only($options, $this->mergeableOptions)),
            ...$options
        );
    }

    /**
     * Create a new response instance using the given PSR response.
     */
    protected function newResponse(PromiseInterface|ResponseInterface $response): Response
    {
        return new Response($response);
    }

    /**
     * Register a stub callable that will intercept requests and be able to return stub responses.
     */
    public function stub(callable|Collection $callback): static
    {
        $this->stubCallbacks = $callback instanceof Collection
            ? $callback
            : new Collection([$callback]);

        return $this;
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
     * Toggle asynchronicity in requests.
     */
    public function async(bool $async = true): static
    {
        $this->async = $async;

        return $this;
    }

    /**
     * Retrieve the pending request promise.
     */
    public function getPromise(): ?PromiseInterface
    {
        return $this->promise;
    }

    /**
     * Dispatch the RequestSending event if a dispatcher is available.
     */
    protected function dispatchRequestSendingEvent(): void
    {
        if ($dispatcher = $this->factory?->getDispatcher()) {
            $dispatcher->dispatch(new RequestSending($this->request));
        }
    }

    /**
     * Dispatch the ResponseReceived event if a dispatcher is available.
     */
    protected function dispatchResponseReceivedEvent(Response $response): void
    {
        if (! ($dispatcher = $this->factory?->getDispatcher()) || ! $this->request) {
            return;
        }

        $dispatcher->dispatch(new ResponseReceived($this->request, $response));
    }

    /**
     * Dispatch the ConnectionFailed event if a dispatcher is available.
     */
    protected function dispatchConnectionFailedEvent(Request $request, ConnectionException $exception): void
    {
        if ($dispatcher = $this->factory?->getDispatcher()) {
            $dispatcher->dispatch(new ConnectionFailed($request, $exception));
        }
    }

    /**
     * Set the client instance.
     */
    public function setClient(ClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Create a new client instance using the given handler.
     */
    public function setHandler(callable $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Get the pending request options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Set the pending request connection.
     */
    public function connection(string $connection, ?array $config = null): static
    {
        $this->connection = $connection;
        if ($config) {
            $this->connectionConfig = $config;
        }

        return $this;
    }

    /**
     * Get the pending request connection.
     */
    public function getConnection(): ?string
    {
        return $this->connection;
    }

    public function getConnectionConfig(): ?array
    {
        return $this->connectionConfig;
    }
}
