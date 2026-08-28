<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use BadMethodCallException;
use GuzzleHttp\ClientInterface;
use Hypervel\ApiClient\Concerns\HasContext;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\PendingRequest as ClientPendingRequest;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Http\Client\Response as HttpResponse;
use Hypervel\Pipeline\Pipeline;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\ForwardsCalls;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * @template TResource of ApiResource = ApiResource
 * @method static baseUrl(string $url)
 * @method static withBody(null|resource|\Psr\Http\Message\StreamInterface|string|\Hypervel\Support\Stringable $content, string $contentType = 'application/json')
 * @method static asJson()
 * @method static asForm()
 * @method static attach(array|string $name, resource|string $contents = '', ?string $filename = null, array $headers = [])
 * @method static asMultipart()
 * @method static bodyFormat(string $format)
 * @method static withQueryParameters(array $parameters)
 * @method static contentType(string $contentType)
 * @method static acceptJson()
 * @method static accept(string $contentType)
 * @method static withHeaders(array $headers)
 * @method static withHeader(string $name, mixed $value)
 * @method static replaceHeaders(array $headers)
 * @method static withBasicAuth(string $username, string $password)
 * @method static withDigestAuth(string $username, string $password)
 * @method static withNtlmAuth(string $username, string $password)
 * @method static withToken(string $token, string $type = 'Bearer')
 * @method static withUserAgent(bool|string $userAgent)
 * @method static withUrlParameters(array $parameters = [])
 * @method static withCookies(array $cookies, string $domain)
 * @method static maxRedirects(int $max)
 * @method static withoutRedirecting()
 * @method static withoutVerifying()
 * @method static sink(\Psr\Http\Message\StreamInterface|resource|string $to)
 * @method static timeout(float|int $seconds)
 * @method static connectTimeout(float|int $seconds)
 * @method static retry(array|int $times, \Closure|int $sleepMilliseconds = 0, ?callable $when = null, bool $throw = true)
 * @method static withOptions(array $options)
 * @method static withMiddleware(callable $middleware)
 * @method static prependMiddleware(callable $middleware)
 * @method static withRequestMiddleware(callable $middleware)
 * @method static withResponseMiddleware(callable $middleware)
 * @method static withAttributes(array $attributes)
 * @method static withoutTelescope()
 * @method static withTelescopeTags(array $tags)
 * @method static beforeSending(callable $callback)
 * @method static afterResponse(callable $callback)
 * @method static throw(?callable $callback = null)
 * @method static throwIf(bool|callable $condition, ?callable $callback = null)
 * @method static throwUnless(bool|callable $condition, ?callable $callback = null)
 * @method static dump()
 * @method static dd()
 * @method static stub(callable|\Hypervel\Support\Collection $callback)
 * @method static preventStrayRequests(bool $prevent = true)
 * @method static allowStrayRequests(array $only)
 * @method static truncateExceptionsAt(int $length)
 * @method static dontTruncateExceptions()
 * @method static setHandler(callable $handler)
 * @method static connection(string $connection, ?array $config = null)
 * @mixin ClientPendingRequest
 */
class PendingRequest implements Transient
{
    use Conditionable;
    use ForwardsCalls;
    use HasContext;

    /**
     * @var class-string<TResource>
     */
    protected string $resource = ApiResource::class;

    /**
     * @var list<callable|object|string>
     */
    protected array $requestMiddleware = [];

    /**
     * @var list<callable|object|string>
     */
    protected array $responseMiddleware = [];

    protected ?ClientPendingRequest $request = null;

    protected Pipeline $pipeline;

    protected ?ApiRequest $activeRequest = null;

    /**
     * Create a new pending API request instance.
     */
    public function __construct(?Pipeline $pipeline = null)
    {
        $this->pipeline = $pipeline ?? Container::getInstance()->make(Pipeline::class);
    }

    /**
     * Add middleware to the API request pipeline.
     */
    public function withApiRequestMiddleware(callable|object|string $middleware): static
    {
        $this->requestMiddleware[] = $middleware;

        return $this;
    }

    /**
     * Replace the API request middleware pipeline.
     *
     * @param list<callable|object|string> $middleware
     */
    public function replaceApiRequestMiddleware(array $middleware): static
    {
        $this->requestMiddleware = $middleware;

        return $this;
    }

    /**
     * Add middleware to the API response pipeline.
     */
    public function withApiResponseMiddleware(callable|object|string $middleware): static
    {
        $this->responseMiddleware[] = $middleware;

        return $this;
    }

    /**
     * Replace the API response middleware pipeline.
     *
     * @param list<callable|object|string> $middleware
     */
    public function replaceApiResponseMiddleware(array $middleware): static
    {
        $this->responseMiddleware = $middleware;

        return $this;
    }

    /**
     * Remove all API middleware from the request.
     */
    public function withoutApiMiddleware(): static
    {
        $this->requestMiddleware = [];
        $this->responseMiddleware = [];

        return $this;
    }

    /**
     * Set the resource class for the request.
     *
     * @template TNewResource of ApiResource
     * @param class-string<TNewResource> $resource
     * @return $this
     * @phpstan-this-out static<TNewResource>
     * @throws InvalidArgumentException
     */
    public function withResource(string $resource): static
    {
        if (! is_a($resource, ApiResource::class, true)) {
            throw new InvalidArgumentException(
                sprintf('Resource class `%s` must be `%s` or a subclass.', $resource, ApiResource::class)
            );
        }

        $this->resource = $resource;

        return $this;
    }

    /**
     * Issue a GET request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function get(string $url, Arrayable|array|JsonSerializable|string|null $query = null): ApiResource
    {
        return func_num_args() === 1
            ? $this->sendRequest('get', $url)
            : $this->sendRequest('get', $url, $query);
    }

    /**
     * Issue a HEAD request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function head(string $url, Arrayable|array|JsonSerializable|string|null $query = null): ApiResource
    {
        return func_num_args() === 1
            ? $this->sendRequest('head', $url)
            : $this->sendRequest('head', $url, $query);
    }

    /**
     * Issue a QUERY request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function query(string $url, Arrayable|array|JsonSerializable $data = []): ApiResource
    {
        return $this->sendRequest('query', $url, $data);
    }

    /**
     * Issue a POST request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function post(string $url, Arrayable|array|JsonSerializable $data = []): ApiResource
    {
        return $this->sendRequest('post', $url, $data);
    }

    /**
     * Issue a PATCH request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function patch(string $url, Arrayable|array|JsonSerializable $data = []): ApiResource
    {
        return $this->sendRequest('patch', $url, $data);
    }

    /**
     * Issue a PUT request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function put(string $url, Arrayable|array|JsonSerializable $data = []): ApiResource
    {
        return $this->sendRequest('put', $url, $data);
    }

    /**
     * Issue a DELETE request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function delete(string $url, Arrayable|array|JsonSerializable $data = []): ApiResource
    {
        return $this->sendRequest('delete', $url, $data);
    }

    /**
     * Send the request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException|Throwable
     * @throws InvalidArgumentException
     */
    public function send(string $method, string $url, array $options = []): ApiResource
    {
        return $this->sendRequest('send', $method, $url, $options);
    }

    /**
     * Enable or disable asynchronous requests.
     */
    public function async(bool $async = true): static
    {
        if ($async) {
            throw new InvalidArgumentException('The API client does not support asynchronous requests.');
        }

        return $this;
    }

    /**
     * Reject a custom Guzzle client that would bypass the API request pipeline.
     */
    public function setClient(ClientInterface $client): never
    {
        throw new BadMethodCallException(
            'Custom Guzzle clients are not supported by the API client. Use setHandler() to configure a request-specific transport handler.'
        );
    }

    /**
     * Send an API request.
     *
     * @return TResource
     */
    protected function sendRequest(string $method, mixed ...$arguments): ApiResource
    {
        try {
            /** @var HttpResponse $response */
            $response = $this->getRequest()->{$method}(...$arguments);
            $request = $this->activeRequest;

            if ($request === null) {
                throw new LogicException(
                    'HTTP middleware ahead of the API bridge short-circuited the request before API middleware could run.'
                );
            }

            $apiResponse = ApiResponse::createFrom($response)
                ->withContext($request->context());
            $apiResponse = $this->runResponseMiddleware($apiResponse);

            return $this->resource::make($apiResponse, $request);
        } finally {
            $this->activeRequest = null;
        }
    }

    /**
     * Run the API request middleware.
     */
    protected function runRequestMiddleware(ApiRequest $request): ApiRequest
    {
        return $this->pipeline
            ->send($request)
            ->through($this->requestMiddleware)
            ->thenReturn();
    }

    /**
     * Run the API response middleware.
     */
    protected function runResponseMiddleware(ApiResponse $response): ApiResponse
    {
        return $this->pipeline
            ->send($response)
            ->through($this->responseMiddleware)
            ->thenReturn();
    }

    /**
     * Get the underlying HTTP pending request.
     */
    protected function getRequest(): ClientPendingRequest
    {
        if ($this->request !== null) {
            return $this->request;
        }

        $request = Http::createPendingRequest();
        $request->prependMiddleware(function (callable $handler) use ($request): callable {
            return function (RequestInterface $psrRequest, array $options) use ($handler, $request) {
                $httpRequest = (new HttpRequest($psrRequest))
                    ->withData($options['hypervel_data'] ?? [])
                    ->setRequestAttributes($request->attributes());
                $apiRequest = ApiRequest::createFrom($httpRequest)
                    ->withContext($this->context());

                $this->activeRequest = $this->runRequestMiddleware($apiRequest);

                return $handler($this->activeRequest->toPsrRequest(), $options);
            };
        });

        return $this->request = $request;
    }

    /**
     * Dynamically pass method calls to the underlying HTTP client.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardDecoratedCallTo(
            $this->getRequest(),
            $method,
            $parameters,
        );
    }
}
