<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth;

use Hypervel\Http\RedirectResponse;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\OAuth\Concerns\InteractsWithOAuthEndpoints;
use Hypervel\Mcp\Client\OAuth\Enums\TokenEndpointAuthMethod;
use Hypervel\Support\Facades\Request;
use Hypervel\Support\Facades\Session;
use Hypervel\Support\Str;
use Hypervel\Support\Uri;
use SensitiveParameter;

class OAuthClient
{
    use InteractsWithOAuthEndpoints;

    protected ?DiscoveryResult $discovered = null;

    protected ?string $returnTo = null;

    /**
     * Create an OAuth client for the configured resource.
     */
    public function __construct(
        protected OAuthConfig $config,
        protected string $resourceUrl,
        protected ?string $resourceMetadataUrl = null,
        protected ?string $challengeScope = null,
        protected AuthServerDiscovery $discovery = new AuthServerDiscovery,
        protected ?string $clientIdMetadataUrl = null,
    ) {
        $this->resourceUrl = Str::before($this->resourceUrl, '#');
    }

    /**
     * Redirect to the authorization server and remember the pending exchange.
     */
    public function redirect(?string $returnTo = null): RedirectResponse
    {
        $discovered = $this->discover();
        $metadata = $discovered->server;

        if ($metadata->codeChallengeMethodsSupported === []) {
            throw new OAuthException('The authorization server metadata does not advertise [code_challenge_methods_supported], so it does not support the required PKCE.');
        }

        if (! in_array('S256', $metadata->codeChallengeMethodsSupported, true)) {
            throw new OAuthException('The authorization server does not support the required S256 PKCE method.');
        }

        $clientId = $this->config->clientId;
        $clientSecret = $this->config->clientSecret;
        $redirectUri = $this->config->redirectUri ?? throw new OAuthException('A redirect URI is required.');

        if ($clientId === null) {
            $registration = $this->usesClientIdMetadataDocument($metadata)
                ? new ClientRegistration(clientId: (string) $this->clientIdMetadataUrl)
                : $this->register($metadata, $redirectUri);

            $clientId = $registration->clientId;
            $clientSecret = $registration->clientSecret;
        }

        $pkce = Pkce::generate();
        $state = Str::random(40);
        $scope = $this->authorizationScope();
        $resource = $discovered->resource ?? $this->resourceUrl;

        Session::put($this->sessionKey(), [
            'state' => $state,
            'verifier' => $pkce->verifier,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'token_endpoint' => $metadata->tokenEndpoint,
            'token_auth_method' => $this->resolveTokenAuthMethod($metadata, $clientSecret)->value,
            'redirect_uri' => $redirectUri,
            'return_to' => $returnTo,
            'issuer' => $metadata->issuer,
            'iss_supported' => $metadata->authorizationResponseIssParameterSupported,
            'scope' => $scope,
            'resource' => $resource,
        ]);

        $authorizeUrl = Uri::of($metadata->authorizationEndpoint)->withQuery(array_filter([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'scope' => $scope,
            'resource' => $resource,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return new RedirectResponse((string) $authorizeUrl);
    }

    /**
     * Obtain a token using the configured client credentials.
     */
    public function clientCredentials(): TokenSet
    {
        if ($this->config->clientId === null) {
            throw new OAuthException('A client_id is required for the client_credentials grant.');
        }

        $discovered = $this->discover();

        return $this->requestToken(
            $discovered->server->tokenEndpoint,
            [
                'grant_type' => 'client_credentials',
                'scope' => $this->authorizationScope(),
                'resource' => $discovered->resource ?? $this->resourceUrl,
            ],
            $this->config->clientId,
            $this->config->clientSecret,
            $this->resolveTokenAuthMethod($discovered->server, $this->config->clientSecret),
        );
    }

    /**
     * Exchange the current authorization callback for a token.
     */
    public function exchangeCallback(): TokenSet
    {
        $this->throwOnServerError();

        $code = $this->query('code');

        if ($code === null) {
            throw new OAuthException('The OAuth callback did not include an authorization code.');
        }

        $state = Request::query('state');
        $iss = Request::query('iss');

        return $this->exchangeAuthorizationCode(
            $code,
            is_string($state) ? $state : '',
            is_string($iss) ? $iss : null,
        );
    }

    /**
     * Get the return URL remembered by the authorization exchange.
     */
    public function returnTo(): ?string
    {
        return $this->returnTo;
    }

    /**
     * Refresh an access token using its client credentials.
     */
    public function refreshCredentials(
        #[SensitiveParameter]
        string $refreshToken,
        ?string $clientId = null,
        #[SensitiveParameter]
        ?string $clientSecret = null,
    ): TokenSet {
        $discovered = $this->discover();

        $clientId ??= $this->config->clientId;
        $clientSecret ??= $this->config->clientSecret;

        $token = $this->requestToken(
            $discovered->server->tokenEndpoint,
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope' => $this->resolveScope(),
                'resource' => $discovered->resource ?? $this->resourceUrl,
            ],
            $clientId,
            $clientSecret,
            $this->resolveTokenAuthMethod($discovered->server, $clientSecret),
        );

        $token->clientId = $clientId;
        $token->clientSecret = $clientSecret;

        return $token;
    }

    /**
     * Validate and exchange the authorization code against the pending session.
     */
    protected function exchangeAuthorizationCode(
        #[SensitiveParameter]
        string $code,
        string $state,
        ?string $iss,
    ): TokenSet {
        $stored = $this->pendingAuthorization($state, $iss);

        $this->returnTo = is_string($stored['return_to'] ?? null) && $stored['return_to'] !== ''
            ? $stored['return_to']
            : null;

        Session::forget($this->sessionKey());

        $clientId = (string) $stored['client_id'];
        $clientSecret = isset($stored['client_secret']) ? (string) $stored['client_secret'] : null;

        $token = $this->requestToken(
            (string) $stored['token_endpoint'],
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => (string) $stored['redirect_uri'],
                'code_verifier' => (string) $stored['verifier'],
                'resource' => (string) $stored['resource'],
            ],
            $clientId,
            $clientSecret,
            TokenEndpointAuthMethod::tryFrom((string) ($stored['token_auth_method'] ?? '')) ?? TokenEndpointAuthMethod::ClientSecretPost,
        );

        $token->clientId = $clientId;
        $token->clientSecret = $clientSecret;
        $token->scope ??= $stored['scope'] ?? null;

        return $token;
    }

    /**
     * Validate the callback against its pending authorization.
     *
     * @return array<string, mixed>
     */
    private function pendingAuthorization(string $state, ?string $iss): array
    {
        /** @var null|array<string, mixed> $stored */
        $stored = Session::get($this->sessionKey());

        if (! is_array($stored)) {
            throw new OAuthException('No pending OAuth authorization was found in the session.');
        }

        if (! is_string($stored['state'] ?? null) || ! hash_equals($stored['state'], $state)) {
            throw new OAuthException('The OAuth state parameter did not match. Possible CSRF attempt.');
        }

        $this->validateIssuer($stored, $iss);

        return $stored;
    }

    /**
     * Determine whether the client can identify itself through a metadata document.
     */
    protected function usesClientIdMetadataDocument(AuthServerMetadata $metadata): bool
    {
        if (! $metadata->clientIdMetadataDocumentSupported || $this->clientIdMetadataUrl === null) {
            return false;
        }

        $url = parse_url($this->clientIdMetadataUrl);

        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ($url['path'] ?? '/') === '/') {
            return false;
        }

        if (isset($url['fragment']) || isset($url['query'])) {
            return false;
        }

        return $this->isPubliclyResolvable((string) ($url['host'] ?? ''));
    }

    /**
     * Determine whether a metadata document host is eligible for public discovery.
     */
    protected function isPubliclyResolvable(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === '' || $host === 'localhost' || preg_match('/\.(test|local|localhost|internal|invalid|example)$/', $host) === 1) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Register a client when no configured client identifier is available.
     */
    protected function register(AuthServerMetadata $metadata, string $redirectUri): ClientRegistration
    {
        if ($metadata->registrationEndpoint === null) {
            throw new OAuthException('No client_id was configured, no https client ID metadata document URL is available, and the authorization server does not support dynamic client registration.');
        }

        return (new DynamicClientRegistration)->register(
            $metadata->registrationEndpoint,
            $redirectUri,
            $this->authorizationScope(),
            applicationType: $this->applicationType($redirectUri),
            tokenEndpointAuthMethod: $this->resolveTokenAuthMethod($metadata, 'confidential'),
        );
    }

    /**
     * Request a token using the selected client authentication method.
     *
     * @param array<string, mixed> $params
     */
    protected function requestToken(
        string $tokenEndpoint,
        array $params,
        ?string $clientId,
        #[SensitiveParameter]
        ?string $clientSecret,
        TokenEndpointAuthMethod $authMethod,
    ): TokenSet {
        $request = $this->oAuthRequest()->asForm();

        $credentials = match ($authMethod) {
            TokenEndpointAuthMethod::ClientSecretBasic => [],
            TokenEndpointAuthMethod::ClientSecretPost => ['client_id' => $clientId, 'client_secret' => $clientSecret],
            TokenEndpointAuthMethod::None => ['client_id' => $clientId],
        };

        if ($authMethod === TokenEndpointAuthMethod::ClientSecretBasic) {
            $request = $request->withBasicAuth((string) $clientId, (string) $clientSecret);
        }

        $destination = $this->resolveOAuthDestination($request, $tokenEndpoint);
        $response = $request->post((string) $destination->uri, array_filter([...$params, ...$credentials], static fn (mixed $value): bool => $value !== null));

        if (! $response->successful()) {
            throw new OAuthException("Token request to [{$tokenEndpoint}] failed with status [{$response->status()}].");
        }

        $data = $response->json();

        if (! is_array($data) || empty($data['access_token'])) {
            throw new OAuthException('The token response did not include an access_token.');
        }

        $token = TokenSet::fromResponse($data);
        // OAuth permits omitting response scope only when it matches the requested scope.
        $token->scope ??= $params['scope'] ?? null;

        return $token;
    }

    /**
     * Report an authorization error returned in the callback.
     */
    protected function throwOnServerError(): void
    {
        if (($error = $this->query('error')) === null) {
            return;
        }

        $state = Request::query('state');
        $iss = Request::query('iss');

        // Error callbacks require the same issuer validation before their descriptions can be trusted.
        $this->pendingAuthorization(
            is_string($state) ? $state : '',
            is_string($iss) ? $iss : null,
        );

        $description = $this->query('error_description');

        throw new OAuthException($description === null
            ? "The authorization server returned an error [{$error}]."
            : "The authorization server returned an error [{$error}]: {$description}");
    }

    /**
     * Get a nonempty string from the callback query.
     */
    protected function query(string $key): ?string
    {
        $value = Request::query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Discover the authorization server once for this client.
     */
    protected function discover(): DiscoveryResult
    {
        return $this->discovered ??= $this->discovery->discover($this->resourceUrl, $this->resourceMetadataUrl);
    }

    /**
     * Resolve the challenged or configured authorization scope.
     */
    protected function resolveScope(): ?string
    {
        if (filled($this->challengeScope)) {
            return $this->challengeScope;
        }

        return filled($this->config->scope) ? $this->config->scope : null;
    }

    /**
     * Use advertised scopes when no authorization scope was selected.
     */
    protected function authorizationScope(): ?string
    {
        if (($scope = $this->resolveScope()) !== null) {
            return $scope;
        }

        $scopes = $this->discover()->scopesSupported;

        // Refresh grants keep resolveScope(): inferred scopes could exceed the original grant.
        return $scopes === [] ? null : implode(' ', $scopes);
    }

    /**
     * Choose a supported authentication method for the available credentials.
     */
    protected function resolveTokenAuthMethod(
        AuthServerMetadata $metadata,
        #[SensitiveParameter]
        ?string $clientSecret,
    ): TokenEndpointAuthMethod {
        if (blank($clientSecret)) {
            return TokenEndpointAuthMethod::None;
        }

        $supported = $metadata->tokenEndpointAuthMethodsSupported;

        if ($supported !== []
            && ! in_array(TokenEndpointAuthMethod::ClientSecretPost->value, $supported, true)
            && in_array(TokenEndpointAuthMethod::ClientSecretBasic->value, $supported, true)) {
            return TokenEndpointAuthMethod::ClientSecretBasic;
        }

        return TokenEndpointAuthMethod::ClientSecretPost;
    }

    /**
     * Identify loopback callback applications as native clients.
     */
    protected function applicationType(string $redirectUri): string
    {
        $host = parse_url($redirectUri, PHP_URL_HOST);

        return is_string($host) && $this->isLocalhost($this->normalizedHost($host))
            ? 'native'
            : 'web';
    }

    /**
     * Validate the callback issuer against the pending authorization.
     *
     * @param array<string, mixed> $stored
     */
    protected function validateIssuer(array $stored, ?string $iss): void
    {
        $expectedIssuer = is_string($stored['issuer'] ?? null) ? $stored['issuer'] : '';

        if ($iss !== null) {
            if ($expectedIssuer === '' || ! hash_equals($expectedIssuer, $iss)) {
                throw new OAuthException('The OAuth issuer (iss) parameter did not match the expected issuer. Possible mix-up attack.');
            }

            return;
        }

        if ($stored['iss_supported'] ?? false) {
            throw new OAuthException('The authorization response is missing the required iss parameter.');
        }
    }

    /**
     * Get the session key for this resource's pending authorization.
     */
    protected function sessionKey(): string
    {
        return 'mcp.oauth.' . sha1($this->resourceUrl);
    }
}
