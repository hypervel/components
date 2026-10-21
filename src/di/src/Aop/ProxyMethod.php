<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ProxyMethod
{
    /**
     * Record the generated helper containing this intercepted method's original body.
     */
    public function __construct(public readonly string $originalMethod)
    {
    }
}
