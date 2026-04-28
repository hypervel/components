<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Collects
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(public string $class)
    {
    }
}
