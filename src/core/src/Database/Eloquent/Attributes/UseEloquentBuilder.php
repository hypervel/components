<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Declare the Eloquent builder class for a model using an attribute.
 *
 * When placed on a model class, the model will use the specified builder
 * class when creating new query builder instances via newModelBuilder().
 *
 * @example
 * ```php
 * #[UseEloquentBuilder(UserBuilder::class)]
 * class User extends Model {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UseEloquentBuilder
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string<\Hypervel\Database\Eloquent\Builder<\Hypervel\Database\Eloquent\Model>> $builderClass
     */
    public function __construct(
        public string $builderClass,
    ) {
    }
}
