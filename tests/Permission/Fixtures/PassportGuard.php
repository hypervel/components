<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Tests\Permission\Fixtures\Models\Client;

class PassportGuard implements Guard
{
    public function __construct(
        protected ?Client $client = null,
    ) {
    }

    public function check(): bool
    {
        return false;
    }

    public function guest(): bool
    {
        return true;
    }

    public function user(): ?Authenticatable
    {
        return null;
    }

    public function id(): int|string|null
    {
        return null;
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): static
    {
        return $this;
    }

    public function client(): ?Client
    {
        return $this->client;
    }
}
