<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Mark a method as an initialize method for a trait.
 *
 * This attribute allows trait initialize methods to be named anything,
 * instead of requiring the conventional `initialize{TraitName}` naming.
 * Initialize methods are called on each new model instance.
 *
 * @example
 * ```php
 * trait HasCustomBehavior
 * {
 *     #[Initialize]
 *     public function setupCustomBehavior(): void
 *     {
 *         // This method will be called when a new model instance is created
 *     }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Initialize
{
}
