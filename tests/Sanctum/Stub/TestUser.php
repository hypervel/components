<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Stub;

use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Sanctum\HasApiTokens;

/**
 * Test user model for authentication tests
 */
class TestUser extends Model implements Authenticatable
{
    use HasApiTokens;
    
    protected ?string $table = 'users';
    
    protected array $fillable = ['name', 'email', 'password'];
    
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }
}