<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;
use Hypervel\Database\Eloquent\Collection;

#[Attribute(Attribute::TARGET_CLASS)]
class CollectedBy
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string<Collection<*, *>> $collectionClass
     */
    public function __construct(public string $collectionClass)
    {
    }
}
