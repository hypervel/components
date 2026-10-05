<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Authenticators;

use DateTimeImmutable;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;

class CustomOAuthAuthenticator extends AccessTokenAuthenticator
{
    /**
     * Create a custom OAuth authenticator.
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $greeting,
        public readonly ?string $refreshToken = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    /**
     * Get the greeting.
     */
    public function getGreeting(): string
    {
        return $this->greeting;
    }
}
