<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use Hypervel\Support\DataObject;

/**
 * @mixin PendingRequest
 */
class ApiClient
{
    protected ?DataObject $config = null;

    protected string $resource = ApiResource::class;

    protected bool $enableMiddleware = true;

    /**
     * @var array<RequestMiddleware|string>
     */
    protected array $requestMiddleware = [];

    /**
     * @var array<ResponseMiddleware|string>
     */
    protected array $responseMiddleware = [];

    /**
     * Dynamically pass method calls to the underlying resource.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->getClient()
            ->{$method}(...$parameters);
    }

    public function getConfig(): ?DataObject
    {
        return $this->config;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function getEnableMiddleware(): bool
    {
        return $this->enableMiddleware;
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

    public function getRequestMiddleware(): array
    {
        return $this->requestMiddleware;
    }

    public function getResponseMiddleware(): array
    {
        return $this->responseMiddleware;
    }

    public function getClient(): PendingRequest
    {
        return new PendingRequest($this);
    }
}
