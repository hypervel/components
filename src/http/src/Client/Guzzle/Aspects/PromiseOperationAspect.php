<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle\Aspects;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Http\Client\Guzzle\CoroutineOwnership;

class PromiseOperationAspect extends AbstractAspect
{
    public array $classes = [
        Promise::class . '::then',
        Promise::class . '::wait',
        Promise::class . '::cancel',
        Promise::class . '::resolve',
        Promise::class . '::reject',
    ];

    /**
     * Keep pending operations and adopted work in their owning coroutine.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        /** @var Promise $promise */
        $promise = $proceedingJoinPoint->getInstance();
        $method = $proceedingJoinPoint->methodName;
        CoroutineOwnership::ensureOwner($promise, $method);

        if ($promise->getState() !== PromiseInterface::PENDING || ($method !== 'resolve' && $method !== 'reject')) {
            return $proceedingJoinPoint->process();
        }

        $value = $proceedingJoinPoint->arguments['keys'][$method === 'resolve' ? 'value' : 'reason'] ?? null;
        $adopting = $value instanceof PromiseInterface && ! CoroutineOwnership::completed($value);

        if ($adopting && $value instanceof Promise) {
            CoroutineOwnership::ensureOwner($value, 'adopt');
        }

        $result = $proceedingJoinPoint->process();

        // A rejected self-resolution must not leave adoption metadata behind.
        if ($adopting) {
            CoroutineOwnership::adopt($promise, $value);
        }

        CoroutineOwnership::settled($promise);

        return $result;
    }
}
