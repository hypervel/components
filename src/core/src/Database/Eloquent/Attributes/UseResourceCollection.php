<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Declare the resource collection class for a model using an attribute.
 *
 * When placed on a model class, collections of this model will use the specified
 * resource collection class when calling toResourceCollection().
 *
 * @example
 * ```php
 * #[UseResourceCollection(PostCollection::class)]
 * class Post extends Model {}
 *
 * // Now Post::all()->toResourceCollection() will use PostCollection
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UseResourceCollection
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string<\Hypervel\Http\Resources\Json\ResourceCollection> $class
     */
    public function __construct(
        public string $class,
    ) {
    }
}
