<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\GuzzleOwnership\Fixtures;

use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;

class NoopAspect extends AbstractAspect
{
    /**
     * Measure normal aspect dispatch without ownership bookkeeping.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->process();
    }
}
