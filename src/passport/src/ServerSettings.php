<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use DateInterval;

readonly class ServerSettings
{
    /**
     * Define an authorization server's keys and optional configuration overrides.
     *
     * @param list<ServerKey> $keys
     * @param array<string, string> $scopes
     */
    public function __construct(
        public array $keys,
        public ?DateInterval $tokensExpireIn = null,
        public ?DateInterval $refreshTokensExpireIn = null,
        public ?DateInterval $personalAccessTokensExpireIn = null,
        public ?DateInterval $clientCredentialsTokensExpireIn = null,
        public array $scopes = [],
        public ?string $issuer = null,
    ) {
    }
}
