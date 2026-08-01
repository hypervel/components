<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\WebSocketServer\Server as WebSocketServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WebSocketKernel extends WebSocketServer
{
    /**
     * Handle an exception using the application's exception handler.
     *
     * Overrides the base WebSocket handler to use the app-level exception
     * handler, matching how the HTTP kernel delegates exception handling.
     */
    protected function handleException(Throwable $throwable): Response
    {
        // Keep the original in flight while it is handled, so a failure in
        // reporting or rendering carries it as that failure's previous. The
        // return suppresses it once a response exists.
        try {
            /* @phpstan-ignore finally.exitPoint */
            throw $throwable;
        } finally {
            $handler = $this->container->make(ExceptionHandlerContract::class);

            $handler->report($throwable);

            /* @phpstan-ignore finally.exitPoint */
            return $handler->render(RequestContext::get(), $throwable);
        }
    }
}
