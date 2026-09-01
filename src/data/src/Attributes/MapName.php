<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Hypervel\Data\Mappers\NameMapper;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class MapName
{
    public readonly string|int|NameMapper $input;

    public readonly string|int|NameMapper $output;

    /**
     * Create a new input and output name mapping attribute.
     */
    public function __construct(
        string|int|NameMapper $input,
        string|int|NameMapper|null $output = null,
    )
    {
        $this->input = $input;
        $this->output = $output ?? $input;
    }
}
