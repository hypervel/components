<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Middleware;

use Hypervel\Horizon\Exceptions\ForbiddenException;
use Hypervel\Horizon\Horizon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Authenticate implements MiddlewareInterface
{
    /**
     * Handle the incoming request.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! Horizon::check($request)) {
            throw ForbiddenException::make();
        }

        return $handler->handle($request);
    }
}
