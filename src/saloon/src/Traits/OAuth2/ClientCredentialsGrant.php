<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\OAuth2;

use DateTimeImmutable;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\OAuth2\GetClientCredentialsTokenRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
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
        $requestModifier?->__invoke($request);
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
        [$accessToken, $expiresAt] = $this->parseOAuthTokenResponse($response);

        return $this->createOAuthAuthenticator($accessToken, $expiresAt);
    }

    /**
     * Create an OAuth authenticator.
     */
    protected function createOAuthAuthenticator(
        #[SensitiveParameter]
        string $accessToken,
        ?DateTimeImmutable $expiresAt = null,
    ): OAuthAuthenticator {
        return new AccessTokenAuthenticator($accessToken, null, $expiresAt);
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
