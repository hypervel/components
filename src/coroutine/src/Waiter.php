<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Closure;
use Hypervel\Coroutine\Exception\ExceptionThrower;
use Hypervel\Coroutine\Exception\WaitTimeoutException;
use Hypervel\Engine\Channel;
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
     * @param float|null $timeout Timeout in seconds (null uses default)
     * @return TReturn
     * @throws WaitTimeoutException When the wait times out
     */
    public function wait(Closure $closure, ?float $timeout = null): mixed
    {
        if ($timeout === null) {
            $timeout = $this->popTimeout;
        }

        $channel = new Channel(1);
        Coroutine::create(function () use ($channel, $closure) {
            try {
                $result = $closure();
            } catch (Throwable $exception) {
                $result = new ExceptionThrower($exception);
            } finally {
                $channel->push($result ?? null, $this->pushTimeout);
            }
        });

        $result = $channel->pop($timeout);
        if ($result === false && $channel->isTimeout()) {
            throw new WaitTimeoutException(sprintf('Channel wait failed, reason: Timed out for %s s', $timeout));
        }
        if ($result instanceof ExceptionThrower) {
            throw $result->getThrowable();
        }

        return $result;
    }
}
