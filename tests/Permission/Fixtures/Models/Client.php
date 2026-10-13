<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Hypervel\Foundation\Auth\Access\Authorizable;
use Hypervel\Passport\Client as PassportClient;
use Hypervel\Permission\Traits\HasRoles;

class Client extends PassportClient implements AuthorizableContract
{
    use Authorizable;
    use HasRoles;

    protected string $guard_name = 'passport';
}
