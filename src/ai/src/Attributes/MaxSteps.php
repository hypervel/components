<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class MaxSteps
{
    /**
     * Create a maximum generation steps attribute.
     */
    public function __construct(public int $value)
    {
    }
}
