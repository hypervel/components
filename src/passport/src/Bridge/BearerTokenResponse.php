<?php

/**
 * @copyright Copyright (c) Alex Bilbie
 * @license http://mit-license.org/
 *
 * @link https://github.com/thephpleague/oauth2-server
 */

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
use LogicException;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

class BearerTokenResponse extends LeagueBearerTokenResponse implements Transient
{
    /**
     * Generate the token response with independent refresh-token authorization.
     */
    public function generateHttpResponse(ResponseInterface $response): ResponseInterface
    {
        $expireDateTime = $this->accessToken->getExpiryDateTime()->getTimestamp();
        $responseParams = [
            'token_type' => 'Bearer',
            'expires_in' => $expireDateTime - time(),
            'access_token' => $this->accessToken->toString(),
        ];

        if (isset($this->refreshToken)) {
            $refreshTokenPayload = json_encode([
                'client_id' => $this->accessToken->getClient()->getIdentifier(),
                'refresh_token_id' => $this->refreshToken->getIdentifier(),
                'access_token_id' => $this->accessToken->getIdentifier(),
                'scopes' => TokenIssuance::current()->refreshTokenScopes ?? $this->accessToken->getScopes(),
                'user_id' => $this->accessToken->getUserIdentifier(),
                'expire_time' => $this->refreshToken->getExpiryDateTime()->getTimestamp(),
            ]);

            if ($refreshTokenPayload === false) {
                throw new LogicException('Error encountered JSON encoding the refresh token payload');
            }

            $responseParams['refresh_token'] = $this->encrypt($refreshTokenPayload);
        }

        $responseParams = json_encode(array_merge($this->getExtraParams($this->accessToken), $responseParams));

        if ($responseParams === false) {
            throw new LogicException('Error encountered JSON encoding response parameters');
        }

        $response = $response
            ->withStatus(200)
            ->withHeader('pragma', 'no-cache')
            ->withHeader('cache-control', 'no-store')
            ->withHeader('content-type', 'application/json; charset=UTF-8');

        $response->getBody()->write($responseParams);

        return $response;
    }

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
