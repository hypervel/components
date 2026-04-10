<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Fixtures\Aspect;

use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;

class NoPriorityAspect extends AbstractAspect
{
    public array $classes = [
        'App\FooService::handle',
    ];

    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->process();
    }
}
