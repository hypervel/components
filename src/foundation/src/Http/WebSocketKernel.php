<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\WebSocketServer\Server as WebSocketServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Hypervel\Support\throw_with_previous;

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
        try {
            $handler = $this->container->make(ExceptionHandlerContract::class);

            $handler->report($throwable);

            return $handler->render(RequestContext::get(), $throwable);
        } catch (Throwable $failure) {
            throw_with_previous($failure, $throwable);
        }
    }
}
