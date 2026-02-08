<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Auth;

use Hypervel\Auth\Access\Authorizable;
use Hypervel\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Database\Eloquent\Model;

class User extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;
}
