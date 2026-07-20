<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Coroutine;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Waiter as BaseWaiter;

class Waiter extends BaseWaiter
{
    public function wait(Closure $closure, ?float $timeout = null): mixed
    {
        $context = CoroutineContext::captureFrom();

        return parent::wait(function () use ($closure, $context): mixed {
            CoroutineContext::setMany($context);

            return $closure();
        }, $timeout);
    }
}
