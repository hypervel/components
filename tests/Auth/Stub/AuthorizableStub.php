<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Auth\Access\Authorizable;
use Hypervel\Auth\Authenticatable;
use Hypervel\Auth\Contracts\Authenticatable as AuthenticatableContract;
use Hypervel\Auth\Contracts\Authorizable as AuthorizableContract;

class AuthorizableStub extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;
}
