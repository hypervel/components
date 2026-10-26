<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Http\Auth\TokenExpiry;
use Hypervel\Support\Facades\Date;
use SensitiveParameter;
use UnexpectedValueException;

final readonly class CachedToken
{
    public ?int $refreshAt;

    /**
     * Create a cache entry with a fixed renewal deadline.
     */
    public function __construct(#[SensitiveParameter] public OAuthAuthenticator $authenticator)
    {
        if ($authenticator->hasExpired()) {
            throw new UnexpectedValueException('The acquired OAuth token has already expired.');
        }

        $this->refreshAt = TokenExpiry::refreshAt($authenticator->getExpiresAt());
    }

    /**
     * Determine whether this entry can still be used.
     */
    public function isUsable(): bool
    {
        return $this->refreshAt === null || $this->refreshAt > Date::now()->getTimestamp();
    }
}
