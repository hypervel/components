<?php

declare(strict_types=1);

namespace Hypervel\Head;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class SchemaType
{
    /**
     * Define the schema.org type for a schema class.
     */
    public function __construct(public string $name)
    {
    }
}
