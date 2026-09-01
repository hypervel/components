<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class MapOutputName
{
    /**
     * Create a new output name mapping attribute.
     */
    public function __construct(public readonly string|int $output)
    {
    }
}
