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
        protected ResourceRepository $resources,
    ) {
    }

    /**
     * Validate a browser authorization and render early errors only to a trusted callback.
     */
    public function validate(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        try {
            return $this->validateAuthorizationRequest($request);
        } catch (OAuthServerException $exception) {
            throw new HttpResponseException($this->error($exception, $request->getQueryParams()), $exception);
        }
    }

    /**
     * Validate protocol input without selecting a browser error transport.
     *
     * Saved requests pass their frozen scope IDs; null applies the fresh-request default
     * intersection. Pass $pushed only for an authenticated PAR submission or a consumed
     * pushed request, since it satisfies the client's PAR requirement.
     *
     * @param null|list<string> $scopes
     * @throws OAuthServerException
     */
    public function validateAuthorizationRequest(ServerRequestInterface $request, ?array $scopes = null, bool $pushed = false): AuthorizationRequestInterface
    {
        if ($scopes !== null) {
            $request = $request->withQueryParams([...$request->getQueryParams(), 'scope' => implode(' ', $scopes)]);
        }

        $authorization = $this->servers->authorizationServer()->validateAuthorizationRequest($request);
        $parameters = $request->getQueryParams();
        $client = $this->clients->findActive($authorization->getClient()->getIdentifier());

        if ($client === null) {
            throw OAuthServerException::invalidClient($request);
        }

        if ($client->require_pushed_authorization_requests && ! $pushed) {
            throw OAuthServerException::invalidRequest('request_uri', 'This client requires a pushed authorization request.');
        }

        if (! in_array($parameters['response_mode'] ?? 'query', ['query', 'form_post'], true)) {
            throw OAuthServerException::invalidRequest('response_mode');
        }

        if (array_key_exists('resource', $parameters)) {
            $this->resources->validate($parameters['resource']);
        }

        $prompts = array_unique(array_filter(explode(' ', trim($parameters['prompt'] ?? ''))));

        if (in_array('none', $prompts, true) && count($prompts) > 1) {
            throw OAuthServerException::invalidRequest('prompt');
        }

        // Empty saved scopes must not pick up defaults added after the original request.
        if ($scopes === []) {
            $authorization->setScopes([]);
        }

        $useDefaults = $scopes === null && trim($parameters['scope'] ?? '') === '';
        $allowed = [];

        foreach ($authorization->getScopes() as $scope) {
            if (! $client->hasScope($scope->getIdentifier())) {
                if ($useDefaults) {
                    continue;
                }

                throw OAuthServerException::invalidScope($scope->getIdentifier());
            }

            $allowed[] = $scope;
        }

        $authorization->setScopes($allowed);

        return $authorization;
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
        $request = $request->withMethod('GET')->withQueryParams([
            ...$parameters,
            'redirect_uri' => $pending['redirect_uri'],
        ]);

        try {
            $authorization = $this->validateAuthorizationRequest($request, $pending['scopes'], $pending['pushed']);
        } catch (OAuthServerException $exception) {
            throw new HttpResponseException($this->error($exception, $request->getQueryParams()), $exception);
        }

        // Validate the saved destination, but preserve whether token exchange requires redirect_uri.
        $authorization->setRedirectUri(trim($parameters['redirect_uri'] ?? '') ?: null);

        return $authorization;
    }
}
