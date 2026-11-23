<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Database\Eloquent\Model;
use UnitEnum;

class ModelMakeTarget extends Model
{
    protected UnitEnum|string|null $connection = 'sqlite';
}
