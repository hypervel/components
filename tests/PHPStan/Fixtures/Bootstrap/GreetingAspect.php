<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap;

use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;

class GreetingAspect extends AbstractAspect
{
    public array $classes = [AspectGreeter::class . '::greet'];

    /**
     * Pass the call through unchanged.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->process();
    }
}
