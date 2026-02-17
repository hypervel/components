<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Declare global scopes to be automatically applied to the model.
 *
 * Can be applied to model classes or traits. Supports both single scope
 * class and arrays of scope classes. Repeatable for multiple declarations.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ScopedBy
{
    /**
     * @param class-string|class-string[] $classes
     */
    public function __construct(
        public array|string $classes,
    ) {
    }
}
