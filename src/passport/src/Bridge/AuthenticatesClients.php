<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Passport\ClientAuthenticator;
use Hypervel\Passport\DpopProofValidator;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestEvent;
use Psr\Http\Message\ServerRequestInterface;

trait AuthenticatesClients
{
    /**
     * Authenticate the client before checking its permitted grants.
     */
    protected function validateClient(ServerRequestInterface $request): ClientEntityInterface
    {
        try {
            $client = app(ClientAuthenticator::class)->authenticate($request);
        } catch (OAuthServerException $exception) {
            if ($exception->getErrorType() === 'invalid_client') {
                $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));
            }

            throw $exception;
        }

        $entity = $this->getClientEntityOrFail($client->getKey(), $request);
        $issuance = TokenIssuance::current();

        if ($issuance?->isTokenRequest() && ($request->hasHeader('DPoP') || $client->dpop_bound_access_tokens)) {
            $issuance->dpopJkt = app(DpopProofValidator::class)->validate($request);
        }

        return $entity;
    }
}
