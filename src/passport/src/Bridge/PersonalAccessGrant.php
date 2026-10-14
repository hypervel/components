<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use DateInterval;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\RequestAccessTokenEvent;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

class PersonalAccessGrant extends AbstractGrant
{
    /**
     * Respond to a personal access token request.
     *
     * {@inheritdoc}
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL
    ): ResponseTypeInterface {
        // Validate request
        if (! $userIdentifier = $this->getRequestParameter('user_id', $request)) {
            throw OAuthServerException::invalidRequest('user_id');
        }

        if (! $provider = $this->getRequestParameter('provider', $request)) {
            throw OAuthServerException::invalidRequest('provider');
        }

        /** @var ClientRepository $clients */
        $clients = $this->clientRepository;
        $client = $clients->getPersonalAccessClientEntity($provider);

        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));

        // Finalize the requested scopes
        $scopes = $this->scopeRepository->finalizeScopes(
            $scopes,
            $this->getIdentifier(),
            $client,
            $userIdentifier
        );

        // Issue and persist access token
        $issuance = TokenIssuance::current();
        $issuance->setAuthorizationAttributes(['provider' => $provider]);
        $issuance->tokenName = $this->getRequestParameter('name', $request);

        $accessToken = $this->issueAccessToken(
            $accessTokenTTL,
            $client,
            $userIdentifier,
            $scopes
        );

        // Send event to emitter
        $this->getEmitter()->emit(new RequestAccessTokenEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request, $accessToken));

        // Inject access token into response type
        $responseType->setAccessToken($accessToken);

        return $responseType;
    }

    /**
     * Get the grant identifier.
     *
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'personal_access';
    }
}
