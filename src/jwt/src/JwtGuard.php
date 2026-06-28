<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Closure;
use Hypervel\Auth\Events\Attempting;
use Hypervel\Auth\Events\Authenticated;
use Hypervel\Auth\Events\Failed;
use Hypervel\Auth\Events\Login;
use Hypervel\Auth\Events\Logout;
use Hypervel\Auth\Events\Validated;
use Hypervel\Auth\GuardHelpers;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\Exceptions\JWTException;
use Hypervel\JWT\Exceptions\TokenBlacklistedException;
use Hypervel\JWT\Exceptions\TokenExpiredException;
use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\JWT\Exceptions\UserNotDefinedException;
use Hypervel\JWT\Http\Parser\Parser;
use Hypervel\Support\Traits\Macroable;
use stdClass;

class JwtGuard implements Guard
{
    use GuardHelpers;
    use Macroable;

    protected const string GUARD_CONTEXT_KEY_PREFIX = '__auth.guards.';

    private const string NO_EXPIRY = '__jwt.ttl.no_expiry';

    /**
     * Sentinel value indicating "user was resolved but not found".
     */
    private static object $nullUserSentinel;

    /**
     * The event dispatcher instance.
     */
    protected ?Dispatcher $events = null;

    /**
     * Create a new JWT authentication guard.
     *
     * @param null|int $ttl token time-to-live in minutes, or null for no expiration
     */
    public function __construct(
        protected string $name,
        UserProvider $provider,
        protected ManagerContract $jwtManager,
        protected ClaimFactory $claimFactory,
        protected Parser $parser,
        protected Container $app,
        protected ?int $ttl = 120,
    ) {
        $this->provider = $provider;
    }

    /**
     * Attempt to authenticate a user using the given credentials.
     */
    public function attempt(array $credentials = [], bool $login = true): string|bool
    {
        $this->fireAttemptEvent($credentials);

        $user = $this->provider->retrieveByCredentials($credentials);
        $this->setContextState('lastAttempted', $user);

        if ($user !== null && $this->provider->validateCredentials($user, $credentials)) {
            $this->fireValidatedEvent($user);

            return $login ? $this->login($user) : true;
        }

        $this->fireFailedEvent($user, $credentials);

        return false;
    }

    /**
     * Parse the JWT token from the current request.
     */
    public function parseToken(): ?string
    {
        if (! RequestContext::has()) {
            return null;
        }

        return $this->parser->parseToken($this->app->make('request'));
    }

    /**
     * Log a user into the application and return the JWT token.
     */
    public function login(AuthenticatableContract $user): string
    {
        $token = $this->makeTokenForUser($user);

        $this->setToken($token);
        $this->setUser($user);
        $this->fireLoginEvent($user);

        return $token;
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?AuthenticatableContract
    {
        self::$nullUserSentinel ??= new stdClass;

        $token = $this->getToken();
        $contextKey = $this->getUserContextKey($token);
        $cached = CoroutineContext::get($contextKey);

        if ($cached === self::$nullUserSentinel) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        if (! $token) {
            CoroutineContext::set($contextKey, self::$nullUserSentinel);

            return null;
        }

        try {
            $payload = $this->decodeToken($token);
        } catch (TokenInvalidException|TokenExpiredException|TokenBlacklistedException) {
            CoroutineContext::set($contextKey, self::$nullUserSentinel);

            return null;
        }

        $sub = $this->claimFactory->subjectMatchesProvider($payload, $this->provider)
            ? ($payload['sub'] ?? null)
            : null;

        $user = $sub !== null ? $this->provider->retrieveById($sub) : null;

        if ($user === null) {
            CoroutineContext::set($contextKey, self::$nullUserSentinel);

            return null;
        }

        $this->setUser($user);

        return $user;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        return (bool) $this->attempt($credentials, false);
    }

    /**
     * Log a user into the application using their credentials without persisting.
     */
    public function once(array $credentials = []): bool
    {
        if ($this->validate($credentials) && $user = $this->getLastAttempted()) {
            $this->setUser($user);

            return true;
        }

        return false;
    }

    /**
     * Log the given user ID into the application.
     */
    public function onceUsingId(mixed $id): AuthenticatableContract|false
    {
        if ($user = $this->provider->retrieveById($id)) {
            $this->setUser($user);

            return $user;
        }

        return false;
    }

    /**
     * Create a new token by user ID.
     */
    public function tokenById(mixed $id): ?string
    {
        if (! $user = $this->provider->retrieveById($id)) {
            return null;
        }

        return $this->makeTokenForUser($user);
    }

    /**
     * Alias for onceUsingId.
     */
    public function byId(mixed $id): AuthenticatableContract|false
    {
        return $this->onceUsingId($id);
    }

    /**
     * Get the currently authenticated user or throw an exception.
     *
     * @throws UserNotDefinedException
     */
    public function userOrFail(): AuthenticatableContract
    {
        if (! $user = $this->user()) {
            throw new UserNotDefinedException;
        }

        return $user;
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function getUserId(): int|string|null
    {
        if ($user = $this->cachedUser()) {
            return $user->getAuthIdentifier();
        }

        try {
            $payload = $this->getPayload();
        } catch (TokenInvalidException|TokenExpiredException|TokenBlacklistedException) {
            return null;
        }

        if (! $this->claimFactory->subjectMatchesProvider($payload, $this->provider)) {
            return null;
        }

        return $payload['sub'] ?? null;
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id(): int|string|null
    {
        return $this->getUserId();
    }

    /**
     * Add custom claims to the next JWT token.
     */
    public function claims(array $claims): static
    {
        $contextKey = $this->getContextStateKey('claims');
        $existing = CoroutineContext::get($contextKey, []);

        CoroutineContext::set($contextKey, array_merge($existing, $claims));

        return $this;
    }

    /**
     * Get the payload from the current JWT token.
     */
    public function getPayload(): array
    {
        $token = $this->getToken();

        if (! $token) {
            return [];
        }

        return $this->decodeToken($token);
    }

    /**
     * Alias for getPayload.
     */
    public function payload(): array
    {
        return $this->getPayload();
    }

    /**
     * Set the token.
     */
    public function setToken(string $token): static
    {
        $this->setContextState('token', $token);

        return $this;
    }

    /**
     * Get the current token.
     */
    public function getToken(): ?string
    {
        $token = $this->getContextState('token');

        return is_string($token) && $token !== '' ? $token : $this->parseToken();
    }

    /**
     * Get the token TTL.
     */
    public function getTTL(): ?int
    {
        $ttl = $this->getContextState('ttl');

        if ($ttl === null) {
            return $this->ttl;
        }

        return $ttl === self::NO_EXPIRY ? null : (int) $ttl;
    }

    /**
     * Set the token TTL for the next token-producing operation.
     */
    public function setTTL(?int $ttl): static
    {
        $this->setContextState('ttl', $ttl ?? self::NO_EXPIRY);

        return $this;
    }

    /**
     * Refresh the current JWT token.
     */
    public function refresh(bool $forceForever = false, bool $resetClaims = false): ?string
    {
        if (! $token = $this->getToken()) {
            return null;
        }

        $cachedUser = $this->cachedUser();
        $customClaims = $this->pullCustomClaims();
        $ttl = $this->getTTL();

        try {
            $newToken = $this->jwtManager->refresh($token, $forceForever, $resetClaims, $customClaims, $ttl);
        } finally {
            $this->forgetContextState('ttl');
            $this->forgetUser();
            CoroutineContext::forget($this->getPayloadContextKey($token));
        }

        $this->setToken($newToken);

        if ($cachedUser !== null) {
            $this->cacheUser($cachedUser);
        }

        return $newToken;
    }

    /**
     * Log the user out by invalidating the current token.
     */
    public function logout(bool $forceForever = false): void
    {
        $user = $this->cachedUser();
        $token = $this->getToken();

        $this->forgetUser();
        $this->forgetContextState('token');

        if ($token) {
            CoroutineContext::forget($this->getPayloadContextKey($token));

            if ($this->jwtManager->hasBlacklistEnabled()) {
                $this->jwtManager->invalidate($token, $forceForever);
            }
        }

        $this->fireLogoutEvent($user);
    }

    /**
     * Invalidate the current token.
     */
    public function invalidate(bool $forceForever = false): static
    {
        $this->jwtManager->invalidate($this->requireToken(), $forceForever);

        return $this;
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        self::$nullUserSentinel ??= new stdClass;

        $cached = CoroutineContext::get($this->getUserContextKey());

        return $cached !== null && $cached !== self::$nullUserSentinel;
    }

    /**
     * Set the current user.
     */
    public function setUser(AuthenticatableContract $user): static
    {
        $this->cacheUser($user);
        $this->fireAuthenticatedEvent($user);

        return $this;
    }

    /**
     * Forget the current user.
     */
    public function forgetUser(): static
    {
        CoroutineContext::forget($this->getUserContextKey());

        return $this;
    }

    /**
     * Register an authentication attempt event listener.
     */
    public function attempting(callable $callback): void
    {
        $this->events?->listen(Attempting::class, $callback);
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getDispatcher(): ?Dispatcher
    {
        return $this->events;
    }

    /**
     * Set the event dispatcher instance.
     */
    public function setDispatcher(Dispatcher $events): void
    {
        $this->events = $events;
    }

    /**
     * Get the last user we attempted to authenticate.
     */
    public function getLastAttempted(): ?AuthenticatableContract
    {
        return $this->getContextState('lastAttempted');
    }

    /**
     * Create a token for the given user.
     */
    protected function makeTokenForUser(AuthenticatableContract $user): string
    {
        $ttl = $this->getTTL();

        try {
            return $this->jwtManager->encode($this->claimFactory->make(
                user: $user,
                provider: $this->provider,
                ttl: $ttl,
                customClaims: $this->pullCustomClaims(),
            ));
        } finally {
            $this->forgetContextState('ttl');
        }
    }

    /**
     * Decode a JWT token, caching the result per coroutine.
     */
    protected function decodeToken(string $token): array
    {
        return CoroutineContext::getOrSet(
            $this->getPayloadContextKey($token),
            fn () => $this->jwtManager->decode($token)
        );
    }

    /**
     * Return the currently cached user.
     */
    protected function cachedUser(): ?AuthenticatableContract
    {
        self::$nullUserSentinel ??= new stdClass;

        $cached = CoroutineContext::get($this->getUserContextKey());

        return ($cached === null || $cached === self::$nullUserSentinel) ? null : $cached;
    }

    /**
     * Cache the current user without firing guard events.
     */
    protected function cacheUser(AuthenticatableContract $user): void
    {
        CoroutineContext::set($this->getUserContextKey(), $user);
    }

    /**
     * Pull custom claims for the next token.
     */
    protected function pullCustomClaims(): array
    {
        $contextKey = $this->getContextStateKey('claims');
        $claims = CoroutineContext::get($contextKey, []);
        CoroutineContext::forget($contextKey);

        return $claims;
    }

    /**
     * Require a token to be available.
     *
     * @throws JWTException
     */
    protected function requireToken(): string
    {
        if (! $token = $this->getToken()) {
            throw new JWTException('Token could not be parsed from the request.');
        }

        return $token;
    }

    /**
     * Get Context state.
     */
    protected function getContextState(string $key, mixed $default = null): mixed
    {
        return CoroutineContext::get($this->getContextStateKey($key), $default);
    }

    /**
     * Set Context state.
     */
    protected function setContextState(string $key, mixed $value): void
    {
        CoroutineContext::set($this->getContextStateKey($key), $value);
    }

    /**
     * Forget Context state.
     */
    protected function forgetContextState(string $key): void
    {
        CoroutineContext::forget($this->getContextStateKey($key));
    }

    /**
     * Get a Context state key.
     */
    protected function getContextStateKey(string $key): string
    {
        return static::GUARD_CONTEXT_KEY_PREFIX . $this->name . '.' . $key;
    }

    /**
     * Get the Context key for caching the authenticated user.
     */
    protected function getUserContextKey(?string $token = null): string
    {
        $token ??= $this->getToken();

        if ($token === null || $token === '') {
            return $this->getContextStateKey('user.default');
        }

        return $this->getContextStateKey('user.' . hash('xxh128', $token));
    }

    /**
     * Get the Context key for caching a decoded payload.
     */
    protected function getPayloadContextKey(string $token): string
    {
        return $this->getContextStateKey('payload.' . hash('xxh128', $token));
    }

    /**
     * Dispatch the given event if listeners are registered.
     */
    protected function dispatchIfListening(string $eventClass, Closure $event): void
    {
        if ($this->events?->hasListeners($eventClass)) {
            $this->events->dispatch($event());
        }
    }

    /**
     * Fire the attempt event.
     */
    protected function fireAttemptEvent(array $credentials): void
    {
        $this->dispatchIfListening(Attempting::class, fn () => new Attempting($this->name, $credentials, false));
    }

    /**
     * Fire the validated event.
     */
    protected function fireValidatedEvent(AuthenticatableContract $user): void
    {
        $this->dispatchIfListening(Validated::class, fn () => new Validated($this->name, $user));
    }

    /**
     * Fire the failed authentication attempt event.
     */
    protected function fireFailedEvent(?AuthenticatableContract $user, array $credentials): void
    {
        $this->dispatchIfListening(Failed::class, fn () => new Failed($this->name, $user, $credentials));
    }

    /**
     * Fire the login event.
     */
    protected function fireLoginEvent(AuthenticatableContract $user): void
    {
        $this->dispatchIfListening(Login::class, fn () => new Login($this->name, $user, false));
    }

    /**
     * Fire the authenticated event.
     */
    protected function fireAuthenticatedEvent(AuthenticatableContract $user): void
    {
        $this->dispatchIfListening(Authenticated::class, fn () => new Authenticated($this->name, $user));
    }

    /**
     * Fire the logout event.
     */
    protected function fireLogoutEvent(?AuthenticatableContract $user): void
    {
        $this->dispatchIfListening(Logout::class, fn () => new Logout($this->name, $user));
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
