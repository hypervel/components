<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Fixtures\Models;

use Hypervel\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    use Authenticatable;

    protected string $primaryKey = 'internal_id';
}
