<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use Hypervel\Support\DataObject;

/**
 * @template TConfig of DataObject
 * @template TResource of ApiResource
 * @mixin PendingRequest<TResource>
 */
class ApiClient
{
    /**
     * @var null|TConfig
     */
    protected ?DataObject $config = null;

    /**
     * @var class-string<TResource>
     */
    protected string $resource = ApiResource::class;

    protected bool $enableMiddleware = true;

    /**
     * @var array<callable|object|string>
     */
    protected array $requestMiddleware = [];

    /**
     * @var array<callable|object|string>
     */
    protected array $responseMiddleware = [];

    /**
     * Dynamically pass method calls to the pending request.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->getClient()
            ->{$method}(...$parameters);
    }

    /**
     * Get the configuration for the API client.
     *
     * @return null|TConfig
     */
    public function getConfig(): ?DataObject
    {
        return $this->config;
    }

    /**
     * Get the resource class name.
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    /**
     * Determine whether middleware is enabled for the client.
     */
    public function getEnableMiddleware(): bool
    {
        return $this->enableMiddleware;
    }

    /**
     * Enable middleware for the client.
     */
    public function enableMiddleware(): static
    {
        $this->enableMiddleware = true;

        return $this;
    }

    /**
     * Disable middleware for the client.
     */
    public function disableMiddleware(): static
    {
        $this->enableMiddleware = false;

        return $this;
    }

    /**
     * Get the request middleware.
     */
    public function getRequestMiddleware(): array
    {
        return $this->requestMiddleware;
    }

    /**
     * Get the response middleware.
     */
    public function getResponseMiddleware(): array
    {
        return $this->responseMiddleware;
    }

    /**
     * Get a new pending request instance.
     */
    public function getClient(): PendingRequest
    {
        return new PendingRequest($this);
    }
}
