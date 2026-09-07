<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class MapInputName
{
    /**
     * Create a new input name mapping attribute.
     */
    public function __construct(public readonly string|int $input)
    {
    }
}
