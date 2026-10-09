<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ProxySource
{
    /**
     * Record the original source file of a generated proxy.
     */
    public function __construct(public readonly string $path)
    {
    }
}
