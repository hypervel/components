<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Exception;
use Hypervel\Http\Request;
use Hypervel\Passport\Exceptions\InvalidAuthTokenException;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

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
        if ($request->isNotFilled('auth_token')
            || $request->session()->pull('authToken') !== $request->input('auth_token')) {
            $request->session()->forget(['authToken', 'authRequest', 'authAttributes']);

            throw InvalidAuthTokenException::different();
        }

        $authRequest = $request->session()->pull('authRequest')
            ?? throw new Exception('Authorization request was not present in the session.');

        $authRequest = unserialize($authRequest, ['allowed_classes' => [
            \League\OAuth2\Server\RequestTypes\AuthorizationRequest::class,
            \Hypervel\Passport\Bridge\Client::class,
            \Hypervel\Passport\Bridge\Scope::class,
            \Hypervel\Passport\Bridge\User::class,
        ]]);

        $this->ensureApprovingUser($request, $authRequest->getUser()?->getIdentifier());
        $client = $this->getClientForApproval(
            $authRequest->getClient()->getIdentifier(),
            $authRequest->getGrantTypeId(),
            $authRequest->getScopes()
        );
        $redirectUri = $authRequest->getRedirectUri();

        if ($redirectUri === null) {
            $originalUris = $authRequest->getClient()->getRedirectUri();
            $redirectUri = is_array($originalUris) ? $originalUris[0] : $originalUris;
        }

        if (! (new RedirectUriValidator($client->redirect_uris))->validateRedirectUri($redirectUri)) {
            throw OAuthServerException::invalidRequest('redirect_uri');
        }

        return $authRequest;
    }
}
