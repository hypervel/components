<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Macros;

use Hypervel\Auth\GuardHelpers;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard;

/**
 * A custom guard that natively declares the method other guards register as a macro.
 */
class NativeCallGuard implements Guard
{
    use GuardHelpers;

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable
    {
        return null;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        return false;
    }

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): static
    {
        return $this;
    }

    /**
     * Handle the call other guards supply as a macro.
     */
    public function guardMacro(): string
    {
        return '';
    }
}
