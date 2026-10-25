<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\OAuth2;

use DateTimeImmutable;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Auth\CachedTokenAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\OAuth2\GetClientCredentialsTokenRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\UrlResolver;
use SensitiveParameter;

/**
 * @phpstan-require-extends Connector
 * @phpstan-ignore trait.unused (user-facing OAuth 2 trait)
 */
trait ClientCredentialsGrant
{
    use HasOAuthConfig;
    use ParsesOAuthTokenResponses;

    /**
     * Cache client-credentials tokens for this grant.
     *
     * @param list<?string> $scopes
     * @param null|callable(Request): void $requestModifier
     */
    public function cachedAccessToken(
        array $scopes = [],
        string $scopeSeparator = ' ',
        ?string $store = null,
        ?callable $requestModifier = null,
        string $key = '',
    ): CachedTokenAuthenticator {
        $config = $this->oauthConfig();
        $config->validate(withRedirectUrl: false);
        $endpoint = UrlResolver::resolve(
            $this->resolveBaseUrl(),
            $config->tokenEndpoint,
            $config->allowBaseUrlOverride || $this->allowsBaseUrlOverride(),
        );

        return new CachedTokenAuthenticator(
            serialize([static::class, (string) $endpoint, $config->clientId, $config->clientSecret,
                implode($scopeSeparator, $config->scopes($scopes)), $key]),
            fn (): OAuthAuthenticator => $this->getAccessToken($scopes, $scopeSeparator, requestModifier: $requestModifier),
            $store,
        );
    }

    /**
     * Request a client-credentials access token.
     *
     * @template TRequest of Request
     * @param list<?string> $scopes
     * @param null|callable(TRequest): void $requestModifier
     * @return ($returnResponse is true ? Response : OAuthAuthenticator)
     */
    public function getAccessToken(
        array $scopes = [],
        string $scopeSeparator = ' ',
        bool $returnResponse = false,
        ?callable $requestModifier = null,
    ): OAuthAuthenticator|Response {
        $config = $this->oauthConfig();
        $config->validate(withRedirectUrl: false);
        $request = $config->modify($this->resolveAccessTokenRequest($config, $scopes, $scopeSeparator));
        if ($requestModifier !== null) {
            $requestModifier($request);
        }
        $response = $this->send($request);

        if ($returnResponse) {
            return $response;
        }

        $response->throw();

        return $this->createOAuthAuthenticatorFromResponse($response);
    }

    /**
     * Create an OAuth authenticator from a token response.
     */
    protected function createOAuthAuthenticatorFromResponse(#[SensitiveParameter] Response $response): OAuthAuthenticator
    {
        [$accessToken, $expiresAt, $data] = $this->parseOAuthTokenResponse($response);

        return $this->createOAuthAuthenticator($accessToken, $expiresAt, $this->resolveOAuthScopes($data['scope'] ?? null));
    }

    /**
     * Create an OAuth authenticator.
     *
     * @param null|list<string> $scopes
     */
    protected function createOAuthAuthenticator(
        #[SensitiveParameter]
        string $accessToken,
        ?DateTimeImmutable $expiresAt = null,
        ?array $scopes = null,
    ): OAuthAuthenticator {
        return new AccessTokenAuthenticator($accessToken, null, $expiresAt, $scopes);
    }

    /**
     * Resolve the client-credentials request.
     *
     * @param list<?string> $scopes
     */
    protected function resolveAccessTokenRequest(
        OAuthConfig $oauthConfig,
        array $scopes = [],
        string $scopeSeparator = ' ',
    ): Request {
        return new GetClientCredentialsTokenRequest($oauthConfig, $scopes, $scopeSeparator);
    }
}
