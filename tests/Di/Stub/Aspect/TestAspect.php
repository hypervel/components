<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Stub\Aspect;

use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;

class TestAspect extends AbstractAspect
{
    public array $classes = [
        'App\SomeClass::someMethod',
        'App\AnotherClass',
    ];

    public ?int $priority = 10;

    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->process();
    }
}
