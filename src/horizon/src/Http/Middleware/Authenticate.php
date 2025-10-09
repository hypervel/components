<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Middleware;

use Closure;
use Hypervel\Horizon\Exceptions\ForbiddenException;
use Hypervel\Horizon\Horizon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Authenticate
{
    /**
     * Handle the incoming request.
     */
    public function handle(ServerRequestInterface $request, Closure $next): ResponseInterface
    {
        if (! Horizon::check($request)) {
            throw ForbiddenException::make();
        }

        return $next($request);
    }
}
