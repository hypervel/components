<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Hypervel\Saloon\Contracts\FakeResponse;
use Hypervel\Saloon\Enums\PipeOrder;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;

class MiddlewarePipeline
{
    /**
     * The request pipeline.
     *
     * @var Pipeline<PendingRequest>
     */
    protected Pipeline $requestPipeline;

    /**
     * The response pipeline.
     *
     * @var Pipeline<Response>
     */
    protected Pipeline $responsePipeline;

    /**
     * The fatal exception pipeline.
     *
     * @var Pipeline<FatalRequestException>
     */
    protected Pipeline $fatalPipeline;

    /**
     * Create a middleware pipeline.
     */
    public function __construct()
    {
        $this->requestPipeline = new Pipeline;
        $this->responsePipeline = new Pipeline;
        $this->fatalPipeline = new Pipeline;
    }

    /**
     * Add middleware before the request is sent.
     *
     * @param callable(PendingRequest): (FakeResponse|void) $callable
     * @return $this
     */
    public function onRequest(callable $callable, ?string $name = null, ?PipeOrder $order = null): static
    {
        $this->requestPipeline->pipe(function (PendingRequest $pendingRequest) use ($callable): PendingRequest {
            $result = $callable($pendingRequest);

            if ($result instanceof FakeResponse) {
                $pendingRequest->setFakeResponse($result);
            }

            return $pendingRequest;
        }, $name, $order);

        return $this;
    }

    /**
     * Add middleware after the request is sent.
     *
     * @param callable(Response): (Response|void) $callable
     * @return $this
     */
    public function onResponse(callable $callable, ?string $name = null, ?PipeOrder $order = null): static
    {
        $this->responsePipeline->pipe(function (Response $response) use ($callable): Response {
            $result = $callable($response);

            return $result instanceof Response ? $result : $response;
        }, $name, $order);

        return $this;
    }

    /**
     * Add middleware that runs after a fatal request error.
     *
     * @param callable(FatalRequestException): void $callable
     * @return $this
     */
    public function onFatalException(callable $callable, ?string $name = null, ?PipeOrder $order = null): static
    {
        $this->fatalPipeline->pipe(function (FatalRequestException $throwable) use ($callable): FatalRequestException {
            $callable($throwable);

            return $throwable;
        }, $name, $order);

        return $this;
    }

    /**
     * Process the request pipeline.
     */
    public function executeRequestPipeline(PendingRequest $pendingRequest): void
    {
        $this->requestPipeline->process($pendingRequest);
    }

    /**
     * Process the response pipeline.
     */
    public function executeResponsePipeline(Response $response): Response
    {
        return $this->responsePipeline->process($response);
    }

    /**
     * Process the fatal exception pipeline.
     *
     * @throws FatalRequestException
     */
    public function executeFatalPipeline(FatalRequestException $throwable): void
    {
        $this->fatalPipeline->process($throwable);
    }

    /**
     * Merge in another middleware pipeline.
     *
     * @return $this
     */
    public function merge(MiddlewarePipeline $middlewarePipeline): static
    {
        $requestPipes = array_merge(
            $this->requestPipeline()->pipes(),
            $middlewarePipeline->requestPipeline()->pipes(),
        );

        $responsePipes = array_merge(
            $this->responsePipeline()->pipes(),
            $middlewarePipeline->responsePipeline()->pipes(),
        );

        $fatalPipes = array_merge(
            $this->fatalPipeline()->pipes(),
            $middlewarePipeline->fatalPipeline()->pipes(),
        );

        $this->requestPipeline->setPipes($requestPipes);
        $this->responsePipeline->setPipes($responsePipes);
        $this->fatalPipeline->setPipes($fatalPipes);

        return $this;
    }

    /**
     * Get the request pipeline.
     */
    public function requestPipeline(): Pipeline
    {
        return $this->requestPipeline;
    }

    /**
     * Get the response pipeline.
     */
    public function responsePipeline(): Pipeline
    {
        return $this->responsePipeline;
    }

    /**
     * Get the fatal exception pipeline.
     */
    public function fatalPipeline(): Pipeline
    {
        return $this->fatalPipeline;
    }

    /**
     * Clone the owned middleware pipelines.
     */
    public function __clone(): void
    {
        $this->requestPipeline = clone $this->requestPipeline;
        $this->responsePipeline = clone $this->responsePipeline;
        $this->fatalPipeline = clone $this->fatalPipeline;
    }
}
