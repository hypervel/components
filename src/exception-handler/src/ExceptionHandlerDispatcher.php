<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler;

use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Dispatcher\AbstractDispatcher;
use InvalidArgumentException;
use Throwable;

class ExceptionHandlerDispatcher extends AbstractDispatcher
{
    /**
     * Create a new exception handler dispatcher instance.
     */
    public function __construct(private Container $container)
    {
    }

    /**
     * Dispatch the exception through the handler stack.
     *
     * Expects: Throwable $throwable, string[] $handlers.
     */
    public function dispatch(...$params)
    {
        [$throwable, $handlers] = $params;
        $response = ResponseContext::get();

        foreach ($handlers as $handler) {
            if (! $this->container->has($handler)) {
                throw new InvalidArgumentException(sprintf('Invalid exception handler %s.', $handler));
            }
            $handlerInstance = $this->container->get($handler);
            if (! $handlerInstance instanceof ExceptionHandler || ! $handlerInstance->isValid($throwable)) {
                continue;
            }
            $response = $handlerInstance->handle($throwable, $response);
            if ($handlerInstance->isPropagationStopped()) {
                break;
            }
        }
        return $response;
    }
}
