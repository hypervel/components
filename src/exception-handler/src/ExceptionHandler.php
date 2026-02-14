<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler;

use Swow\Psr7\Message\ResponsePlusInterface;
use Throwable;

abstract class ExceptionHandler
{
    /**
     * Handle the exception, and return the specified result.
     */
    abstract public function handle(Throwable $throwable, ResponsePlusInterface $response);

    /**
     * Determine if the current exception handler should handle the exception.
     *
     * @see ExceptionHandler::stopPropagation() if you want to stop propagation after handling
     * an exception, as returning `true` in `isValid` does not stop the handlers call loop.
     *
     * @return bool If return true, then this exception handler will handle the exception and then call the next handler,
     *              If return false, this handler will be ignored and the next will be called
     */
    abstract public function isValid(Throwable $throwable): bool;

    /**
     * Stop propagation of the exception to the next handler.
     */
    public function stopPropagation(): bool
    {
        Propagation::instance()->setPropagationStopped(true);
        return true;
    }

    /**
     * Determine if propagation has been stopped.
     */
    public function isPropagationStopped(): bool
    {
        return Propagation::instance()->isPropagationStopped();
    }
}
