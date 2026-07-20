<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Closure;
use Hypervel\Coroutine\Exceptions\ExceptionThrower;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Throwable;

class Waiter
{
    protected float $pushTimeout = 10.0;

    protected float $popTimeout = 10.0;

    public function __construct(float $timeout = 10.0)
    {
        $this->popTimeout = $timeout;
    }

    /**
     * Execute a closure in a coroutine and wait for the result.
     *
     * @template TReturn
     * @param Closure():TReturn $closure
     * @param null|float $timeout Timeout in seconds (null uses default)
     * @return TReturn
     * @throws WaitTimeoutException When the wait times out
     */
    public function wait(Closure $closure, ?float $timeout = null): mixed
    {
        if ($timeout === null) {
            $timeout = $this->popTimeout;
        }

        $channel = new Channel(1);
        $childCoroutineId = Coroutine::create(function () use ($channel, $closure) {
            $result = null;

            Coroutine::defer(function () use ($channel, &$result): void {
                $channel->push($result, $this->pushTimeout);
            });

            try {
                $result = $closure();
            } catch (Throwable $exception) {
                $result = new ExceptionThrower($exception);
            }
        });

        $result = $channel->pop($timeout);
        if ($result === false && $channel->isTimeout()) {
            // Interrupt the operation without a catchable exception, then give the
            // child a bounded interval to unwind and run deferred cleanup.
            EngineCoroutine::cancelById($childCoroutineId);
            Coroutine::join([$childCoroutineId], $this->pushTimeout);

            throw new WaitTimeoutException(sprintf('Channel wait failed, reason: Timed out for %s s', $timeout));
        }

        Coroutine::join([$childCoroutineId]);

        if ($result instanceof ExceptionThrower) {
            throw $result->getThrowable();
        }

        return $result;
    }
}
