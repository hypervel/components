<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Coroutine;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ExceptionThrower;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Coroutine\Waiter as BaseWaiter;
use Hypervel\Engine\Channel;
use Throwable;

class Waiter extends BaseWaiter
{
    public function wait(Closure $closure, ?float $timeout = null): mixed
    {
        if ($timeout === null) {
            $timeout = $this->popTimeout;
        }

        $channel = new Channel(1);
        $coroutineId = Coroutine::id();
        Coroutine::create(function () use ($channel, $closure, $coroutineId) {
            if ($coroutineId) {
                CoroutineContext::copyFrom($coroutineId);
            }

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
