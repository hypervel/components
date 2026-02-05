<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Exception\InvalidArgumentException;
use Hypervel\Engine\Channel;
use Throwable;

/**
 * @method bool isFull()
 * @method bool isEmpty()
 */
class Concurrent
{
    protected Channel $channel;

    public function __construct(
        protected int $limit,
    ) {
        $this->channel = new Channel($limit);
    }

    /**
     * Proxy isFull() and isEmpty() to the channel.
     *
     * @return mixed
     * @throws InvalidArgumentException When method is not supported
     */
    public function __call(string $name, array $arguments)
    {
        if (in_array($name, ['isFull', 'isEmpty'])) {
            return $this->channel->{$name}(...$arguments);
        }

        throw new InvalidArgumentException(sprintf('The method %s is not supported.', $name));
    }

    /**
     * Get the concurrency limit.
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get the current number of running coroutines.
     */
    public function length(): int
    {
        return $this->channel->getLength();
    }

    /**
     * Get the current number of running coroutines.
     */
    public function getLength(): int
    {
        return $this->channel->getLength();
    }

    /**
     * Get the current number of running coroutines.
     */
    public function getRunningCoroutineCount(): int
    {
        return $this->getLength();
    }

    /**
     * Get the underlying channel.
     */
    public function getChannel(): Channel
    {
        return $this->channel;
    }

    /**
     * Create a new coroutine with concurrency limiting.
     */
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

    /**
     * Report an exception through the exception handler.
     */
    protected function reportException(Throwable $throwable): void
    {
        if (! ApplicationContext::hasContainer()) {
            return;
        }

        $container = ApplicationContext::getContainer();

        if ($container->has(ExceptionHandlerContract::class)) {
            $container->get(ExceptionHandlerContract::class)
                ->report($throwable);
        }
    }
}
