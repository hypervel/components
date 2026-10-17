<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth\Concerns;

use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\Destinations\ResolvedDestination;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\OAuth\OAuthDestinationPolicy;
use Hypervel\Support\Facades\Http;

trait InteractsWithOAuthEndpoints
{
    /**
     * Prepare a buffered OAuth request without following redirects.
     */
    protected function oAuthRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(5)
            ->connectTimeout(2)
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * Validate and pin an OAuth endpoint within the prepared request's limits.
     */
    protected function resolveOAuthDestination(PendingRequest $request, string $url): ResolvedDestination
    {
        try {
            return $request->resolveDestination(app(OAuthDestinationPolicy::class), $url);
        } catch (DisallowedDestinationException $exception) {
            throw new OAuthException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Determine whether the normalized host names a local development endpoint.
     */
    protected function isLocalhost(string $host): bool
    {
        return in_array($host, OAuthDestinationPolicy::LOCALHOSTS, true);
    }

    /**
     * Normalize the host consistently with destination validation.
     */
    protected function normalizedHost(string $host): string
    {
        return strtolower(rtrim(trim($host, '[]'), '.'));
    }
}
