<?php

declare(strict_types=1);

namespace Hypervel\Dispatcher;

use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class HttpRequestHandler implements RequestHandlerInterface
{
    /**
     * Create a new HTTP request handler instance.
     */
    public function __construct(
        protected array $middlewares,
        protected MiddlewareInterface $coreMiddleware,
        protected Container $container,
    ) {
    }

    /**
     * Handle the request through the middleware pipeline.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        Context::set('__request.middleware', $this->middlewares);

        return $this->container
            ->get(Pipeline::class)
            ->send($request)
            ->through([...$this->middlewares, $this->coreMiddleware])
            ->thenReturn();
    }
}
