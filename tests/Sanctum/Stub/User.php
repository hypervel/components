<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Stub;

use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Sanctum\HasApiTokens;

class User implements Authenticatable
{
    use HasApiTokens;

    public int $id = 1;

    public bool $wasRecentlyCreated = false;

    public string $email = 'test@example.com';

    public string $password = '';

    public string $name = 'Test User';

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
        return $this->password ?: 'password';
    }
}
