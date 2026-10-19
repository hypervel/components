<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\PasswordConfirmation;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Http\Request;
use Hypervel\Passport\AuthorizationFinalizer;
use Hypervel\Passport\AuthorizationRequestResolver;
use Hypervel\Passport\Bridge\User;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\ConsentRepository;
use Hypervel\Passport\Contracts\AccountSelectionViewResponse;
use Hypervel\Passport\Contracts\AuthorizationViewResponse;
use Hypervel\Passport\Contracts\ReauthenticationResponse;
use Hypervel\Passport\Enums\AuthorizationApproval;
use Hypervel\Passport\Events\AuthorizationRequestValidated;
use Hypervel\Passport\Exceptions\AuthenticationException;
use Hypervel\Passport\Exceptions\InvalidAuthTokenException;
use Hypervel\Passport\Exceptions\OAuthServerException;
use Hypervel\Passport\Http\Middleware\SetAuthorizationResponseHeaders;
use Hypervel\Passport\Http\RequestParameters;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PendingAuthorizationRepository;
use Hypervel\Passport\PushedAuthorizationRequest;
use Hypervel\Passport\PushedAuthorizationRequestRepository;
use Hypervel\Passport\Scope;
use Hypervel\Passport\ServerFactory;
use Hypervel\Routing\Attributes\Controllers\Middleware;
use Hypervel\Support\Facades\Date;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

#[Middleware(SetAuthorizationResponseHeaders::class)]
class AuthorizationController
{
    use ConvertsPsrResponses;
    use HandlesOAuthErrors;
    use ResolvesApprovingGuard;

    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected ServerFactory $servers,
        protected AuthManager $auth,
        protected ClientRepository $clients,
        protected Repository $config,
        protected AuthorizationRequestResolver $authorizations,
        protected PendingAuthorizationRepository $pending,
        protected ConsentRepository $consents,
        protected AuthorizationFinalizer $finalizer,
        protected AuthorizationResponse $responses,
        protected Dispatcher $events,
        protected PushedAuthorizationRequestRepository $pushed,
    ) {
    }

    /**
     * Turn a cross-site protocol form into a top-level GET without touching its session.
     */
    public function store(Request $request): Response
    {
        return $this->withErrorHandling(function () use ($request): Response {
            $parameters = RequestParameters::fromForm($request);

            return redirect()->to(route('passport.authorizations.authorize') . '?' . RequestParameters::toQuery($parameters), 303);
        });
    }

    /**
     * Authorize a client to access the user's account.
     */
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse
    ): Response|AuthorizationViewResponse {
        $parameters = $this->withErrorHandling(fn (): array => RequestParameters::fromRequest($request));
        $pushed = null;

        if (isset($parameters['request_uri']) && $this->pushed->isPushedRequestUri($parameters['request_uri'])) {
            $pushed = $this->withErrorHandling(function () use ($parameters): PushedAuthorizationRequest {
                if (! isset($parameters['client_id']) || $parameters['client_id'] === '') {
                    throw LeagueException::invalidRequest('client_id');
                }

                return $this->pushed->consume($parameters['request_uri'], $parameters['client_id']);
            });
            $parameters = $pushed->parameters;
        }

        $authRequest = $pushed === null
            ? $this->authorizations->validate($psrRequest->withQueryParams($parameters))
            : $this->authorizations->resolve([
                ...$pushed->only(['parameters', 'scopes', 'redirect_uri']), 'pushed' => true,
            ], $psrRequest);
        $redirects = $authRequest->getClient()->getRedirectUri();
        $pending = [
            'parameters' => $parameters,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $pushed->redirect_uri ?? $authRequest->getRedirectUri() ?? (is_array($redirects) ? $redirects[0] : $redirects),
            'scopes' => array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $authRequest->getScopes()),
            'guard' => $this->getApprovingGuardName(),
            'response_mode' => ($parameters['response_mode'] ?? null) === 'form_post' ? 'form_post' : 'query',
            'pushed' => $pushed !== null,
        ];

        return $this->withAuthorizationErrorHandling(
            fn () => $this->respondToAuthorization($request, $psrRequest, $authRequest, $psrResponse, $viewResponse, $pending),
            $pending,
            $this->responses,
        );
    }

    /**
     * Resume a browser interaction without accepting replacement protocol parameters.
     */
    public function resume(
        string $authorization,
        Request $request,
        ServerRequestInterface $psrRequest,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse,
    ): Response|AuthorizationViewResponse {
        $pending = $this->pending->get($request, $authorization);

        if ($pending['grant_type'] !== 'authorization_code') {
            throw InvalidAuthTokenException::different();
        }

        return $this->withAuthorizationErrorHandling(
            function () use ($request, $psrRequest, $psrResponse, $viewResponse, $pending, $authorization) {
                try {
                    $authRequest = $this->authorizations->resolve($pending, $psrRequest);

                    return $this->respondToAuthorization($request, $psrRequest, $authRequest, $psrResponse, $viewResponse, $pending, $authorization);
                } catch (LeagueException|HttpResponseException $exception) {
                    if ($exception instanceof LeagueException || $exception->getPrevious() instanceof LeagueException) {
                        // A terminal protocol error must also prevent an older consent form from completing.
                        $this->pending->pull($request, $authorization);
                    }

                    throw $exception;
                }
            },
            $pending,
            $this->responses,
        );
    }

    /**
     * Continue a pending authorization as the explicitly selected account.
     */
    public function continueAsCurrentAccount(Request $request): Response
    {
        $pending = $this->getPendingAuthorization($request);

        if ($pending['grant_type'] !== 'authorization_code' || $pending['stage'] !== 'account') {
            throw InvalidAuthTokenException::different();
        }

        $this->ensureApprovingUser($pending);
        $authorization = $request->input('auth_token');
        $this->pending->update($request, $authorization, ['account_selected' => true, 'stage' => 'resume']);

        return to_route('passport.authorizations.resume', ['authorization' => $authorization]);
    }

    /**
     * Sign out for an explicit account switch while preserving only this authorization.
     */
    public function switchAccount(Request $request): Response
    {
        $pending = $this->getPendingAuthorization($request);

        if ($pending['grant_type'] !== 'authorization_code' || $pending['stage'] !== 'account') {
            throw InvalidAuthTokenException::different();
        }

        $this->ensureApprovingUser($pending);
        $this->getApprovingGuard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        unset(
            $pending['user_id'], $pending['provider'], $pending['login_confirmed'], $pending['confirmation_started_at'],
            $pending['permissions'], $pending['attributes'], $pending['remember_consent_for'],
        );
        $pending['stage'] = 'login';
        $authorization = $this->pending->store($request, $pending);

        return to_route('passport.authorizations.resume', ['authorization' => $authorization]);
    }

    /**
     * Resolve login, account choice and consent for a validated authorization.
     *
     * @param array<string, mixed> $pending
     */
    protected function respondToAuthorization(
        Request $request,
        ServerRequestInterface $psrRequest,
        AuthorizationRequestInterface $authRequest,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse,
        array $pending,
        ?string $authorization = null,
    ): Response|AuthorizationViewResponse {
        $parameters = $pending['parameters'];
        $prompts = array_values(array_unique(array_filter(explode(' ', trim($parameters['prompt'] ?? '')))));
        $silent = in_array('none', $prompts, true);

        $guard = $this->getApprovingGuard();

        if ($pending['guard'] !== $this->getApprovingGuardName()) {
            throw InvalidAuthTokenException::different();
        }

        $client = $this->clients->findActive($authRequest->getClient()->getIdentifier());

        if (! $client || ! $client->supportsProvider($this->getApprovingProvider())) {
            throw LeagueException::invalidClient($psrRequest);
        }

        if ($this->events->hasListeners(AuthorizationRequestValidated::class)) {
            $event = new AuthorizationRequestValidated($request, $parameters, $authRequest);
            $this->events->dispatch($event);
            $prompts = array_unique([...$prompts, ...$event->prompts]);
        }

        if (in_array('select_account', $prompts, true) && ! ($pending['account_selected'] ?? false)
            && ($silent || ! app()->bound(AccountSelectionViewResponse::class))) {
            throw new LeagueException('The authorization server requires account selection.', 9, 'account_selection_required', 400);
        }

        if ($guard->guest()) {
            if ($silent) {
                throw OAuthServerException::loginRequired($authRequest);
            }

            if ($authorization === null) {
                $authorization = $this->saveAuthorization($request, null, [...$pending, 'stage' => 'login']);

                return to_route('passport.authorizations.resume', ['authorization' => $authorization]);
            }

            // The normal guest redirect saves this resume URL as the intended destination.
            $this->promptForLogin($request);
        }

        $user = $guard->user();

        if (isset($pending['user_id']) && ($pending['user_id'] !== (string) $user->getAuthIdentifier()
            || $pending['provider'] !== $this->getApprovingProvider())) {
            $this->pending->pull($request, $authorization);

            throw InvalidAuthTokenException::different();
        }

        $pending['user_id'] = (string) $user->getAuthIdentifier();
        $pending['provider'] = $this->getApprovingProvider();
        $authRequest->setUser(new User($pending['user_id']));

        if (in_array('select_account', $prompts, true) && ! ($pending['account_selected'] ?? false)) {
            $authorization = $this->saveAuthorization($request, $authorization, [...$pending, 'stage' => 'account']);

            return app(AccountSelectionViewResponse::class)->withParameters([
                'client' => $client, 'user' => $user, 'request' => $request, 'authToken' => $authorization,
            ])->toResponse($request);
        }

        if (in_array('login', $prompts, true) && ! ($pending['login_confirmed'] ?? false)) {
            if ($silent) {
                throw OAuthServerException::loginRequired($authRequest);
            }

            $confirmationKey = PasswordConfirmation::sessionKey($pending['guard']);

            if (isset($pending['confirmation_started_at'])
                && $request->session()->get($confirmationKey, 0) >= $pending['confirmation_started_at']) {
                $pending['login_confirmed'] = true;
                unset($pending['confirmation_started_at']);
            } else {
                if (! isset($pending['confirmation_started_at'])) {
                    $request->session()->forget($confirmationKey);
                    $pending['confirmation_started_at'] = Date::now()->getTimestamp();
                }

                $authorization = $this->saveAuthorization($request, $authorization, [...$pending, 'stage' => 'confirm']);

                return app(ReauthenticationResponse::class)->withParameters([
                    'authToken' => $authorization,
                    'client' => $client,
                    'user' => $user,
                    'returnUrl' => route('passport.authorizations.resume', ['authorization' => $authorization]),
                ])->toResponse($request);
            }
        }

        $scopes = $this->parseScopes($authRequest);
        $state = $this->authorizationState($request, $parameters, $user, $client, $scopes, $authRequest, $this->events);

        if (array_key_exists('permissions', $pending)) {
            // Reopening a presentation must not let an older form approve newly added permissions.
            $state['permissions'] = $pending['permissions'];
            $state['remember_consent_for'] = $pending['remember_consent_for'];
        }

        $pending = [...$pending, ...$state];

        if (! in_array('consent', $prompts, true)) {
            $approval = $client->skipsAuthorization($user, $scopes) ? AuthorizationApproval::Policy
                : ($this->hasGrantedScopes($user, $client, $scopes, $pending['permissions']) ? AuthorizationApproval::Remembered : null);

            if ($approval !== null) {
                $response = $this->finalizer->approve(
                    $request,
                    $authRequest,
                    $pending,
                    $user,
                    $approval,
                    fn () => $this->approveRequest($authRequest, $psrResponse)
                );

                if ($response !== null) {
                    if ($authorization !== null) {
                        $this->pending->pull($request, $authorization);
                    }

                    return $this->responses->success(
                        $response,
                        $pending['redirect_uri'],
                        $pending['parameters']['state'] ?? null,
                        $pending['response_mode'],
                    );
                }
            }
        }

        if ($silent) {
            throw OAuthServerException::consentRequired($authRequest);
        }

        $authorization = $this->saveAuthorization($request, $authorization, [...$pending, 'stage' => 'consent']);

        return $viewResponse->withParameters([
            'client' => $client, 'user' => $user, 'scopes' => $scopes,
            'redirectUri' => $pending['redirect_uri'],
            'resources' => Passport::resourcesFor(array_keys($pending['permissions']['resources'] ?? [])),
            'permissions' => $pending['permissions'], 'request' => $request, 'authToken' => $authorization,
        ]);
    }

    /**
     * Save a new interaction or advance it without extending its lifetime.
     *
     * @param array<string, mixed> $pending
     */
    protected function saveAuthorization(Request $request, ?string $authorization, array $pending): string
    {
        if ($authorization === null) {
            return $this->pending->store($request, $pending);
        }

        $this->pending->update($request, $authorization, $pending);

        return $authorization;
    }

    /**
     * Transform the authorization request's scopes into Scope instances.
     *
     * @return Scope[]
     */
    protected function parseScopes(AuthorizationRequestInterface $authRequest): array
    {
        return Passport::scopesFor(
            collect($authRequest->getScopes())->map(
                fn (ScopeEntityInterface $scope): string => $scope->getIdentifier()
            )->unique()->all()
        );
    }

    /**
     * Determine if the given user has already granted the client access to the scopes.
     *
     * This pre-check does not replace the final locked consent check. Customize
     * Consent::covers() for remembered coverage, or Client::skipsAuthorization()
     * for trusted approval without remembered consent.
     *
     * @param Scope[] $scopes
     * @param array<string, array> $permissions
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes, array $permissions = []): bool
    {
        $consent = $this->consents->findForUser(
            $client->getKey(),
            $this->getApprovingProvider(),
            $user->getAuthIdentifier(),
        );

        return $consent?->covers(array_map(static fn (Scope $scope): string => $scope->id, $scopes), $permissions) ?? false;
    }

    /**
     * Approve the authorization request.
     */
    protected function approveRequest(AuthorizationRequestInterface $authRequest, ResponseInterface $psrResponse): Response
    {
        $authRequest->setAuthorizationApproved(true);

        return $this->withErrorHandling(fn () => $this->convertResponse(
            $this->servers->authorizationServer()->completeAuthorizationRequest($authRequest, $psrResponse)
        ));
    }

    /**
     * Prompt the user to login by throwing an AuthenticationException.
     *
     * @throws AuthenticationException
     */
    protected function promptForLogin(Request $request): never
    {
        throw new AuthenticationException(guards: [
            $this->getApprovingGuardName(),
        ]);
    }
}
