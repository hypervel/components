<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use Hypervel\Support\Traits\ForwardsCalls;

/**
 * @template TResource of ApiResource = ApiResource
 * @mixin PendingRequest<TResource>
 */
class ApiClient
{
    use ForwardsCalls;

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

    /**
     * Create a pending API request.
     *
     * @return PendingRequest<TResource>
     */
    public function createPendingRequest(): PendingRequest
    {
        $request = $this->newPendingRequest()
            ->withResource($this->resource)
            ->replaceApiRequestMiddleware($this->requestMiddleware)
            ->replaceApiResponseMiddleware($this->responseMiddleware);

        $this->configurePendingRequest($request);

        return $request;
    }

    /**
     * Create a new pending API request instance.
     *
     * @return PendingRequest<TResource>
     */
    protected function newPendingRequest(): PendingRequest
    {
        /** @var PendingRequest<TResource> $request */
        $request = new PendingRequest;

        return $request;
    }

    /**
     * Configure a pending API request.
     */
    protected function configurePendingRequest(PendingRequest $request): void
    {
    }

    /**
     * Dynamically pass method calls to a pending API request.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo(
            $this->createPendingRequest(),
            $method,
            $parameters,
        );
    }
}
