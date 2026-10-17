<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use DateInterval;
use Hypervel\Support\Uri;
use InvalidArgumentException;

readonly class ServerSettings
{
    /**
     * Define an authorization server's keys and optional configuration overrides.
     *
     * @param list<ServerKey> $keys
     * @param array<string, string> $scopes
     * @param int $metadataCacheLifetime Discovery and JWKS HTTP freshness in seconds
     * @param array<string, string> $resources
     */
    public function __construct(
        public array $keys,
        public ?DateInterval $tokensExpireIn = null,
        public ?DateInterval $refreshTokensExpireIn = null,
        public ?DateInterval $personalAccessTokensExpireIn = null,
        public ?DateInterval $clientCredentialsTokensExpireIn = null,
        public array $scopes = [],
        public ?string $issuer = null,
        public int $metadataCacheLifetime = 0,
        public array $resources = [],
    ) {
        ProtectedResource::validateIdentifiers($resources);

        if ($issuer !== null) {
            $uri = Uri::of($issuer)->getUri();

            if ($uri->getScheme() !== 'https' || $uri->getHost() === null || $uri->getHost() === ''
                || $uri->getUserInfo() !== null || $uri->getQuery() !== null || $uri->getFragment() !== null) {
                throw new InvalidArgumentException('Passport issuers must be absolute HTTPS URLs without user information, query parameters or fragments.');
            }
        }

        if ($metadataCacheLifetime < 0) {
            throw new InvalidArgumentException('The Passport metadata cache lifetime cannot be negative.');
        }
    }
}
