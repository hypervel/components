<?php

declare(strict_types=1);

namespace Hypervel\Log\Formatters;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;
use Override;
use Throwable;

class JsonFormatter extends MonologJsonFormatter
{
    #[Override]
    protected function normalizeException(Throwable $e, int $depth = 0): array
    {
        $response = parent::normalizeException($e, $depth);

        try {
            $handler = Container::getInstance()->make(ExceptionHandler::class);
        } catch (Throwable) {
            return array_merge($this->getExceptionContext($e, $depth), $response);
        }

        if (! method_exists($handler, 'isReporting') || ! $handler->isReporting($e)) {
            if (method_exists($handler, 'buildContextForException')
                && is_array($context = $this->normalize($handler->buildContextForException($e), $depth + 1))
            ) {
                $response = array_merge($context, $response);
            } elseif (method_exists($e, 'context')) {
                $response = array_merge($this->getExceptionContext($e, $depth), $response);
            }
        }

        return $response;
    }

    /**
     * Extract the context from the exception if available.
     *
     * @return array<array-key, mixed>
     */
    protected function getExceptionContext(Throwable $e, int $depth): array
    {
        if (! method_exists($e, 'context')) {
            return [];
        }

        try {
            $context = $this->normalize($e->context(), $depth + 1);
        } catch (Throwable) {
            return [];
        }

        return is_array($context) ? $context : [];
    }
}
