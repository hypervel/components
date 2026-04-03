<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Fixtures;

use Hypervel\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Sanctum\HasApiTokens;

/**
 * Test user model for authentication tests.
 */
class TestUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;

    protected ?string $table = 'users';

    protected array $fillable = ['name', 'email', 'password'];
}
