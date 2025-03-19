<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Coroutine;

use Closure;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Coroutine\Exception\ExceptionThrower;
use Hyperf\Coroutine\Exception\WaitTimeoutException;
use Hyperf\Coroutine\Waiter as HyperfWaiter;
use Hyperf\Engine\Channel;
use Throwable;

class Waiter extends HyperfWaiter
{
    public function wait(Closure $closure, ?float $timeout = null)
    {
        if ($timeout === null) {
            $timeout = $this->popTimeout;
        }

        $channel = new Channel(1);
        $coroutineId = Coroutine::id();
        Coroutine::create(function () use ($channel, $closure, $coroutineId) {
            if ($coroutineId) {
                Context::copy($coroutineId);
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
