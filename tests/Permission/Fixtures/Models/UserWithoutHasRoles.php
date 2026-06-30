<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\Access\Authorizable;

class UserWithoutHasRoles extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;

    protected array $fillable = ['email'];

    public bool $timestamps = false;

    protected ?string $table = 'users';
}
