<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Timeout
{
    /**
     * Create a generation timeout attribute.
     */
    public function __construct(public int $value)
    {
    }
}
