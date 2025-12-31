<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Declare the policy class for a model using an attribute.
 *
 * When placed on a model class, the Gate will use the specified policy
 * class for authorization checks. This takes precedence over policy
 * name guessing but not over explicitly registered policies.
 *
 * @example
 * ```php
 * #[UsePolicy(PostPolicy::class)]
 * class Post extends Model {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UsePolicy
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string $class
     */
    public function __construct(
        public string $class
    ) {
    }
}
