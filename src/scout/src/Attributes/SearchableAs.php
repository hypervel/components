<?php

declare(strict_types=1);

namespace Hypervel\Scout\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class SearchableAs
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(public string $index)
    {
    }
}
