<?php

declare(strict_types=1);

namespace Hypervel\Socialite\One;

use Hypervel\Socialite\AbstractUser;

class User extends AbstractUser
{
    /**
     * The user's access token.
     */
    public string $token;

    /**
     * The user's access token secret.
     */
    public string $tokenSecret;

    /**
     * Set the token on the user.
     */
    public function setToken(string $token, string $tokenSecret): static
    {
        $this->token = $token;
        $this->tokenSecret = $tokenSecret;

        return $this;
    }
}
