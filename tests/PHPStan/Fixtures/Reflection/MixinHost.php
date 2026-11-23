<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Reflection;

/**
 * A class whose mixin is its template type.
 *
 * @template T of object
 * @mixin T
 */
class MixinHost
{
}
