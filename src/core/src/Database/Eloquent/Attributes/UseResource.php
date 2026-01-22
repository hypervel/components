<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Declare the resource class for a model using an attribute.
 *
 * When placed on a model class that uses the TransformsToResource trait,
 * the specified resource will be used when calling the model's toResource() method.
 *
 * @example
 * ```php
 * #[UseResource(PostResource::class)]
 * class Post extends Model {}
 *
 * // Now $post->toResource() will use PostResource
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UseResource
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string<\Hypervel\Http\Resources\Json\JsonResource> $class
     */
    public function __construct(
        public string $class,
    ) {
    }
}
