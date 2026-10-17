<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationRequestResolver
{
    /**
     * Create the authorization request resolver.
     */
    public function __construct(
        protected ServerFactory $servers,
        protected ClientRepository $clients,
        protected AuthorizationResponse $responses,
    ) {
    }

    /**
     * Validate an authorization and render early errors only to a trusted callback.
     */
    public function validate(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        try {
            return $this->servers->authorizationServer()->validateAuthorizationRequest($request);
        } catch (OAuthServerException $exception) {
            throw new HttpResponseException($this->error($exception, $request->getQueryParams()), $exception);
        }
    }

    /**
     * Recover a safe error destination when League rejects before returning an authorization.
     *
     * @param array<string, list<string>|string> $parameters
     */
    public function error(OAuthServerException $exception, array $parameters): Response
    {
        $redirectUri = null;

        if ($exception->getErrorType() !== 'invalid_client'
            && ($client = $this->clients->findActive(trim($parameters['client_id'] ?? '')))) {
            $candidate = trim($parameters['redirect_uri'] ?? '');

            if ($candidate === '' && count($client->redirect_uris) === 1) {
                $candidate = $client->redirect_uris[0];
            }

            // Reuse League's loopback-port rules without invoking grant validation or emitting its events twice.
            if ($candidate !== '' && (new RedirectUriValidator($client->redirect_uris))->validateRedirectUri($candidate)) {
                $redirectUri = $candidate;
            }
        }

        return $this->responses->error(
            $exception,
            $redirectUri,
            $parameters['state'] ?? null,
            ($parameters['response_mode'] ?? null) === 'form_post' ? 'form_post' : 'query',
        );
    }

    /**
     * Revalidate a saved presentation using the current client and server configuration.
     *
     * @param array<string, mixed> $pending
     */
    public function resolve(array $pending, ServerRequestInterface $request): AuthorizationRequestInterface
    {
        $parameters = $pending['parameters'];
        $authorization = $this->validate(
            $request->withMethod('GET')->withQueryParams([
                ...$parameters,
                'redirect_uri' => $pending['redirect_uri'],
                'scope' => implode(' ', $pending['scopes']),
            ]),
        );

        // Validate the saved destination, but preserve whether token exchange requires redirect_uri.
        $authorization->setRedirectUri(trim($parameters['redirect_uri'] ?? '') ?: null);

        if ($pending['scopes'] === []) {
            // League applies current defaults to an empty scope parameter; an old presentation must not widen.
            $authorization->setScopes([]);
        }

        return $authorization;
    }
}
