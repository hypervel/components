<?php

declare(strict_types=1);

namespace Hypervel\Mcp;

use Closure;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\OAuth\OAuthClient;
use Hypervel\Mcp\Client\OAuth\OAuthConfig;
use Hypervel\Mcp\Client\OAuth\OAuthRouteRegistrar;
use Hypervel\Mcp\Client\Transport\HttpTransport;
use Hypervel\Mcp\Schema\Implementation;
use SensitiveParameter;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class WebClient extends Client
{
    /**
     * Create an HTTP client with optional OAuth configuration.
     */
    public function __construct(
        protected HttpTransport $httpTransport,
        public ?Implementation $clientInfo = null,
        protected ?OAuthConfig $oAuthConfig = null,
    ) {
        parent::__construct($httpTransport, $clientInfo);
    }

    /**
     * Set the bearer token or its resolver.
     *
     * @param (Closure(): ?string)|string $token
     */
    public function withToken(#[SensitiveParameter] string|Closure $token): static
    {
        $this->httpTransport->withToken($token);

        return $this;
    }

    /**
     * Add custom HTTP headers.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->httpTransport->withHeaders($headers);

        return $this;
    }

    /**
     * Configure OAuth authorization for this client.
     */
    public function withOAuth(
        ?string $clientId = null,
        #[SensitiveParameter]
        ?string $clientSecret = null,
        ?string $scope = null,
        ?string $redirectUri = null,
    ): static {
        $this->oAuthConfig = new OAuthConfig(
            clientId: $clientId,
            clientSecret: $clientSecret,
            scope: $scope,
            redirectUri: $redirectUri,
        );

        return $this;
    }

    /**
     * Create the OAuth client for this resource and challenge.
     */
    public function oAuthClient(?string $resourceMetadataUrl = null, ?string $challengeScope = null): OAuthClient
    {
        if (! $this->oAuthConfig instanceof OAuthConfig) {
            throw new OAuthException('No OAuth configuration found. Call withOAuth() before oAuthClient().');
        }

        $config = $this->oAuthConfig;

        if ($this->name !== null && $config->redirectUri === null) {
            $config = clone $config;

            $config->redirectUri = $this->canonicalRouteUrl("mcp.oauth.{$this->name}.callback");
        }

        return new OAuthClient(
            $config,
            $this->httpTransport->url(),
            $resourceMetadataUrl,
            $challengeScope,
            clientIdMetadataUrl: $this->name === null
                ? null
                : $this->canonicalRouteUrl("mcp.oauth.{$this->name}.client-metadata"),
        );
    }

    /**
     * Get the canonical URL for a registered OAuth route.
     */
    protected function canonicalRouteUrl(string $name): ?string
    {
        try {
            return OAuthRouteRegistrar::url($name);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * Restore the HTTP transport and registered OAuth configuration.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->oAuthConfig = null;

        parent::__unserialize($data);

        if ($this->transport instanceof HttpTransport) {
            $this->httpTransport = $this->transport;
        }
    }

    /**
     * Restore OAuth settings from the same factory instance as the transport.
     */
    protected function restoreNamedClient(Client $client): void
    {
        parent::restoreNamedClient($client);

        $this->oAuthConfig = $client instanceof self ? $client->oAuthConfig : null;
    }
}
