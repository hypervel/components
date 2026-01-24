<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Auth\Access\Authorizable;
use Hypervel\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;

class AuthorizableStub extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;
}
