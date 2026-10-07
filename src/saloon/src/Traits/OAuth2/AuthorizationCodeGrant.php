<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\OAuth2;

use DateTimeImmutable;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Data\AuthorizationUrl;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Exceptions\InvalidStateException;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
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
use UnexpectedValueException;

/**
 * @phpstan-require-extends Connector
 * @phpstan-ignore trait.unused (user-facing OAuth 2 trait)
 */
trait AuthorizationCodeGrant
{
    use HasOAuthConfig;
    use ParsesOAuthTokenResponses;

    /**
     * Create an authorization URL and its paired state.
     *
     * @param list<?string> $scopes
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

        $resolvedScopes = $config->scopes($scopes);
        $queryParameters = ['response_type' => 'code'];

        if ($resolvedScopes !== []) {
            $queryParameters['scope'] = implode($scopeSeparator, $resolvedScopes);
        }

        $queryParameters['client_id'] = $config->clientId;
        $queryParameters['redirect_uri'] = $config->redirectUri;
        $queryParameters['state'] = $state;

        if ($codeChallenge !== null) {
            $queryParameters['code_challenge'] = $codeChallenge;
            $queryParameters['code_challenge_method'] = $codeChallengeMethod;
        }

        // Additional parameters may replace the others, but not the state paired with the returned URL.
        $queryParameters = [...$queryParameters, ...$additionalQueryParameters, 'state' => $state];
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
        $request = $this->resolveAccessTokenRequest($code, $config);

        // Adding the verifier here keeps PKCE working with a custom token request resolver.
        if ($codeVerifier !== null) {
            $request->withData(['code_verifier' => $codeVerifier]);
        }

        $request = $config->modify($request);
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
            if ($refreshToken->isNotRefreshable()) {
                throw new InvalidArgumentException('The provided OAuthAuthenticator does not contain a refresh token.');
            }

            /** @var string $refreshToken */
            $refreshToken = $refreshToken->getRefreshToken();
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
     * Create an OAuth authenticator from a token response.
     */
    protected function createOAuthAuthenticatorFromResponse(
        #[SensitiveParameter]
        Response $response,
        #[SensitiveParameter]
        ?string $fallbackRefreshToken = null,
    ): OAuthAuthenticator {
        [$accessToken, $expiresAt, $data] = $this->parseOAuthTokenResponse($response);
        $refreshToken = $data['refresh_token'] ?? $fallbackRefreshToken;

        if ($refreshToken !== null && ! is_string($refreshToken)) {
            throw new UnexpectedValueException('The OAuth token response contains an invalid refresh token.');
        }

        return $this->createOAuthAuthenticator($accessToken, $refreshToken, $expiresAt);
    }

    /**
     * Create an OAuth authenticator.
     */
    protected function createOAuthAuthenticator(
        #[SensitiveParameter]
        string $accessToken,
        #[SensitiveParameter]
        ?string $refreshToken = null,
        ?DateTimeImmutable $expiresAt = null,
    ): OAuthAuthenticator {
        return new AccessTokenAuthenticator($accessToken, $refreshToken, $expiresAt);
    }

    /**
     * Retrieve the authenticated OAuth user.
     *
     * @template TRequest of Request
     * @param null|callable(TRequest): void $requestModifier
     */
    public function getUser(OAuthAuthenticator $oauthAuthenticator, ?callable $requestModifier = null): Response
    {
        $config = $this->oauthConfig();
        $request = $config->modify($this->resolveUserRequest($config))->authenticate($oauthAuthenticator);
        $requestModifier?->__invoke($request);

        return $this->send($request);
    }

    // getState() is not included: authorizationUrl() returns the state with its URL, since the connector may be shared.

    /**
     * Resolve the access-token request.
     */
    protected function resolveAccessTokenRequest(#[SensitiveParameter] string $code, OAuthConfig $oauthConfig): Request
    {
        return new GetAccessTokenRequest($code, $oauthConfig);
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
