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

class PendingRequest
{
    use Conditionable;

    protected string $resource = ApiResource::class;

    protected bool $enableMiddleware = true;

    protected bool $enableRequestLog = true;

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

    public function __construct(
        protected ApiClient $client,
    ) {
        $this->resource = $this->client->getResource();
        $this->enableMiddleware = $this->client->getEnableMiddleware();
        $this->requestMiddleware = $this->client->getRequestMiddleware();
        $this->responseMiddleware = $this->client->getResponseMiddleware();
    }

    public function enableMiddleware(): static
    {
        $this->enableMiddleware = true;

        return $this;
    }

    public function disableMiddleware(): static
    {
        $this->enableMiddleware = false;

        return $this;
    }

    public function enableRequestLog(): static
    {
        $this->enableRequestLog = true;

        return $this;
    }

    public function disableRequestLog(): static
    {
        $this->enableRequestLog = false;

        return $this;
    }

    public function withMiddlewareOptions(array $options): static
    {
        $this->middlewareOptions = $options;

        return $this;
    }

    public function withGuzzleOptions(array $options): static
    {
        $this->guzzleOptions = $options;

        return $this;
    }

    public function withRequestMiddleware(array $middleware): static
    {
        $this->requestMiddleware = $middleware;

        return $this;
    }

    public function withAddedRequestMiddleware(array $middleware): static
    {
        $this->requestMiddleware = array_merge($this->requestMiddleware, $middleware);

        return $this;
    }

    public function withResponseMiddleware(array $middleware): static
    {
        $this->responseMiddleware = $middleware;

        return $this;
    }

    public function withAddedResponseMiddleware(array $middleware): static
    {
        $this->responseMiddleware = array_merge($this->responseMiddleware, $middleware);

        return $this;
    }

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

    public function timeout(float|int $timeout): static
    {
        $this->guzzleOptions['timeout'] = $timeout;

        return $this;
    }

    /**
     * Issue a GET request to the given URL.
     *
     * @throws ConnectionException
     */
    public function get(string $url, null|array|JsonSerializable|string $query = null): ApiResource
    {
        return $this->sendRequest('get', $url, $query);
    }

    /**
     * Issue a HEAD request to the given URL.
     *
     * @throws ConnectionException
     */
    public function head(string $url, null|array|string $query = null): ApiResource
    {
        return $this->sendRequest('head', $url, $query);
    }

    /**
     * Issue a POST request to the given URL.
     *
     * @throws ConnectionException
     */
    public function post(string $url, array|JsonSerializable $data = []): ApiResource
    {
        return $this->sendRequest('post', $url, $data);
    }

    /**
     * Issue a PATCH request to the given URL.
     *
     * @throws ConnectionException
     */
    public function patch(string $url, array $data = []): ApiResource
    {
        return $this->sendRequest('patch', $url, $data);
    }

    /**
     * Issue a PUT request to the given URL.
     *
     * @throws ConnectionException
     */
    public function put(string $url, array $data = []): ApiResource
    {
        return $this->sendRequest('put', $url, $data);
    }

    /**
     * Issue a DELETE request to the given URL.
     *
     * @throws ConnectionException
     */
    public function delete(string $url, array $data = []): ApiResource
    {
        return $this->sendRequest('delete', $url, $data);
    }

    /**
     * Send the request to the given URL.
     *
     * @throws ConnectionException|Throwable
     */
    public function send(string $method, string $url, array $options = []): ApiResource
    {
        return $this->sendRequest('send', $method, $url, $options);
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

        return $request;
    }
}
