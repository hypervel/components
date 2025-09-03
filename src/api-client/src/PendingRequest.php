<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\HttpClient\ConnectionException;
use Hypervel\HttpClient\PendingRequest as ClientPendingRequest;
use Hypervel\HttpClient\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;
use JsonSerializable;
use Throwable;

/**
 * @template TResource of ApiResource
 * @mixin ClientPendingRequest
 */
class PendingRequest
{
    use Conditionable;

    /**
     * @var class-string<TResource>
     */
    protected string $resource = ApiResource::class;

    protected bool $enableMiddleware = true;

    protected array $middlewareOptions = [];

    protected array $guzzleOptions = [];

    /**
     * @var array<RequestMiddleware|string>
     */
    protected array $requestMiddleware = [];

    /**
     * @var array<ResponseMiddleware|string>
     */
    protected array $responseMiddleware = [];

    protected ?ClientPendingRequest $request = null;

    public function __construct(
        protected ApiClient $client,
    ) {
        $this->resource = $this->client->getResource();
        $this->enableMiddleware = $this->client->getEnableMiddleware();
        $this->requestMiddleware = $this->client->getRequestMiddleware();
        $this->responseMiddleware = $this->client->getResponseMiddleware();
    }

    /**
     * Enable or disable middleware for the request.
     */
    public function enableMiddleware(): static
    {
        $this->enableMiddleware = true;

        return $this;
    }

    /**
     * Disable middleware for the request.
     */
    public function disableMiddleware(): static
    {
        $this->enableMiddleware = false;

        return $this;
    }

    /**
     * Set the options for the middleware.
     */
    public function withMiddlewareOptions(array $options): static
    {
        $this->middlewareOptions = $options;

        return $this;
    }

    /**
     * Set the Guzzle options for the request.
     */
    public function withGuzzleOptions(array $options): static
    {
        $this->guzzleOptions = $options;

        return $this;
    }

    /**
     * Set the request middleware for the request.
     */
    public function withRequestMiddleware(array $middleware): static
    {
        $this->requestMiddleware = $middleware;

        return $this;
    }

    /**
     * Add request middleware to the existing request middleware.
     */
    public function withAddedRequestMiddleware(array $middleware): static
    {
        $this->requestMiddleware = array_merge($this->requestMiddleware, $middleware);

        return $this;
    }

    /**
     * Set the response middleware for the request.
     */
    public function withResponseMiddleware(array $middleware): static
    {
        $this->responseMiddleware = $middleware;

        return $this;
    }

    /**
     * Add response middleware to the existing response middleware.
     */
    public function withAddedResponseMiddleware(array $middleware): static
    {
        $this->responseMiddleware = array_merge($this->responseMiddleware, $middleware);

        return $this;
    }

    /**
     * Set the resource class for the request.
     *
     * @param class-string<TResource> $resource
     * @throws InvalidArgumentException
     */
    public function withResource(string $resource): static
    {
        if (! class_exists($resource)) {
            throw new InvalidArgumentException(
                sprintf('Resource class `%s` does not exist', $resource)
            );
        }

        if (! is_subclass_of($resource, ApiResource::class)) {
            throw new InvalidArgumentException(
                sprintf('Resource class `%s` must be a subclass of `%s`', $resource, ApiResource::class)
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
     */
    public function get(string $url, array|JsonSerializable|string|null $query = null): ApiResource
    {
        return $this->sendRequest('get', $url, $query);
    }

    /**
     * Issue a HEAD request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     */
    public function head(string $url, array|string|null $query = null): ApiResource
    {
        return $this->sendRequest('head', $url, $query);
    }

    /**
     * Issue a POST request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     */
    public function post(string $url, array|JsonSerializable $data = []): ApiResource
    {
        return $this->sendRequest('post', $url, $data);
    }

    /**
     * Issue a PATCH request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     */
    public function patch(string $url, array $data = []): ApiResource
    {
        return $this->sendRequest('patch', $url, $data);
    }

    /**
     * Issue a PUT request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     */
    public function put(string $url, array $data = []): ApiResource
    {
        return $this->sendRequest('put', $url, $data);
    }

    /**
     * Issue a DELETE request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException
     */
    public function delete(string $url, array $data = []): ApiResource
    {
        return $this->sendRequest('delete', $url, $data);
    }

    /**
     * Send the request to the given URL.
     *
     * @return TResource
     * @throws ConnectionException|Throwable
     */
    public function send(string $method, string $url, array $options = []): ApiResource
    {
        return $this->sendRequest('send', $method, $url, $options);
    }

    /**
     * Provide a dynamic method to pass calls to the pending request.
     */
    public function __call(string $method, array $parameters): static
    {
        $this->getClient()
            ->{$method}(...$parameters);

        return $this;
    }

    protected function sendRequest(): ApiResource
    {
        $arguments = func_get_args();
        $method = array_shift($arguments);

        $request = null;
        $response = $this->getClient()
            ->beforeSending(function (Request $httpRequest) use (&$request) {
                $request = $httpRequest;
            })->{$method}(...$arguments);

        if ($response instanceof PromiseInterface) {
            throw new InvalidArgumentException('Api client does not support async requests');
        }

        return $this->resource::make($response, $request);
    }

    protected function createMiddleware(array $middlewareClasses, array $options): array
    {
        $middleware = [];
        foreach ($middlewareClasses as $value) {
            if (! class_exists($value)) {
                throw new InvalidArgumentException(
                    sprintf('Middleware class `%s` does not exist', $value)
                );
            }

            $middleware[] = new $value($this->client->getConfig(), $options);
        }

        return $middleware;
    }

    protected function getClient(): ClientPendingRequest
    {
        if ($this->request) {
            return $this->request;
        }

        $request = Http::throw();

        if ($this->guzzleOptions) {
            $request->withOptions($this->guzzleOptions);
        }

        if (! $this->enableMiddleware) {
            return $request;
        }

        foreach ($this->createMiddleware($this->requestMiddleware, $this->middlewareOptions) as $middleware) {
            $request->withRequestMiddleware($middleware);
        }

        foreach ($this->createMiddleware($this->responseMiddleware, $this->middlewareOptions) as $middleware) {
            $request->withResponseMiddleware($middleware);
        }

        return $this->request = $request;
    }
}
