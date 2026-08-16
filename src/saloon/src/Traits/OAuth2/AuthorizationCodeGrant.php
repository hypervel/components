<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\OAuth2;

use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Data\AuthorizationUrl;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Exceptions\InvalidStateException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\OAuth2\GetAccessTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetRefreshTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetUserRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\UrlResolver;
use Hypervel\Support\Str;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * @phpstan-require-extends Connector
 * @phpstan-ignore trait.unused (user-facing OAuth 2 trait)
 */
trait AuthorizationCodeGrant
{
    use CreatesOAuthAuthenticator;
    use HasOAuthConfig;

    /**
     * Create an authorization URL and its paired state.
     *
     * @param list<string> $scopes
     * @param array<string, mixed> $additionalQueryParameters
     */
    public function authorizationUrl(
        array $scopes = [],
        ?string $state = null,
        string $scopeSeparator = ' ',
        array $additionalQueryParameters = [],
        ?string $codeChallenge = null,
        string $codeChallengeMethod = 'S256',
    ): AuthorizationUrl {
        $config = $this->oauthConfig();
        $config->validate();
        $state ??= Str::random(32);

        if ($state === '') {
            throw new InvalidStateException;
        }

        if ($codeChallenge !== null
            && ($codeChallenge === '' || ! in_array($codeChallengeMethod, ['S256', 'plain'], true))) {
            throw new InvalidArgumentException('The PKCE challenge must be non-empty and use the [S256] or [plain] method.');
        }

        $resolvedScopes = [...$config->defaultScopes, ...$scopes];
        $queryParameters = [
            'response_type' => 'code',
            'client_id' => $config->clientId,
            'redirect_uri' => $config->redirectUri,
            'state' => $state,
        ];

        if ($resolvedScopes !== []) {
            $queryParameters['scope'] = implode($scopeSeparator, $resolvedScopes);
        }

        if ($codeChallenge !== null) {
            $queryParameters['code_challenge'] = $codeChallenge;
            $queryParameters['code_challenge_method'] = $codeChallengeMethod;
        }

        $queryParameters += $additionalQueryParameters;
        $uri = UrlResolver::resolve(
            $this->resolveBaseUrl(),
            $config->authorizeEndpoint,
            $config->allowBaseUrlOverride || $this->allowsBaseUrlOverride(),
        );
        $uri = UrlResolver::withQuery($uri, $queryParameters);

        return new AuthorizationUrl((string) $uri, $state);
    }

    /**
     * Exchange an authorization code for an access token.
     *
     * @template TRequest of Request
     * @param null|callable(TRequest): void $requestModifier
     * @return ($returnResponse is true ? Response : OAuthAuthenticator)
     */
    public function getAccessToken(
        #[SensitiveParameter]
        string $code,
        #[SensitiveParameter]
        ?string $state = null,
        #[SensitiveParameter]
        ?string $expectedState = null,
        bool $returnResponse = false,
        ?callable $requestModifier = null,
        #[SensitiveParameter]
        ?string $codeVerifier = null,
    ): OAuthAuthenticator|Response {
        $config = $this->oauthConfig();
        $config->validate();
        $this->validateState($state, $expectedState);
        $request = $config->modify($this->resolveAccessTokenRequest($code, $config, $codeVerifier));
        $requestModifier?->__invoke($request);
        $response = $this->send($request);

        if ($returnResponse) {
            return $response;
        }

        $response->throw();

        return $this->createOAuthAuthenticatorFromResponse($response);
    }

    /**
     * Refresh an OAuth access token.
     *
     * @template TRequest of Request
     * @param null|callable(TRequest): void $requestModifier
     * @return ($returnResponse is true ? Response : OAuthAuthenticator)
     */
    public function refreshAccessToken(
        #[SensitiveParameter]
        OAuthAuthenticator|string $refreshToken,
        bool $returnResponse = false,
        ?callable $requestModifier = null,
    ): OAuthAuthenticator|Response {
        $config = $this->oauthConfig();
        $config->validate();

        if ($refreshToken instanceof OAuthAuthenticator) {
            $refreshToken = $refreshToken->getRefreshToken()
                ?? throw new InvalidArgumentException('The provided OAuth authenticator does not contain a refresh token.');
        }

        $request = $config->modify($this->resolveRefreshTokenRequest($config, $refreshToken));
        $requestModifier?->__invoke($request);
        $response = $this->send($request);

        if ($returnResponse) {
            return $response;
        }

        $response->throw();

        return $this->createOAuthAuthenticatorFromResponse($response, $refreshToken);
    }

    /**
     * Retrieve the authenticated OAuth user.
     *
     * @template TRequest of Request
     * @param null|callable(TRequest): void $requestModifier
     */
    public function getUser(OAuthAuthenticator $authenticator, ?callable $requestModifier = null): Response
    {
        $config = $this->oauthConfig();
        $request = $config->modify($this->resolveUserRequest($config))->authenticate($authenticator);
        $requestModifier?->__invoke($request);

        return $this->send($request);
    }

    /**
     * Resolve the access-token request.
     */
    protected function resolveAccessTokenRequest(
        #[SensitiveParameter]
        string $code,
        OAuthConfig $oauthConfig,
        #[SensitiveParameter]
        ?string $codeVerifier = null,
    ): Request {
        return new GetAccessTokenRequest($code, $oauthConfig, $codeVerifier);
    }

    /**
     * Resolve the refresh-token request.
     */
    protected function resolveRefreshTokenRequest(
        OAuthConfig $oauthConfig,
        #[SensitiveParameter]
        string $refreshToken,
    ): Request {
        return new GetRefreshTokenRequest($oauthConfig, $refreshToken);
    }

    /**
     * Resolve the authenticated-user request.
     */
    protected function resolveUserRequest(OAuthConfig $oauthConfig): Request
    {
        return new GetUserRequest($oauthConfig);
    }

    /**
     * Validate returned OAuth state against the expected state.
     */
    protected function validateState(
        #[SensitiveParameter]
        ?string $state,
        #[SensitiveParameter]
        ?string $expectedState,
    ): void {
        if ($state === null && $expectedState === null) {
            return;
        }

        if ($state === null
            || $state === ''
            || $expectedState === null
            || $expectedState === ''
            || ! hash_equals($expectedState, $state)) {
            throw new InvalidStateException;
        }
    }
}
