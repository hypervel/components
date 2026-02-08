<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Hypervel\Contracts\Http\Request as RequestContract;
use Hypervel\Contracts\Http\Response as ResponseContract;
use Hypervel\Socialite\Contracts\Provider as ProviderContract;
use Hypervel\Socialite\HasProviderContext;
use Hypervel\Socialite\Two\Exceptions\InvalidStateException;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractProvider implements ProviderContract
{
    use HasProviderContext;

    /**
     * The custom parameters to be sent with the request.
     */
    protected array $parameters = [];

    /**
     * The scopes being requested.
     */
    protected array $scopes = [];

    /**
     * The separating character for the requested scopes.
     */
    protected string $scopeSeparator = ',';

    /**
     * The type of the encoding in the query.
     *
     * @var int can be either PHP_QUERY_RFC3986 or PHP_QUERY_RFC1738
     */
    protected int $encodingType = PHP_QUERY_RFC1738;

    /**
     * Indicates if the session state should be utilized.
     */
    protected bool $stateless = false;

    /**
     * Indicates if PKCE should be used.
     */
    protected bool $usesPKCE = false;

    /**
     * Create a new provider instance.
     *
     * @param RequestContract $request the HTTP request instance
     * @param ResponseContract $response the HTTP response instance
     * @param string $clientId the client ID
     * @param string $clientSecret the client secret
     * @param string $redirectUrl the redirect URL
     * @param array $guzzle array The custom Guzzle configuration options
     */
    public function __construct(
        protected RequestContract $request,
        protected ResponseContract $response,
        protected string $clientId,
        protected string $clientSecret,
        protected string $redirectUrl,
        protected array $guzzle = []
    ) {
    }

    /**
     * Get the authentication URL for the provider.
     */
    abstract protected function getAuthUrl(?string $state): string;

    /**
     * Get the token URL for the provider.
     */
    abstract protected function getTokenUrl(): string;

    /**
     * Get the raw user for the given access token.
     */
    abstract protected function getUserByToken(string $token): mixed;

    /**
     * Map the raw user array to a Socialite User instance.
     */
    abstract protected function mapUserToObject(array $user): User;

    /**
     * Redirect the user of the application to the provider's authentication screen.
     */
    public function redirect(): ResponseInterface
    {
        $state = null;

        if ($this->usesState()) {
            $this->request->session()->put('state', $state = $this->getState());
        }

        if ($this->usesPKCE()) {
            $this->request->session()->put('code_verifier', $this->getCodeVerifier());
        }

        return $this->response->redirect(
            $this->getAuthUrl($state)
        );
    }

    /**
     * Build the authentication URL for the provider from the given base URL.
     */
    protected function buildAuthUrlFromBase(string $url, ?string $state): string
    {
        return $url . '?' . http_build_query($this->getCodeFields($state), '', '&', $this->encodingType);
    }

    /**
     * Get the GET parameters for the code request.
     */
    protected function getCodeFields(?string $state = null): array
    {
        $fields = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->getRedirectUrl(),
            'scope' => $this->formatScopes($this->getScopes(), $this->scopeSeparator),
            'response_type' => 'code',
        ];

        if ($this->usesState()) {
            $fields['state'] = $state;
        }

        if ($this->usesPKCE()) {
            $fields['code_challenge'] = $this->getCodeChallenge();
            $fields['code_challenge_method'] = $this->getCodeChallengeMethod();
        }

        return array_merge($fields, $this->getParameters());
    }

    /**
     * Format the given scopes.
     */
    protected function formatScopes(array $scopes, string $scopeSeparator): string
    {
        return implode($scopeSeparator, $scopes);
    }

    public function user(): User
    {
        if ($user = $this->getUser()) {
            return $user;
        }

        if ($this->hasInvalidState()) {
            throw new InvalidStateException();
        }

        $response = $this->getAccessTokenResponse($this->getCode());

        $user = $this->getUserByToken(Arr::get($response, 'access_token'));

        return $this->userInstance($response, $user);
    }

    /**
     * Get the user instance from the context.
     */
    protected function getUser(): ?User
    {
        return $this->getContext('user');
    }

    /**
     * Set the user instance in the context.
     */
    protected function setUser(User $user): static
    {
        $this->setContext('user', $user);

        return $this;
    }

    /**
     * Create a user instance from the given data.
     */
    protected function userInstance(array $response, array $user): User
    {
        $this->setUser(
            $this->mapUserToObject($user)
        );

        return $this->getUser()->setToken(Arr::get($response, 'access_token'))
            ->setRefreshToken(Arr::get($response, 'refresh_token'))
            ->setExpiresIn(Arr::get($response, 'expires_in'))
            ->setApprovedScopes(explode($this->scopeSeparator, Arr::get($response, 'scope', '')));
    }

    /**
     * Get a Social User instance from a known access token.
     */
    public function userFromToken(string $token): User
    {
        $user = $this->mapUserToObject($this->getUserByToken($token));

        return $user->setToken($token);
    }

    /**
     * Determine if the current request / session has a mismatching "state".
     */
    protected function hasInvalidState(): bool
    {
        if ($this->isStateless()) {
            return false;
        }

        $state = $this->request->session()->pull('state');

        return empty($state) || $this->request->input('state') !== $state;
    }

    /**
     * Get the access token response for the given code.
     */
    public function getAccessTokenResponse(string $code): mixed
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => $this->getTokenHeaders($code),
            RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Get the headers for the access token request.
     */
    protected function getTokenHeaders(string $code): array
    {
        return ['Accept' => 'application/json'];
    }

    /**
     * Get the POST fields for the token request.
     */
    protected function getTokenFields(string $code): array
    {
        $fields = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->getRedirectUrl(),
        ];

        if ($this->usesPKCE()) {
            $fields['code_verifier'] = $this->request->session()->pull('code_verifier');
        }

        return array_merge($fields, $this->getParameters());
    }

    /**
     * Refresh a user's access token with a refresh token.
     */
    public function refreshToken(string $refreshToken): Token
    {
        $response = $this->getRefreshTokenResponse($refreshToken);

        return new Token(
            Arr::get($response, 'access_token'),
            Arr::get($response, 'refresh_token'),
            Arr::get($response, 'expires_in'),
            explode($this->scopeSeparator, Arr::get($response, 'scope', ''))
        );
    }

    /**
     * Get the refresh token response for the given refresh token.
     */
    protected function getRefreshTokenResponse(string $refreshToken): mixed
    {
        return json_decode((string) $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
            RequestOptions::FORM_PARAMS => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ])->getBody(), true);
    }

    /**
     * Get the code from the request.
     */
    protected function getCode(): string
    {
        return $this->request->input('code');
    }

    /**
     * Merge the scopes of the requested access.
     */
    public function scopes(array|string $scopes): static
    {
        $this->setScopes(
            array_values(array_unique(array_merge($this->getScopes(), Arr::wrap($scopes))))
        );

        return $this;
    }

    /**
     * Set the scopes of the requested access.
     */
    public function setScopes(array|string $scopes): static
    {
        $this->setContext(
            'scopes',
            array_values(array_unique(Arr::wrap($scopes)))
        );

        return $this;
    }

    /**
     * Get the current scopes.
     */
    public function getScopes(): array
    {
        return $this->getContext('scopes', $this->scopes);
    }

    /**
     * Set the redirect URL.
     */
    public function redirectUrl(string $url): static
    {
        $this->setContext('redirectUrl', $url);

        return $this;
    }

    /**
     * Get the redirect URL.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getContext('redirectUrl', $this->redirectUrl);
    }

    /**
     * Get a instance of the Guzzle HTTP client.
     */
    protected function getHttpClient(): Client
    {
        return $this->getOrSetContext('httpClient', function () {
            return new Client($this->guzzle);
        });
    }

    /**
     * Set the request instance.
     */
    public function setRequest(RequestContract $request): static
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Determine if the provider is operating with state.
     */
    protected function usesState(): bool
    {
        return ! $this->isStateless();
    }

    /**
     * Determine if the provider is operating as stateless.
     */
    protected function isStateless(): bool
    {
        return $this->getContext('stateless', $this->stateless);
    }

    /**
     * Indicates that the provider should operate as stateless.
     */
    public function stateless(): static
    {
        $this->setContext('stateless', true);

        return $this;
    }

    /**
     * Get the string used for session state.
     */
    protected function getState(): string
    {
        return Str::random(40);
    }

    /**
     * Determine if the provider uses PKCE.
     */
    protected function usesPKCE(): bool
    {
        return $this->getContext('usesPKCE', $this->usesPKCE);
    }

    /**
     * Enables PKCE for the provider.
     */
    public function enablePKCE(): static
    {
        $this->setContext('usesPKCE', true);

        return $this;
    }

    /**
     * Generates a random string of the right length for the PKCE code verifier.
     */
    protected function getCodeVerifier(): string
    {
        return Str::random(96);
    }

    /**
     * Generates the PKCE code challenge based on the PKCE code verifier in the session.
     */
    protected function getCodeChallenge(): string
    {
        $hashed = hash('sha256', $this->request->session()->get('code_verifier'), true);

        return rtrim(strtr(base64_encode($hashed), '+/', '-_'), '=');
    }

    /**
     * Returns the hash method used to calculate the PKCE code challenge.
     */
    protected function getCodeChallengeMethod(): string
    {
        return 'S256';
    }

    /**
     * Set the custom parameters of the request.
     */
    public function with(array $parameters): static
    {
        $this->setContext('parameters', $parameters);

        return $this;
    }

    /**
     * Get the custom parameters of the request.
     */
    protected function getParameters(): array
    {
        return $this->getContext('parameters', $this->parameters);
    }
}
