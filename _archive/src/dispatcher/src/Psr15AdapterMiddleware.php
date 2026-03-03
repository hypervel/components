<?php

declare(strict_types=1);

namespace Hypervel\Dispatcher;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

class Psr15AdapterMiddleware
{
    public function __construct(
        private MiddlewareInterface $middleware,
        private bool $overrideResponse = false
    ) {
    }

    public function handle(ServerRequestInterface $request, Closure $next, ...$arguments): ResponseInterface
    {
        return $this->middleware->process(
            $request,
            new AdaptedRequestHandler($next, $this->overrideResponse),
            ...$arguments
        );
    }
}
