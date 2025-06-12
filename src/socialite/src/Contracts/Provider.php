<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Contracts;

use Psr\Http\Message\ResponseInterface;

interface Provider
{
    /**
     * Redirect the user to the authentication page for the provider.
     */
    public function redirect(): ResponseInterface;

    /**
     * Get the User instance for the authenticated user.
     */
    public function user(): User;
}
