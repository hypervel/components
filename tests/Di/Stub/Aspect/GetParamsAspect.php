<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Stub\Aspect;

use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;

class GetParamsAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->getArguments();
    }
}
