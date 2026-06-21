<?php

declare(strict_types=1);

namespace Hypervel\Console\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Hidden
{
    /**
     * Create a new attribute instance.
     */
    public function __construct()
    {
    }
}
