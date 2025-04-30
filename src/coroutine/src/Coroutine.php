<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Coroutine as BaseCoroutine;
use Hyperf\ExceptionHandler\Formatter\FormatterInterface;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Throwable;

class Coroutine extends BaseCoroutine
{
    protected static function printLog(Throwable $throwable): void
    {
        if (! ApplicationContext::hasContainer()) {
            return;
        }

        $container = ApplicationContext::getContainer();

        if ($container->has(ExceptionHandlerContract::class)) {
            $container->get(ExceptionHandlerContract::class)
                ->report($throwable);
            return;
        }

        if ($container->has(StdoutLoggerInterface::class)) {
            $logger = $container->get(StdoutLoggerInterface::class);
            if ($container->has(FormatterInterface::class)) {
                $formatter = $container->get(FormatterInterface::class);
                $logger->warning($formatter->format($throwable));
            } else {
                $logger->warning((string) $throwable);
            }
        }
    }
}
