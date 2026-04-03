<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Seed
{
    /**
     * Create a new attribute instance.
     */
    public function __construct()
    {
    }
}
