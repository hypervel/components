<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Concurrent as BaseConcurrent;
use Hyperf\ExceptionHandler\Formatter\FormatterInterface;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Throwable;

class Concurrent extends BaseConcurrent
{
    public function create(callable $callable): void
    {
        $this->channel->push(true);

        Coroutine::create(function () use ($callable) {
            try {
                $callable();
            } catch (Throwable $exception) {
                $this->reportException($exception);
            } finally {
                $this->channel->pop();
            }
        });
    }

    protected function reportException(Throwable $throwable): void
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

        if ($container->has(StdoutLoggerInterface::class) && $container->has(FormatterInterface::class)) {
            $logger = $container->get(StdoutLoggerInterface::class);
            $formatter = $container->get(FormatterInterface::class);
            $logger->error($formatter->format($throwable));
        }
    }
}
