<?php

declare(strict_types=1);

namespace Hypervel\Dispatcher;

use Closure;
use Hypervel\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AdaptedRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private Closure $next,
        private bool $overrideResponse = false
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = ($this->next)($request);

        if ($this->overrideResponse) {
            Context::set(ResponseInterface::class, $response);
        }

        return $response;
    }
}
