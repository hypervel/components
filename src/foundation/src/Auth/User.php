<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Auth;

use Hypervel\Auth\Authenticatable;
use Hypervel\Auth\MustVerifyEmail;
use Hypervel\Auth\Passwords\CanResetPassword;
use Hypervel\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\Access\Authorizable;

class User extends Model implements AuthenticatableContract, AuthorizableContract, CanResetPasswordContract
{
    use Authenticatable;
    use Authorizable;
    use CanResetPassword;
    use MustVerifyEmail;
}
