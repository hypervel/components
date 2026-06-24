<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories\Attributes;

use Attribute;

/**
 * Declare the model class for a factory using an attribute.
 *
 * When placed on a factory class, the specified model will be used when
 * resolving the factory's corresponding model name.
 *
 * @example
 * ```php
 * #[UseModel(Post::class)]
 * class PostFactory extends Factory
 * {
 *     //
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UseModel
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string<\Hypervel\Database\Eloquent\Model> $class
     */
    public function __construct(
        public string $class,
    ) {
    }
}
