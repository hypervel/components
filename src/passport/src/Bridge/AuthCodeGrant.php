<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use DateInterval;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant as LeagueAuthCodeGrant;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthCodeGrant extends LeagueAuthCodeGrant
{
    use AuthenticatesClients;

    /**
     * Validate the authorization request and require a hashed PKCE challenge.
     */
    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        if ($this->getQueryStringParameter('code_challenge', $request) !== null
            && $this->getQueryStringParameter('code_challenge_method', $request) !== 'S256') {
            throw OAuthServerException::invalidRequest('code_challenge_method', 'Code challenge method must be `S256`.');
        }

        return parent::validateAuthorizationRequest($request);
    }

    /**
     * Claim the validated authorization code before issuing its token.
     *
     * @param ScopeEntityInterface[] $scopes
     */
    protected function issueAccessToken(
        DateInterval $accessTokenTTL,
        ClientEntityInterface $client,
        ?string $userIdentifier,
        array $scopes = [],
    ): AccessTokenEntityInterface {
        /** @var TokenIssuance $issuance */
        $issuance = TokenIssuance::current();
        $issuance->claimGrant();

        return parent::issueAccessToken($accessTokenTTL, $client, $userIdentifier, $scopes);
    }
}
