<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Contracts;

use Carbon\CarbonInterface;

interface OAuthAuthenticator extends Authenticator
{
    /**
     * Get the access token.
     */
    public function getAccessToken(): string;

    /**
     * Get the refresh token.
     */
    public function getRefreshToken(): ?string;

    /**
     * Get the expiry.
     */
    public function getExpiresAt(): ?CarbonInterface;

    /**
     * Determine if the authenticator has expired.
     */
    public function hasExpired(): bool;

    /**
     * Determine if the authenticator has not expired.
     */
    public function hasNotExpired(): bool;

    /**
     * Determine if the authenticator is refreshable.
     */
    public function isRefreshable(): bool;

    /**
     * Determine if the authenticator is not refreshable.
     */
    public function isNotRefreshable(): bool;
}
