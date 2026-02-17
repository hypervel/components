<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer\Stub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SetHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected string $id
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAddedHeader('DEBUG', $this->id));
    }
}
