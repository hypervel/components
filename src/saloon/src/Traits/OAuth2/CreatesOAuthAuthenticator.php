<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\OAuth2;

use Carbon\CarbonInterface;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Response;
use Hypervel\Support\Facades\Date;
use SensitiveParameter;
use UnexpectedValueException;

/**
 * @phpstan-require-extends Connector
 * @phpstan-ignore trait.unused (user-facing OAuth 2 trait)
 */
trait CreatesOAuthAuthenticator
{
    /**
     * Create an OAuth authenticator from a token response.
     */
    protected function createOAuthAuthenticatorFromResponse(
        #[SensitiveParameter]
        Response $response,
        #[SensitiveParameter]
        ?string $fallbackRefreshToken = null,
    ): OAuthAuthenticator {
        $data = $response->json();
        $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new UnexpectedValueException('The OAuth token response does not contain a valid access token.');
        }

        $refreshToken = $data['refresh_token'] ?? $fallbackRefreshToken;

        if ($refreshToken !== null && ! is_string($refreshToken)) {
            throw new UnexpectedValueException('The OAuth token response contains an invalid refresh token.');
        }

        return $this->createOAuthAuthenticator(
            $accessToken,
            $refreshToken,
            $this->resolveOAuthExpiry($data['expires_in'] ?? null),
        );
    }

    /**
     * Create an OAuth authenticator.
     */
    protected function createOAuthAuthenticator(
        #[SensitiveParameter]
        string $accessToken,
        #[SensitiveParameter]
        ?string $refreshToken = null,
        ?CarbonInterface $expiresAt = null,
    ): OAuthAuthenticator {
        return new AccessTokenAuthenticator($accessToken, $refreshToken, $expiresAt);
    }

    /**
     * Resolve an OAuth token expiry.
     */
    protected function resolveOAuthExpiry(mixed $expiresIn): ?CarbonInterface
    {
        if ($expiresIn === null) {
            return null;
        }

        if (is_string($expiresIn) && preg_match('/^(0|[1-9][0-9]*)$/D', $expiresIn) === 1) {
            $expiresIn = filter_var($expiresIn, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        }

        if (is_float($expiresIn)
            && is_finite($expiresIn)
            && floor($expiresIn) === $expiresIn
            && $expiresIn < PHP_INT_MAX) {
            $expiresIn = (int) $expiresIn;
        }

        if (! is_int($expiresIn) || $expiresIn < 0) {
            throw new UnexpectedValueException('The OAuth token response contains an invalid expiry duration.');
        }

        $now = Date::now();

        if ($now->getTimestamp() > PHP_INT_MAX - $expiresIn) {
            throw new UnexpectedValueException('The OAuth token response contains an invalid expiry duration.');
        }

        return $now->addSeconds($expiresIn);
    }
}
