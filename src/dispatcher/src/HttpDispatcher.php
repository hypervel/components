<?php

declare(strict_types=1);

namespace Hypervel\Dispatcher;

use Hypervel\Contracts\Container\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

class HttpDispatcher extends AbstractDispatcher
{
    /**
     * Create a new HTTP dispatcher instance.
     */
    public function __construct(private Container $container)
    {
    }

    /**
     * Dispatch the request through the middleware stack.
     *
     * Expects: ServerRequestInterface $request, array $middlewares, MiddlewareInterface $coreHandler.
     */
    public function dispatch(...$params): ResponseInterface
    {
        [$request, $middlewares, $coreHandler] = $params;
        $requestHandler = new HttpRequestHandler($middlewares, $coreHandler, $this->container);

        return $requestHandler->handle($request);
    }
}
