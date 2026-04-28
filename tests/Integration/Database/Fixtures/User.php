<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures;

use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    protected array $guarded = [];
}
