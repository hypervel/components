<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Mercure;

use Closure;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

class CachingTokenProvider implements TokenProviderInterface
{
    /**
     * The cached token.
     */
    protected ?string $jwt = null;

    /**
     * The context of the single cached token.
     */
    protected ?string $cacheKey = null;

    /**
     * The Unix timestamp after which the cached token must be re-minted.
     */
    protected int $refreshAfter = 0;

    /**
     * Create a new caching token provider instance.
     *
     * @param null|Closure(): string $cacheKeyResolver
     */
    public function __construct(
        protected TokenProviderInterface $provider,
        protected int $clockSkew = 30,
        protected ?Closure $cacheKeyResolver = null,
    ) {
    }

    /**
     * Get the JWT, minting a fresh one when the cached token nears expiry.
     */
    public function getJwt(): string
    {
        $cacheKey = $this->cacheKeyResolver === null ? null : ($this->cacheKeyResolver)();

        if ($this->jwt !== null && $cacheKey === $this->cacheKey && time() < $this->refreshAfter) {
            return $this->jwt;
        }

        $jwt = $this->provider->getJwt();
        $refreshAfter = ($this->expiresAt($jwt) ?? PHP_INT_MAX) - $this->clockSkew;

        // Publish a complete entry only after callbacks and signing have finished.
        $this->jwt = $jwt;
        $this->cacheKey = $cacheKey;
        $this->refreshAfter = $refreshAfter;

        return $jwt;
    }

    /**
     * Extract the "exp" claim from the given JWT, if any.
     */
    protected function expiresAt(string $jwt): ?int
    {
        $payload = explode('.', $jwt)[1] ?? '';

        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')) ?: 'null', true);

        return isset($claims['exp']) ? (int) $claims['exp'] : null;
    }
}
