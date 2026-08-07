<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use SensitiveParameter;

class Token
{
    /**
     * Create a new token instance.
     *
     * @param string $token the user's access token
     * @param string $refreshToken the refresh token that can be exchanged for a new access token
     * @param null|int $expiresIn the number of seconds the access token is valid for
     * @param array $approvedScopes The scopes the user authorized. The approved scopes may be a subset of the requested scopes.
     */
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        #[SensitiveParameter]
        public string $refreshToken,
        public ?int $expiresIn,
        public array $approvedScopes
    ) {
    }
}
