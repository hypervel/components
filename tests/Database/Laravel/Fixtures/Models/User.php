<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Fixtures\Models;

use Hypervel\Auth\Authenticatable as FoundationUser;

class User extends FoundationUser
{
    protected $primaryKey = 'internal_id';
}
