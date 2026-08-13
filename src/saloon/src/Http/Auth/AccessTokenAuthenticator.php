<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Carbon\CarbonInterface;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Support\Facades\Date;
use SensitiveParameter;

readonly class AccessTokenAuthenticator implements OAuthAuthenticator
{
    /**
     * Create an access token authenticator.
     */
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        #[SensitiveParameter]
        public ?string $refreshToken = null,
        public ?CarbonInterface $expiresAt = null,
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->withHeader('Authorization', 'Bearer ' . $this->accessToken);
    }

    /**
     * Check if the access token has expired.
     */
    public function hasExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->getTimestamp() <= Date::now()->getTimestamp();
    }

    /**
     * Check if the access token has not expired.
     */
    public function hasNotExpired(): bool
    {
        return ! $this->hasExpired();
    }

    /**
     * Get the access token.
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Get the refresh token.
     */
    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * Get the expiry.
     */
    public function getExpiresAt(): ?CarbonInterface
    {
        return $this->expiresAt;
    }

    /**
     * Determine if the authenticator is refreshable.
     */
    public function isRefreshable(): bool
    {
        return isset($this->refreshToken);
    }

    /**
     * Determine if the authenticator is not refreshable.
     */
    public function isNotRefreshable(): bool
    {
        return ! $this->isRefreshable();
    }
}
