<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\DeviceCode;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\Token;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse as LeagueBearerTokenResponse;
use SensitiveParameter;

class BearerTokenResponse extends LeagueBearerTokenResponse implements Transient
{
    /**
     * Report the final scopes, including a user's partial approval.
     *
     * {@inheritdoc}
     */
    protected function getExtraParams(#[SensitiveParameter] AccessTokenEntityInterface $accessToken): array
    {
        return [
            'scope' => implode(' ', array_map(
                static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
                $accessToken->getScopes(),
            )),
        ];
    }

    /**
     * Get the access token saved by the current issuance without another query.
     */
    protected function getToken(): ?Token
    {
        return TokenIssuance::current()?->accessToken;
    }

    /**
     * Get the credential redeemed by the current issuance, if any.
     */
    protected function getGrant(): AuthCode|DeviceCode|RefreshToken|null
    {
        return TokenIssuance::current()?->grant;
    }

    /**
     * Get the signing key pinned to the current issuance.
     */
    protected function getSigningKey(): ?SigningCryptKey
    {
        return TokenIssuance::current()?->signingKey;
    }
}
