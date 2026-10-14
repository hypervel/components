<?php

declare(strict_types=1);

namespace Hypervel\Passport\Guards;

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hypervel\Auth\GuardHelpers;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Encryption\DecryptException;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Cookie\CookieValuePrefix;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Http\Request;
use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PassportUserProvider;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\TransientToken;
use Hypervel\Support\Traits\Macroable;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use stdClass;

class TokenGuard implements Guard
{
    /** @use GuardHelpers<PassportUserProvider> */
    use GuardHelpers;

    use Macroable;

    /**
     * The user provider implementation.
     *
     * @var PassportUserProvider
     */
    protected ?UserProvider $provider = null;

    /**
     * Sentinel for a completed authentication lookup with no result.
     */
    private static object $nullSentinel;

    /**
     * Create a new token guard instance.
     */
    public function __construct(
        protected string $name,
        protected ServerFactory $servers,
        PassportUserProvider $provider,
        protected ClientRepository $clients,
        protected Encrypter $encrypter,
        protected Container $app,
    ) {
        $this->provider = $provider;
    }

    /**
     * Get the user for the incoming request.
     *
     * @return null|OAuthenticatable
     */
    public function user(): ?Authenticatable
    {
        if ($user = CoroutineContext::get($this->getExplicitUserContextKey())) {
            return $user;
        }

        if (! RequestContext::has()) {
            return null;
        }

        $request = $this->app->make('request');
        $contextKey = $this->getContextKey($request, 'user');
        self::$nullSentinel ??= new stdClass;

        if (($cached = RequestContext::getValue($contextKey)) !== null) {
            return $cached === self::$nullSentinel ? null : $cached;
        }

        $user = match (true) {
            (bool) $request->bearerToken() => $this->authenticateViaBearerToken($request),
            (bool) $request->cookie(Passport::cookie()) => $this->authenticateViaCookie($request),
            default => null,
        };

        RequestContext::setValue($contextKey, $user ?? self::$nullSentinel);

        return $user;
    }

    /**
     * Validate a user's credentials.
     *
     * @param array<string, mixed> $credentials
     */
    public function validate(#[SensitiveParameter] array $credentials = []): bool
    {
        $request = $credentials['request'];

        return match (true) {
            (bool) $request->bearerToken() => $this->authenticateViaBearerToken($request, false) !== null,
            (bool) $request->cookie(Passport::cookie()) => $this->authenticateViaCookie($request) !== null,
            default => false,
        };
    }

    /**
     * Get the client for the incoming request.
     */
    public function client(): ?Client
    {
        if ($client = CoroutineContext::get($this->getExplicitClientContextKey())) {
            return $client;
        }

        if (! RequestContext::has()) {
            return null;
        }

        $request = $this->app->make('request');

        if (! $request->bearerToken()) {
            return null;
        }

        $contextKey = $this->getContextKey($request, 'client');
        self::$nullSentinel ??= new stdClass;

        if (($cached = RequestContext::getValue($contextKey)) !== null) {
            return $cached === self::$nullSentinel ? null : $cached;
        }

        $psr = $this->getPsrRequestViaBearerToken($request);
        $client = $psr ? $this->clients->findActive($psr->getAttribute('oauth_client_id')) : null;
        RequestContext::setValue($contextKey, $client ?? self::$nullSentinel);

        return $client;
    }

    /**
     * Get the validated bearer token for the current request.
     */
    public function token(): ?AccessToken
    {
        if (! RequestContext::has()) {
            return null;
        }

        $request = $this->app->make('request');

        if (! $request->bearerToken() || ! $psr = $this->getPsrRequestViaBearerToken($request)) {
            return null;
        }

        return AccessToken::fromPsrRequest($psr);
    }

    /**
     * Authenticate the incoming request via the Bearer token.
     *
     * @return null|OAuthenticatable
     */
    protected function authenticateViaBearerToken(Request $request, bool $cache = true): ?Authenticatable
    {
        if (! $psr = $this->getPsrRequestViaBearerToken($request, $cache)) {
            return null;
        }

        $client = $this->clients->findActive(
            $psr->getAttribute('oauth_client_id')
        );

        if (! $client) {
            return null;
        }

        $oauthUserId = $psr->getAttribute('oauth_user_id');

        // League uses the client ID for tokens without a user, even if the client's allowed grants later change.
        if ($oauthUserId === null || $oauthUserId === '' || $oauthUserId === $psr->getAttribute('oauth_client_id')) {
            return null;
        }

        $token = AccessToken::fromPsrRequest($psr);

        if ($token->provider !== $this->provider->getProviderName()) {
            return null;
        }

        // If the access token is valid we will retrieve the user according to the user ID
        // associated with the token. We will use the provider implementation which may
        // be used to retrieve users from Eloquent. Next, we'll be ready to continue.
        $user = $this->provider->retrieveById($oauthUserId);

        // Next, we will assign a token instance to this user which the developers may use
        // to determine if the token has a given scope, etc. This will be useful during
        // authorization such as within the developer's Hypervel model policy classes.
        return $user?->withAccessToken($token);
    }

    /**
     * Authenticate and get the incoming PSR-7 request via the Bearer token.
     */
    protected function getPsrRequestViaBearerToken(Request $request, bool $cache = true): ?ServerRequestInterface
    {
        try {
            return $this->servers->validateAuthenticatedRequest($request, $cache);
        } catch (OAuthServerException) {
            // Invalid tokens are expected client errors; leave the header for other guards and do not report them.
            return null;
        }
    }

    /**
     * Authenticate the incoming request via the token cookie.
     *
     * @return null|OAuthenticatable
     */
    protected function authenticateViaCookie(Request $request): ?Authenticatable
    {
        if (! $token = $this->getTokenViaCookie($request)) {
            return null;
        }

        if (! isset($token['provider']) || $token['provider'] !== $this->provider->getProviderName()) {
            return null;
        }

        if (Passport::$cookieBindingCallback && ($token['bind'] ?? null) !== (Passport::$cookieBindingCallback)($request)) {
            return null;
        }

        // If this user exists, we will return this user and attach a "transient" token to
        // the user model. The transient token assumes it has all scopes since the user
        // is physically logged into the application via the application's interface.
        $user = $this->provider->retrieveById($token['sub']);

        return $user?->withAccessToken(new TransientToken);
    }

    /**
     * Get the token cookie via the incoming request.
     *
     * @return null|array<string, mixed>
     */
    protected function getTokenViaCookie(Request $request): ?array
    {
        $cookie = $request->cookie(Passport::cookie());

        // Cookie names using array syntax are parsed into arrays by PHP and Swoole.
        if (! is_string($cookie) || $cookie === '') {
            return null;
        }

        // If we need to retrieve the token from the cookie, it'll be encrypted so we must
        // first decrypt the cookie and then verify the token's signature and expiration.
        // If we can't decrypt or verify the value we'll bail out with a null return.
        try {
            $token = $this->decodeJwtTokenCookie($request);
        } catch (Exception) {
            return null;
        }

        // Legacy expiry-claim cookies are omitted; the decoder validates exp.

        // We will compare the CSRF token in the decoded API token against the CSRF header
        // sent with the request. If they don't match then this request isn't sent from
        // a valid source and we won't authenticate the request for further handling.
        if (! Passport::$ignoreCsrfToken && ! $this->validCsrf($token, $request)) {
            return null;
        }

        return $token;
    }

    /**
     * Decode and decrypt the JWT token cookie.
     *
     * @return array<string, mixed>
     */
    protected function decodeJwtTokenCookie(Request $request): array
    {
        $jwt = $request->cookie(Passport::cookie());

        return (array) JWT::decode(
            Passport::$decryptsCookies
                ? CookieValuePrefix::remove($this->encrypter->decrypt($jwt, Passport::$unserializesCookies))
                : $jwt,
            new Key($this->servers->cookieKey(), 'HS256')
        );
    }

    /**
     * Determine if the CSRF / header are valid and match.
     *
     * @param array<string, mixed> $token
     */
    protected function validCsrf(array $token, Request $request): bool
    {
        $requestToken = $this->getTokenFromRequest($request);

        return isset($token['csrf'])
               && is_string($requestToken)
               && hash_equals($token['csrf'], $requestToken);
    }

    /**
     * Get the CSRF token from the request.
     */
    protected function getTokenFromRequest(Request $request): ?string
    {
        $token = $request->header('X-CSRF-TOKEN');

        if (! $token && $header = $request->header('X-XSRF-TOKEN')) {
            try {
                $token = CookieValuePrefix::remove($this->encrypter->decrypt($header, static::serialized()));
            } catch (DecryptException) {
                $token = null;
            }
        }

        return $token;
    }

    // setRequest() is omitted; RequestContext owns the coroutine's request.

    /**
     * Determine if the cookie contents should be serialized.
     */
    public static function serialized(): bool
    {
        return EncryptCookies::serialized('XSRF-TOKEN');
    }

    /**
     * Set the client for the current request.
     */
    public function setClient(Client $client): static
    {
        CoroutineContext::set($this->getExplicitClientContextKey(), $client);

        return $this;
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        if (CoroutineContext::has($this->getExplicitUserContextKey())) {
            return true;
        }

        if (! RequestContext::has()) {
            return false;
        }

        self::$nullSentinel ??= new stdClass;
        $user = RequestContext::getValue($this->getContextKey($this->app->make('request'), 'user'));

        return $user !== null && $user !== self::$nullSentinel;
    }

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): static
    {
        CoroutineContext::set($this->getExplicitUserContextKey(), $user);

        return $this;
    }

    /**
     * Forget the current user.
     */
    public function forgetUser(): static
    {
        CoroutineContext::forget($this->getExplicitUserContextKey());

        if (RequestContext::has()) {
            RequestContext::forgetValue($this->getContextKey($this->app->make('request'), 'user'));
        }

        return $this;
    }

    /**
     * Get durable authentication context keys.
     *
     * @return list<string>
     */
    public function getAuthContextKeys(): array
    {
        return [$this->getExplicitUserContextKey(), $this->getExplicitClientContextKey()];
    }

    /**
     * Get the context key for an explicitly assigned user.
     */
    protected function getExplicitUserContextKey(): string
    {
        return "__auth.guards.{$this->name}.user.explicit";
    }

    /**
     * Get the context key for an explicitly assigned client.
     */
    protected function getExplicitClientContextKey(): string
    {
        return "__auth.guards.{$this->name}.client.explicit";
    }

    /**
     * Get the context key for credentials resolved in the current request.
     */
    protected function getContextKey(Request $request, string $type): string
    {
        $credentials = $request->bearerToken();

        if (! $credentials) {
            $credentials = serialize([
                $request->cookie(Passport::cookie()),
                $request->header('X-CSRF-TOKEN'),
                $request->header('X-XSRF-TOKEN'),
            ]);
        }

        return "__auth.guards.{$this->name}.{$type}." . hash('xxh128', $credentials);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
