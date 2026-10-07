<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\OAuth2;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Response;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use SensitiveParameter;
use UnexpectedValueException;

/**
 * @phpstan-require-extends Connector
 * @phpstan-ignore trait.unused (user-facing OAuth 2 trait)
 */
trait ParsesOAuthTokenResponses
{
    /**
     * Parse the access token, expiry and data of a token response.
     *
     * @return array{string, ?CarbonImmutable, array<array-key, mixed>}
     */
    protected function parseOAuthTokenResponse(#[SensitiveParameter] Response $response): array
    {
        $data = $response->json();
        $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;

        if (! is_array($data) || ! is_string($accessToken) || $accessToken === '') {
            throw new UnexpectedValueException('The OAuth token response does not contain a valid access token.');
        }

        return [$accessToken, $this->resolveOAuthExpiry($data['expires_in'] ?? null), $data];
    }

    /**
     * Resolve an OAuth token expiry.
     */
    protected function resolveOAuthExpiry(mixed $expiresIn): ?CarbonImmutable
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

        // Date may be configured to create mutable dates, so copy the clock into an immutable expiry.
        $now = CarbonImmutable::instance(Date::now());

        if ($now->getTimestamp() > PHP_INT_MAX - $expiresIn) {
            throw new UnexpectedValueException('The OAuth token response contains an invalid expiry duration.');
        }

        return $now->addSeconds($expiresIn);
    }
}
