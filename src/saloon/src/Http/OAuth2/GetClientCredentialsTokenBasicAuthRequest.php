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
        return [
            'grant_type' => 'client_credentials',
            'scope' => implode($this->scopeSeparator, [
                ...$this->oauthConfig->defaultScopes,
                ...$this->scopes,
            ]),
        ];
    }

    /**
     * Resolve the client-credentials authenticator.
     */
    protected function defaultAuth(): ?Authenticator
    {
        return new BasicAuthenticator(
            $this->oauthConfig->clientId,
            $this->oauthConfig->clientSecret,
        );
    }
}
