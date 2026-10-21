<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class MaxTokens
{
    /**
     * Create a maximum token count attribute.
     */
    public function __construct(public int $value)
    {
    }
}
