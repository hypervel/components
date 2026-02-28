<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Middleware;

use Closure;
use Hypervel\Horizon\Exceptions\ForbiddenException;
use Hypervel\Horizon\Horizon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Authenticate implements MiddlewareInterface
{
    /**
     * Handle the incoming request (Laravel-style middleware API).
     *
     * @TODO Remove PSR bridge method `process()` once the framework middleware pipeline
     *       no longer depends on PSR-15 and invokes middleware via `handle()`.
     */
    public function handle(ServerRequestInterface $request, Closure $next): ResponseInterface
    {
        if (! Horizon::check($request)) {
            throw ForbiddenException::make();
        }

        return $next($request);
    }

    /**
     * Process the incoming request (PSR-15 bridge).
     *
     * @TODO Remove this bridge once middleware dispatch is fully Laravel-style.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->handle(
            $request,
            static fn (ServerRequestInterface $request): ResponseInterface => $handler->handle($request),
        );
    }
}
