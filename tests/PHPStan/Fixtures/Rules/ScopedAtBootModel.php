<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Rules;

use Hypervel\Database\Eloquent\Model;
use UnitEnum;

/**
 * A model that the analyzer bootstrap registers a global scope on, as a service provider can.
 */
class ScopedAtBootModel extends Model
{
    protected UnitEnum|string|null $connection = 'sqlite';
}
