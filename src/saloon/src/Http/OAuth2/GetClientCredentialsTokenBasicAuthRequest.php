<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\OAuth2;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\Auth\BasicAuthenticator;

class GetClientCredentialsTokenBasicAuthRequest extends GetClientCredentialsTokenRequest
{
    /**
     * Resolve the client-credentials request body.
     *
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        $scopes = $this->oauthConfig->scopes($this->scopes);

        return [
            'grant_type' => 'client_credentials',
            ...($scopes === [] ? [] : ['scope' => implode($this->scopeSeparator, $scopes)]),
        ];
    }

    /**
     * Resolve the client-credentials authenticator.
     */
    protected function defaultAuth(): ?Authenticator
    {
        // RFC 6749 §2.3.1 requires form encoding each credential before HTTP Basic encoding.
        return new BasicAuthenticator(
            urlencode($this->oauthConfig->clientId),
            urlencode($this->oauthConfig->clientSecret),
        );
    }
}
