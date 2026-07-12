<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Database\Eloquent\Model;

class PlainAuthenticatableUser extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected array $fillable = ['email'];

    public bool $timestamps = false;

    protected ?string $table = 'users';
}
