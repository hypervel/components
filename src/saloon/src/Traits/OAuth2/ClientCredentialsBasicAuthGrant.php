<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\OAuth2;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\OAuth2\GetClientCredentialsTokenBasicAuthRequest;
use Hypervel\Saloon\Http\Request;

/**
 * @phpstan-require-extends Connector
 * @phpstan-ignore trait.unused (user-facing OAuth 2 trait)
 */
trait ClientCredentialsBasicAuthGrant
{
    use ClientCredentialsGrant;

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
        return new GetClientCredentialsTokenBasicAuthRequest($oauthConfig, $scopes, $scopeSeparator);
    }
}
