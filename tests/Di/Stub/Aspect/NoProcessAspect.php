<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Stub\Aspect;

use Hypervel\Di\Aop\ProceedingJoinPoint;

class NoProcessAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        return true;
    }
}
