<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\OAuth2;

use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\OAuth2\GetClientCredentialsTokenRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;

/**
 * @phpstan-require-extends Connector
 * @phpstan-ignore trait.unused (user-facing OAuth 2 trait)
 */
trait ClientCredentialsGrant
{
    use CreatesOAuthAuthenticator;
    use HasOAuthConfig;

    /**
     * Request a client-credentials access token.
     *
     * @template TRequest of Request
     * @param list<string> $scopes
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
        $config->validate(false);
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
     * Resolve the client-credentials request.
     *
     * @param list<string> $scopes
     */
    protected function resolveAccessTokenRequest(
        OAuthConfig $oauthConfig,
        array $scopes = [],
        string $scopeSeparator = ' ',
    ): Request {
        return new GetClientCredentialsTokenRequest($oauthConfig, $scopes, $scopeSeparator);
    }
}
