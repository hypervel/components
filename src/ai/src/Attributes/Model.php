<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Model
{
    /**
     * Create a model selection attribute.
     */
    public function __construct(public string $value)
    {
    }
}
