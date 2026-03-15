<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Request;
use Hypervel\Pipeline\Pipeline as BasePipeline;
use Throwable;

/**
 * This extended pipeline catches any exceptions that occur during each slice.
 *
 * The exceptions are converted to HTTP responses for proper middleware handling.
 */
class Pipeline extends BasePipeline
{
    /**
     * Handle the value returned from each pipe before passing it to the next.
     */
    protected function handleCarry(mixed $carry): mixed
    {
        return $carry instanceof Responsable
            ? $carry->toResponse($this->getContainer()->make(Request::class))
            : $carry;
    }

    /**
     * Handle the given exception.
     *
     * @throws Throwable
     */
    protected function handleException(mixed $passable, Throwable $e): mixed
    {
        if (! $this->container->bound(ExceptionHandler::class)
            || ! $passable instanceof Request) {
            throw $e;
        }

        $handler = $this->container->make(ExceptionHandler::class);

        $handler->report($e);

        $response = $handler->render($passable, $e);

        if (method_exists($response, 'withException')) {
            $response->withException($e);
        }

        return $this->handleCarry($response);
    }
}
