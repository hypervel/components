<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth\Concerns;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\DroppingStream;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\Destinations\ResolvedDestination;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\OAuth\OAuthDestinationPolicy;
use Hypervel\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

trait InteractsWithOAuthEndpoints
{
    protected int $maxOAuthResponseSize = 1_048_576;

    /**
     * Prepare a buffered OAuth request without following redirects.
     */
    protected function oAuthRequest(): PendingRequest
    {
        $maxResponseSize = $this->maxOAuthResponseSize;

        return Http::acceptJson()
            ->timeout(5)
            ->connectTimeout(2)
            ->withOptions(['allow_redirects' => false])
            ->withMiddleware(static function (callable $handler) use ($maxResponseSize): Closure {
                return static function (RequestInterface $request, array $options) use ($handler, $maxResponseSize): PromiseInterface {
                    // A fresh buffer per hop prevents redirected responses from overwriting earlier bodies.
                    // Explicit caller sinks retain their own storage and limit policy.
                    $options['sink'] ??= new DroppingStream(Utils::streamFor(''), $maxResponseSize);

                    return $handler($request, $options);
                };
            });
    }

    // oAuthTransportOptions() is intentionally omitted: Guzzle already restricts schemes to HTTP/HTTPS,
    // and the capped sink aborts oversized bodies including those without a declared length.

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
