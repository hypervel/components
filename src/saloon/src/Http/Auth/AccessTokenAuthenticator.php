<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use DateTimeImmutable;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Support\Facades\Date;
use SensitiveParameter;

class AccessTokenAuthenticator implements OAuthAuthenticator
{
    /**
     * Create an access token authenticator.
     */
    public function __construct(
        #[SensitiveParameter]
        public readonly string $accessToken,
        #[SensitiveParameter]
        public readonly ?string $refreshToken = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->replaceHeaders(['Authorization' => 'Bearer ' . $this->getAccessToken()]);
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
    public function getExpiresAt(): ?DateTimeImmutable
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
