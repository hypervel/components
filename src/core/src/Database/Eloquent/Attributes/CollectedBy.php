<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Declare the collection class for a model using an attribute.
 *
 * When placed on a model class, the model will use the specified collection
 * class when creating new collection instances via newCollection().
 *
 * @example
 * ```php
 * #[CollectedBy(CustomCollection::class)]
 * class User extends Model {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class CollectedBy
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string<\Hypervel\Database\Eloquent\Collection<array-key, \Hypervel\Database\Eloquent\Model>> $collectionClass
     */
    public function __construct(
        public string $collectionClass,
    ) {
    }
}
