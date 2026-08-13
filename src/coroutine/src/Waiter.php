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
    /**
     * The default timeout for deferred result delivery and cancelled child unwind.
     */
    public const float DEFAULT_PUSH_TIMEOUT_SECONDS = 10.0;

    protected const float DEFAULT_POP_TIMEOUT_SECONDS = 10.0;

    protected float $pushTimeout = self::DEFAULT_PUSH_TIMEOUT_SECONDS;

    protected float $popTimeout = self::DEFAULT_POP_TIMEOUT_SECONDS;

    public function __construct(float $timeout = self::DEFAULT_POP_TIMEOUT_SECONDS)
    {
        $this->popTimeout = $timeout;
    }

    /**
     * Execute a closure in a coroutine and wait for the result.
     *
     * @template TReturn
     * @param Closure():TReturn $closure
     * @param null|float $timeout Timeout in seconds (null uses default)
     * @param array<string>|bool $copyContext When set, parent coroutine context is copied to the child.
     *                                        false = fresh context (default), true or empty array = copy all keys, non-empty array = copy listed keys only.
     *                                        Object values are shared by reference unless they implement Hypervel\Context\ReplicableContext.
     * @return TReturn
     * @throws WaitTimeoutException When the wait times out
     */
    public function wait(Closure $closure, ?float $timeout = null, bool|array $copyContext = false): mixed
    {
        if ($timeout === null) {
            $timeout = $this->popTimeout;
        }

        $channel = new Channel(1);
        $callable = function () use ($channel, $closure): void {
            $result = null;

            Coroutine::defer(function () use ($channel, &$result): void {
                $channel->push($result, $this->pushTimeout);
            });

            try {
                $result = $closure();
            } catch (Throwable $exception) {
                $result = new ExceptionThrower($exception);
            }
        };
        $childCoroutineId = $copyContext === false
            ? Coroutine::create($callable)
            : Coroutine::fork($callable, is_array($copyContext) ? $copyContext : []);

        $result = $channel->pop($timeout);
        if ($result === false && $channel->isTimeout()) {
            // Throw into the operation so an interrupted wait cannot be ignored accidentally,
            // then give the child a bounded interval to unwind and run deferred cleanup.
            EngineCoroutine::cancelById($childCoroutineId, throwException: true);
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
