<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use Hypervel\Socialite\AbstractUser;

class User extends AbstractUser
{
    /**
     * The user's access token.
     */
    public ?string $token = null;

    /**
     * The refresh token that can be exchanged for a new access token.
     */
    public ?string $refreshToken = null;

    /**
     * The number of seconds the access token is valid for.
     */
    public ?int $expiresIn = null;

    /**
     * The scopes the user authorized. The approved scopes may be a subset of the requested scopes.
     */
    public array $approvedScopes = [];

    /**
     * Set the token on the user.
     */
    public function setToken(?string $token): static
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Set the refresh token required to obtain a new access token.
     */
    public function setRefreshToken(?string $refreshToken): static
    {
        $this->refreshToken = $refreshToken;

        return $this;
    }

    /**
     * Set the number of seconds the access token is valid for.
     */
    public function setExpiresIn(?int $expiresIn): static
    {
        $this->expiresIn = $expiresIn;

        return $this;
    }

    /**
     * Set the scopes that were approved by the user during authentication.
     */
    public function setApprovedScopes(array $approvedScopes): static
    {
        $this->approvedScopes = $approvedScopes;

        return $this;
    }
}
