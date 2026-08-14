<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hyperf\HttpServer\Router\Dispatched;
use Hypervel\Dispatcher\Pipeline as BasePipeline;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Foundation\Exceptions\Handler as ExceptionHandler;
use Hypervel\Http\DispatchedRoute;
use Hypervel\Http\Request;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * This extended pipeline catches any exceptions that occur during each slice.
 *
 * The exceptions are converted to HTTP responses, so middleware wrapping the
 * pipe that threw can observe the status the client actually receives instead
 * of only seeing the throwable propagate past them.
 */
class Pipeline extends BasePipeline
{
    /**
     * Handle the given exception.
     *
     * @throws Throwable
     */
    protected function handleException(mixed $passable, Throwable $e): mixed
    {
        if (! $this->isHttpRequest($passable)) {
            throw $e;
        }

        // Keep the original in flight while it is handled, so a failure in
        // reporting or rendering carries it as that failure's previous. The
        // return suppresses it once a response exists.
        try {
            /* @phpstan-ignore finally.exitPoint */
            throw $e;
        } finally {
            $handler = $this->getExceptionHandler();

            $handler->report($e);

            /* @phpstan-ignore finally.exitPoint */
            return $this->handleCarry(
                $handler->render($this->container->get(Request::class), $e)
            );
        }
    }

    /**
     * Determine whether the passable belongs to the HTTP server's pipeline.
     *
     * Only Hypervel\Http\CoreMiddleware::dispatch() attaches a DispatchedRoute.
     * The WebSocket server shares this pipeline but attaches Hyperf's plain
     * Dispatched, and its kernel relies on catching the throwable itself to
     * release the connection's fd and context, so handshakes are left alone.
     */
    protected function isHttpRequest(mixed $passable): bool
    {
        return $passable instanceof ServerRequestInterface
            && $passable->getAttribute(Dispatched::class) instanceof DispatchedRoute;
    }

    /**
     * Resolve the exception handler used to report and render the exception.
     *
     * Mirrors Kernel::initExceptionHandlers(), falling back to the framework
     * handler so applications that never bound the contract still get their
     * exceptions converted to responses.
     */
    protected function getExceptionHandler(): ExceptionHandlerContract
    {
        return $this->container->get(
            $this->container->has(ExceptionHandlerContract::class)
                ? ExceptionHandlerContract::class
                : ExceptionHandler::class
        );
    }
}
