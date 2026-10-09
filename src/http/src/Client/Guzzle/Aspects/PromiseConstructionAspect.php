<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle\Aspects;

use GuzzleHttp\Promise\Promise;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Http\Client\Guzzle\CoroutineOwnership;

class PromiseConstructionAspect extends AbstractAspect
{
    public array $classes = [Promise::class . '::__construct'];

    /**
     * Record ownership after the promise constructor succeeds.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        $result = $proceedingJoinPoint->process();
        CoroutineOwnership::record($proceedingJoinPoint->getInstance());

        return $result;
    }
}
