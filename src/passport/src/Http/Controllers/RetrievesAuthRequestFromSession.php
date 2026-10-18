<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Exception;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\User;
use Hypervel\Passport\Exceptions\InvalidAuthTokenException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;

trait RetrievesAuthRequestFromSession
{
    /**
     * Get the authorization request from the session.
     *
     * @throws InvalidAuthTokenException
     * @throws Exception
     */
    protected function getAuthRequestFromSession(Request $request): AuthorizationRequestInterface
    {
        $pending = $this->getPendingAuthorization($request);

        try {
            if ($pending['grant_type'] !== 'authorization_code' || $pending['stage'] !== 'consent') {
                throw InvalidAuthTokenException::different();
            }

            $this->ensureApprovingUser($pending);
            // Resolve the current PSR request here to preserve this Laravel trait's method signature.
            $authRequest = $this->authorizations->resolve($pending, app(ServerRequestInterface::class));
            $this->getClientForApproval(
                $authRequest->getClient()->getIdentifier(),
                $authRequest->getGrantTypeId(),
                $authRequest->getScopes(),
            );
            $authRequest->setUser(new User($pending['user_id']));

            return $authRequest;
        } finally {
            $this->pending->pull($request, $request->input('auth_token'));
        }
    }
}
