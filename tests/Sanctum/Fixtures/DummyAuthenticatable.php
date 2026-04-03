<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Fixtures;

use Hypervel\Contracts\Auth\Authenticatable;

/**
 * Minimal Authenticatable stub for tests that need a user object
 * but don't exercise authentication behavior.
 *
 * @internal
 */
class DummyAuthenticatable implements Authenticatable
{
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return 1;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(string $value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
