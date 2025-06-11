<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use Psr\Http\Message\ResponseInterface;

interface ProviderInterface
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
