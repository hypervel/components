<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class DateFormat
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(public string $format)
    {
    }
}
