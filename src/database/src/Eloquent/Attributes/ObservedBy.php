<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Declare observers for a model using an attribute.
 *
 * When placed on a model class, the specified observer(s) will be automatically
 * registered when the model boots. Attributes on parent classes are inherited
 * by child classes.
 *
 * @example
 * ```php
 * #[ObservedBy(UserObserver::class)]
 * class User extends Model {}
 *
 * #[ObservedBy([AuditObserver::class, CacheObserver::class])]
 * class Post extends Model {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ObservedBy
{
    /**
     * Create a new attribute instance.
     *
     * @param class-string|class-string[] $classes
     */
    public function __construct(
        public array|string $classes
    ) {
    }
}
