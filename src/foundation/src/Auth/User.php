<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Auth;

use Hypervel\Auth\Access\Authorizable;
use Hypervel\Auth\Authenticatable;
use Hypervel\Auth\Contracts\Authenticatable as AuthenticatableContract;
use Hypervel\Auth\Contracts\Authorizable as AuthorizableContract;
use Hypervel\Database\Eloquent\Model;

class User extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;
}
