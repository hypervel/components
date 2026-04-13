<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    public bool $timestamps = false;
}
