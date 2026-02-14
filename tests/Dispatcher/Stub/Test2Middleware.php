<?php

declare(strict_types=1);

namespace Hypervel\Tests\Dispatcher\Stub;

use Hypervel\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Test2Middleware implements MiddlewareInterface
{
    /**
     * Process an incoming server request and return a response, optionally delegating
     * response creation to a handler.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = Context::get(ResponseInterface::class);
        Context::set(ResponseInterface::class, $response->withAddedHeader('Test', 'Hyperf2'));
        return $handler->handle($request);
    }
}
