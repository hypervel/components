<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class TopP
{
    /**
     * Create a nucleus sampling attribute.
     */
    public function __construct(public float $value)
    {
    }
}
