<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Middleware;

use Hypervel\Http\Contracts\RequestContract;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Hypervel\Telescope\Telescope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Authorize implements MiddlewareInterface
{
    public function __construct(
        protected RequestContract $request
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! Telescope::check($this->request)) {
            throw new HttpException(403);
        }

        return $handler->handle($request);
    }
}
