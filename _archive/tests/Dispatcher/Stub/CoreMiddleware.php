<?php

declare(strict_types=1);

namespace Hypervel\Tests\Dispatcher\Stub;

use Hypervel\Context\Context;
use Hypervel\HttpServer\Contracts\CoreMiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CoreMiddleware implements CoreMiddlewareInterface
{
    /**
     * Dispatch the request for route resolution.
     */
    public function dispatch(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request;
    }

    /**
     * Process an incoming server request and return a response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = Context::get(ResponseInterface::class);
        return $response->withAddedHeader('Server', 'Hyperf');
    }
}
