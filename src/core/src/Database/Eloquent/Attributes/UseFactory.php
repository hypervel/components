<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Declare the factory class for a model using an attribute.
 *
 * When placed on a model class that uses the HasFactory trait, the specified
 * factory will be used when calling the model's factory() method.
 *
 * @example
 * ```php
 * #[UseFactory(PostFactory::class)]
 * class Post extends Model
 * {
 *     use HasFactory;
 * }
 *
 * // Now Post::factory() will use PostFactory
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UseFactory
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string<\Hypervel\Database\Eloquent\Factories\Factory> $class
     */
    public function __construct(
        public string $class,
    ) {
    }
}
