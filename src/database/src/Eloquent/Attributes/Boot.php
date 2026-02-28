<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

/**
 * Mark a static method as a boot method for a trait.
 *
 * This attribute allows trait boot methods to be named anything,
 * instead of requiring the conventional `boot{TraitName}` naming.
 *
 * @example
 * ```php
 * trait HasCustomBehavior
 * {
 *     #[Boot]
 *     public static function registerCustomBehavior(): void
 *     {
 *         // This method will be called during model boot
 *     }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Boot
{
}
