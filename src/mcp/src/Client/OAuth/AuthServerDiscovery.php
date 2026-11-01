<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth;

use Closure;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Client\Response;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\OAuth\Concerns\InteractsWithOAuthEndpoints;
use Hypervel\Support\Arr;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class AuthServerDiscovery
{
    use InteractsWithOAuthEndpoints;

    /**
     * Discover and validate the resource's authorization server.
     */
    public function discover(string $resourceUrl, ?string $resourceMetadataUrl = null): DiscoveryResult
    {
        $metadataUrl = $resourceMetadataUrl ?? $this->wellKnown($resourceUrl, 'oauth-protected-resource');

        $this->requireFetchable($metadataUrl, $resourceUrl);

        $resourceMetadata = $this->fetchResourceMetadata($metadataUrl, explicit: $resourceMetadataUrl !== null);
        $resource = $resourceUrl;

        if ($resourceMetadataUrl === null && $resourceMetadata === []) {
            $origin = $this->origin($resourceUrl);
            $rootMetadataUrl = $this->wellKnown($origin, 'oauth-protected-resource');

            if ($rootMetadataUrl !== $metadataUrl) {
                $resourceMetadata = $this->fetchResourceMetadata($rootMetadataUrl);

                if ($resourceMetadata !== []) {
                    // Root discovery identifies the origin, while explicit metadata identifies the requested URL.
                    $resource = $origin;
                }
            }
        }

        $this->requireResourceMatches($resourceMetadata, $resource);
        $resource = is_string($resourceMetadata['resource'] ?? null) ? $resourceMetadata['resource'] : $resource;

        $issuer = $this->issuerFrom($resourceMetadata) ?? $this->origin($resourceUrl);

        $this->requireSecure($issuer);
        $this->requireNotInternal($issuer, $resourceUrl);

        $serverMetadata = $this->fetchMetadata($issuer);

        if (! hash_equals($issuer, $serverMetadata->issuer)) {
            throw new OAuthException("Authorization server issuer [{$serverMetadata->issuer}] did not match the expected issuer [{$issuer}].");
        }

        $this->requireSecure($serverMetadata->authorizationEndpoint);
        $this->requireSecure($serverMetadata->tokenEndpoint);
        $this->requireNotInternal($serverMetadata->authorizationEndpoint, $resourceUrl);
        $this->requireNotInternal($serverMetadata->tokenEndpoint, $resourceUrl);

        if ($serverMetadata->registrationEndpoint !== null) {
            $this->requireSecure($serverMetadata->registrationEndpoint);
            $this->requireNotInternal($serverMetadata->registrationEndpoint, $resourceUrl);
        }

        $scopesSupported = array_values(array_map(strval(...), (array) ($resourceMetadata['scopes_supported'] ?? [])));

        return new DiscoveryResult($serverMetadata, $scopesSupported, $resource);
    }

    /**
     * Require caller-supplied metadata to be advertised by the resource.
     *
     * @param array<string, string> $headers
     */
    public function requireAdvertised(string $resourceUrl, string $resourceMetadataUrl, array $headers = []): void
    {
        $this->requireFetchable($resourceMetadataUrl, $resourceUrl);

        if ($resourceMetadataUrl === $this->wellKnown($resourceUrl, 'oauth-protected-resource')) {
            return;
        }

        $this->requireFetchable($resourceUrl, $resourceUrl);

        $response = $this->probe($resourceUrl, array_filter(
            $headers,
            static fn (string $name): bool => strcasecmp($name, 'Authorization') !== 0 && ! str_starts_with(strtolower($name), 'mcp-'),
            ARRAY_FILTER_USE_KEY,
        ));

        $advertised = WwwAuthenticateChallenge::parse($response->header('WWW-Authenticate'))->resourceMetadataUrl;

        if (! in_array($response->status(), [401, 403], true) || $advertised === null || ! hash_equals($advertised, $resourceMetadataUrl)) {
            throw new OAuthException("The protected resource metadata URL [{$resourceMetadataUrl}] was not advertised by the resource [{$resourceUrl}].");
        }
    }

    /**
     * Probe the resource for its unauthenticated authorization challenge.
     *
     * @param array<string, string> $headers
     */
    protected function probe(string $resourceUrl, array $headers): Response
    {
        try {
            return $this->oAuthRequest()
                ->withHeaders($headers)
                ->withMiddleware(function (callable $handler): Closure {
                    return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                        // Resolve every hop through the same boundary as other OAuth requests, which
                        // preserves ordinary proxies that the generic destination-policy handler rejects.
                        $transportOptions = ['curl', 'proxy', 'timeout', 'connect_timeout'];
                        $hop = new PendingRequest(options: Arr::only($options, $transportOptions));
                        $destination = $this->resolveOAuthDestination($hop, (string) $request->getUri());

                        return $handler($request->withUri($destination->uri, true), array_replace(
                            $options,
                            Arr::only($hop->getOptions(), $transportOptions),
                        ));
                    };
                })
                ->withOptions(['allow_redirects' => [
                    'max' => 5,
                    'strict' => true,
                    'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($resourceUrl): void {
                        $this->requireFetchable((string) $uri, $resourceUrl);
                    },
                ]])
                ->accept('application/json, text/event-stream')
                ->post($resourceUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'ping',
                ]);
        } catch (ConnectionException|RequestException|TransferException|MalformedUriException $exception) {
            throw new OAuthException("Unable to reach the resource [{$resourceUrl}] to verify its protected resource metadata.", $exception->getCode(), previous: $exception);
        }
    }

    /**
     * Fetch optional or explicitly requested protected resource metadata.
     *
     * @return array<string, mixed>
     */
    protected function fetchResourceMetadata(string $metadataUrl, bool $explicit = false): array
    {
        $request = $this->oAuthRequest();
        $destination = $this->resolveOAuthDestination($request, $metadataUrl);
        $response = $request->get((string) $destination->uri);

        if (! $response->successful()) {
            if ($explicit) {
                throw new OAuthException("Protected resource metadata request to [{$metadataUrl}] failed with status [{$response->status()}].");
            }

            return [];
        }

        $data = $response->json();

        if (is_array($data)) {
            return $data;
        }

        if ($explicit) {
            throw new OAuthException("Protected resource metadata at [{$metadataUrl}] did not return a valid JSON object.");
        }

        return [];
    }

    /**
     * Verify that metadata belongs to the requested resource.
     *
     * @param array<string, mixed> $resourceMetadata
     */
    protected function requireResourceMatches(array $resourceMetadata, string $resourceUrl): void
    {
        $resource = Arr::get($resourceMetadata, 'resource');

        if (is_string($resource) && ! hash_equals($resourceUrl, $resource)) {
            // URL serializers disagree on the root slash; every other part must still match exactly.
            $withoutRootSlash = static fn (string $url): string => preg_replace('~\A(https?://[^/?#]+)/(?=[?#]|\z)~', '$1', $url);

            if (hash_equals($withoutRootSlash($resourceUrl), $withoutRootSlash($resource))) {
                return;
            }

            throw new OAuthException("Protected resource metadata resource [{$resource}] did not match the expected resource [{$resourceUrl}].");
        }
    }

    /**
     * Get the resource's first advertised authorization server.
     *
     * @param array<string, mixed> $resourceMetadata
     */
    protected function issuerFrom(array $resourceMetadata): ?string
    {
        $servers = Arr::get($resourceMetadata, 'authorization_servers');

        if (is_array($servers) && $servers !== []) {
            return (string) $servers[0];
        }

        return null;
    }

    /**
     * Fetch authorization metadata from the issuer's supported discovery paths.
     */
    protected function fetchMetadata(string $issuer): AuthServerMetadata
    {
        foreach ($this->metadataUrls($issuer) as $metadataUrl) {
            $request = $this->oAuthRequest();
            $destination = $this->resolveOAuthDestination($request, $metadataUrl);
            $response = $request->get((string) $destination->uri);

            if (! $response->successful()) {
                continue;
            }

            $metadata = $response->json();

            if (is_array($metadata)) {
                return AuthServerMetadata::fromArray($metadata);
            }
        }

        throw new OAuthException("Unable to discover authorization server metadata from [{$issuer}].");
    }

    /**
     * Get the OAuth and OpenID discovery URLs for an issuer.
     *
     * @return array<int, string>
     */
    protected function metadataUrls(string $issuer): array
    {
        $parts = $this->parse($issuer);

        $origin = $this->originFromParts($parts);

        $path = rtrim($parts['path'] ?? '', '/');

        if ($path === '') {
            return [
                $origin . '/.well-known/oauth-authorization-server',
                $origin . '/.well-known/openid-configuration',
            ];
        }

        return [
            $origin . '/.well-known/oauth-authorization-server' . $path,
            $origin . '/.well-known/openid-configuration' . $path,
            $origin . $path . '/.well-known/openid-configuration',
        ];
    }

    /**
     * Build a well-known metadata URL for the resource path and query.
     */
    protected function wellKnown(string $url, string $type): string
    {
        $parts = $this->parse($url);

        $path = $parts['path'] ?? '';

        if ($path === '/') {
            $path = '';
        }

        return $this->originFromParts($parts) . '/.well-known/' . $type . $path
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Get the URL's origin.
     */
    protected function origin(string $url): string
    {
        return $this->originFromParts($this->parse($url));
    }

    /**
     * Require HTTPS outside local development endpoints.
     */
    protected function requireSecure(string $url): void
    {
        $parts = $this->parse($url);

        if ($parts['scheme'] === 'https') {
            return;
        }

        if ($this->isLocalhost($this->normalizedHost($parts['host']))) {
            return;
        }

        throw new OAuthException("OAuth endpoint [{$url}] must be served over HTTPS.");
    }

    /**
     * Validate a metadata endpoint before fetching it.
     */
    protected function requireFetchable(string $url, string $resourceUrl): void
    {
        $this->requireSecure($url);
        $this->requireNotInternal($url, $resourceUrl);
    }

    /**
     * Prevent remote resources from directing authorization to local endpoints.
     */
    protected function requireNotInternal(string $url, string $resourceUrl): void
    {
        $parts = $this->parse($url);
        $resourceParts = $this->parse($resourceUrl);
        $host = $this->normalizedHost($parts['host']);
        $resourceHost = $this->normalizedHost($resourceParts['host']);

        if (! $this->isInternalHost($host)) {
            return;
        }

        if ($this->isLocalhost($host)) {
            if (! $this->isLocalhost($resourceHost)) {
                throw new OAuthException("OAuth endpoint [{$url}] cannot use a private or internal host.");
            }

            return;
        }

        try {
            app(OAuthDestinationPolicy::class)->validate($url);
        } catch (DisallowedDestinationException $exception) {
            throw new OAuthException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Determine whether a host is local or a non-public address literal.
     */
    protected function isInternalHost(string $host): bool
    {
        if ($this->isLocalhost($host)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Build an origin from parsed URL components.
     *
     * @param array{scheme: string, host: string, port?: int, path?: string} $parts
     */
    protected function originFromParts(array $parts): string
    {
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Parse the URL components required for discovery.
     *
     * @return array{scheme: string, host: string, port?: int, path?: string, query?: string}
     */
    protected function parse(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new OAuthException("Unable to parse URL [{$url}] during OAuth discovery.");
        }

        /** @var array{scheme: string, host: string, port?: int, path?: string, query?: string} $parts */
        return $parts;
    }
}
