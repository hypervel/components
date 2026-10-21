<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\Saloon\Fixtures\Requests\OAuth\CustomClientCredentialsAccessTokenRequest;

class CustomRequestClientCredentialsConnector extends ClientCredentialsConnector
{
    /**
     * Resolve the access token request.
     *
     * @param list<?string> $scopes
     */
    protected function resolveAccessTokenRequest(OAuthConfig $oauthConfig, array $scopes = [], string $scopeSeparator = ' '): Request
    {
        return new CustomClientCredentialsAccessTokenRequest($oauthConfig, $scopes, $scopeSeparator);
    }
}
