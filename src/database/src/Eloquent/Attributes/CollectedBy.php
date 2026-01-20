<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class CollectedBy
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string<\Hypervel\Database\Eloquent\Collection<*, *>> $collectionClass
     */
    public function __construct(public string $collectionClass)
    {
    }
}
