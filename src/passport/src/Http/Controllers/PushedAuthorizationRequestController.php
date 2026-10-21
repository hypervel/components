<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passport\AuthorizationRequestResolver;
use Hypervel\Passport\ClientAuthenticator;
use Hypervel\Passport\DpopProofValidator;
use Hypervel\Passport\Events\AuthorizationRequestValidated;
use Hypervel\Passport\Exceptions\OAuthServerException;
use Hypervel\Passport\Http\RequestParameters;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use Hypervel\Passport\PushedAuthorizationRequestRepository;
use Hypervel\Support\Arr;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class PushedAuthorizationRequestController
{
    /**
     * Create the pushed authorization endpoint.
     */
    public function __construct(
        protected AuthManager $auth,
        protected Repository $config,
        protected ClientAuthenticator $authenticator,
        protected AuthorizationRequestResolver $authorizations,
        protected PushedAuthorizationRequestRepository $pushed,
        protected AuthorizationResponse $responses,
        protected Dispatcher $events,
        protected DpopProofValidator $proofs,
    ) {
    }

    /**
     * Validate and retain authorization parameters for one browser request.
     */
    public function store(Request $request, ServerRequestInterface $psrRequest): Response
    {
        try {
            if (strlen($request->getContent()) > RequestParameters::MAX_ENCODED_LENGTH) {
                throw new LeagueException('The pushed authorization request is too large.', 3, 'invalid_request', 413);
            }

            $parameters = RequestParameters::fromForm($request);
            $client = $this->authenticator->authenticate($psrRequest->withParsedBody($parameters));

            if (! $client->supportsProvider($this->auth->getUserProviderName($this->config->get('passport.guard')))) {
                throw LeagueException::invalidClient($psrRequest);
            }

            if (array_key_exists('request_uri', $parameters)) {
                throw LeagueException::invalidRequest('request_uri');
            }

            $parameters = [
                ...Arr::except($parameters, ['client_secret', 'client_assertion', 'client_assertion_type']),
                'client_id' => $client->getKey(),
            ];
            $authorization = $this->authorizations->validateAuthorizationRequest($psrRequest->withQueryParams($parameters), pushed: true);

            if ($psrRequest->hasHeader('DPoP')) {
                $parameters['dpop_jkt'] = $this->proofs->validate($psrRequest, thumbprint: $parameters['dpop_jkt'] ?? null);
            }

            if ($this->events->hasListeners(AuthorizationRequestValidated::class)) {
                $this->events->dispatch(new AuthorizationRequestValidated($request, $parameters, $authorization));
            }

            return new JsonResponse([
                'request_uri' => $this->pushed->store($parameters, $authorization),
                'expires_in' => $this->config->integer('passport.par.lifetime'),
            ], 201, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (LeagueException|OAuthServerException $exception) {
            /** @var LeagueException $error */
            $error = $exception instanceof OAuthServerException ? $exception->getPrevious() : $exception;

            // League can attach a redirect to scope errors, but PAR responses never use browser transport.
            return $this->responses->error($error, null, null, 'query');
        }
    }
}
