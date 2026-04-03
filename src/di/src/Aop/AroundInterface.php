<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

interface AroundInterface
{
    /**
     * Process the join point and return the result.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed;
}
