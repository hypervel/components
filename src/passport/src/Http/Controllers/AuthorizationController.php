<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Bridge\User;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Contracts\AuthorizationViewResponse;
use Hypervel\Passport\Exceptions\AuthenticationException;
use Hypervel\Passport\Exceptions\OAuthServerException;
use Hypervel\Passport\Passport;
use Hypervel\Passport\Scope;
use Hypervel\Passport\ServerFactory;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

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
        protected AuthFactory $auth,
        protected ClientRepository $clients,
        protected Repository $config,
    ) {
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
        $authRequest = $this->withErrorHandling(
            fn (): AuthorizationRequestInterface => $this->servers->authorizationServer()->validateAuthorizationRequest($psrRequest)
        );

        $additionalPrompts = Passport::$resolvePromptsCallback
            ? $this->withErrorHandling(fn () => (Passport::$resolvePromptsCallback)($request, $authRequest))
            : [];
        // Collection callbacks also receive the key, which trim would treat as its character mask.
        $prompt = $request->string('prompt')->explode(' ')->map(static fn (string $prompt): string => trim($prompt))->filter()->values();

        // If the prompt parameter includes "none", all other prompt values will be ignored
        // An error will be returned if the end-user is not already authenticated or the
        // OAuth client does not have pre-configured consent for the requested scopes.
        if ($prompt->contains('none')) {
            if (in_array('login', $additionalPrompts, true)) {
                throw OAuthServerException::loginRequired($authRequest);
            }

            if (in_array('consent', $additionalPrompts, true)) {
                throw OAuthServerException::consentRequired($authRequest);
            }

            $prompt = collect(['none']);
        } else {
            $prompt = $prompt->merge($additionalPrompts);
        }

        $guard = $this->getApprovingGuard();

        if ($guard->guest()) {
            $prompt->contains('none')
                ? throw OAuthServerException::loginRequired($authRequest)
                : $this->promptForLogin($request);
        }

        if ($prompt->contains('login')
            && ! $request->session()->get('promptedForLogin', false)) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $this->promptForLogin($request);
        }

        $request->session()->forget('promptedForLogin');

        $user = $guard->user();
        $authRequest->setUser(new User((string) $user->getAuthIdentifier()));

        $scopes = $this->parseScopes($authRequest);
        $client = $this->withErrorHandling(function () use ($authRequest, $psrRequest): Client {
            $client = $this->clients->findActive($authRequest->getClient()->getIdentifier());

            if (! $client || ! $this->clientMatchesProvider($client)) {
                throw LeagueException::invalidClient($psrRequest);
            }

            return $client;
        });
        $attributes = $this->withErrorHandling(fn () => $this->authorizationAttributes($request, $user));

        if ($prompt->doesntContain('consent')
            && ($client->skipsAuthorization($user, $scopes) || $this->hasGrantedScopes($user, $client, $scopes))) {
            return TokenIssuance::run(
                $this->servers,
                fn () => $this->approveRequest($authRequest, $psrResponse),
                $attributes
            );
        }

        if ($prompt->contains('none')) {
            throw OAuthServerException::consentRequired($authRequest);
        }

        $request->session()->put('authToken', $authToken = Str::random());
        $request->session()->put('authRequest', serialize($authRequest));
        $request->session()->put('authAttributes', $attributes);

        return $viewResponse->withParameters([
            'client' => $client,
            'user' => $user,
            'scopes' => $scopes,
            'request' => $request,
            'authToken' => $authToken,
        ]);
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
     * @param Scope[] $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        $activeTokens = $client->tokens()->where([
            ['user_id', '=', $user->getAuthIdentifier()],
            ['provider', '=', $this->getApprovingProvider()],
            ['revoked', '=', false],
            ['expires_at', '>', Date::now()],
        ]);

        // If no specific scope is requested, we'll simply check whether the given
        // user has any active tokens that grant access to the specified client
        // In this case, comparing the granted scopes is no longer necessary.
        if (empty($scopes)) {
            return $activeTokens->exists();
        }

        // Otherwise, we list all previously granted scopes from the active tokens
        // of the given user that authorize access to the specified client, and
        // check whether the newly requested scopes are included in that set.
        return collect($scopes)->pluck('id')->diff(
            $activeTokens->pluck('scopes')->flatten()
        )->isEmpty();
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
        $request->session()->put('promptedForLogin', true);

        throw new AuthenticationException(guards: [
            $this->config->get('passport.guard') ?? $this->auth->getDefaultDriver(),
        ]);
    }
}
