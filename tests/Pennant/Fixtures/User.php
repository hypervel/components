<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Fixtures;

use Hypervel\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected array $guarded = [];
}
