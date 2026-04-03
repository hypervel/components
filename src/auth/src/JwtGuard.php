<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use stdClass;

class JwtGuard implements Guard
{
    use GuardHelpers;
    use Macroable;

    /**
     * Sentinel value indicating "user was resolved but not found".
     */
    private static object $nullUserSentinel;

    /**
     * Create a new JWT authentication guard.
     *
     * @param int $ttl token time-to-live in minutes
     */
    public function __construct(
        protected string $name,
        UserProvider $provider,
        protected ManagerContract $jwtManager,
        protected Container $app,
        protected int $ttl = 120,
    ) {
        $this->provider = $provider;
    }

    /**
     * Attempt to authenticate a user using the given credentials.
     */
    public function attempt(array $credentials = [], bool $login = true): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        $validated = ! is_null($user) && $this->provider->validateCredentials($user, $credentials);

        if ($validated && $login) {
            $this->login($user);
        }

        return $validated;
    }

    /**
     * Parse the JWT token from the current request.
     */
    public function parseToken(): ?string
    {
        if (! RequestContext::has()) {
            return null;
        }

        $request = $this->app->make('request');

        $header = $request->header('Authorization', '');
        if ($header && Str::startsWith($header, 'Bearer ')) {
            return Str::substr($header, 7);
        }

        if ($request->has('token')) {
            return $request->input('token');
        }

        return null;
    }

    /**
     * Log a user into the application and return the JWT token.
     */
    public function login(AuthenticatableContract $user): string
    {
        $now = Carbon::now();
        $claims = CoroutineContext::get("__auth.guards.{$this->name}.claims", []);
        $token = $this->jwtManager->encode(array_merge([
            'sub' => $user->getAuthIdentifier(),
            'iat' => $now->copy()->timestamp,
            'exp' => $now->copy()->addMinutes($this->ttl)->timestamp,
        ], $claims));

        CoroutineContext::set(
            $this->getContextKeyForToken($this->parseToken() ? $token : null),
            $user
        );

        return $token;
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?AuthenticatableContract
    {
        self::$nullUserSentinel ??= new stdClass();

        $token = $this->parseToken();
        $contextKey = $this->getContextKeyForToken($token);
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

        $user = null;

        $payload = $this->decodeToken($token);
        $sub = $payload['sub'] ?? null;
        $user = $sub ? $this->provider->retrieveById($sub) : null;

        CoroutineContext::set($contextKey, $user ?? self::$nullUserSentinel);

        return $user;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        return $this->attempt($credentials, false);
    }

    /**
     * Add custom claims to the next JWT token.
     */
    public function claims(array $claims): static
    {
        $contextKey = "__auth.guards.{$this->name}.claims";
        if ($contextClaims = CoroutineContext::get($contextKey)) {
            $claims = array_merge($contextClaims, $claims);
        }

        CoroutineContext::set($contextKey, $claims);

        return $this;
    }

    /**
     * Get the payload from the current JWT token.
     */
    public function getPayload(): array
    {
        $token = $this->parseToken();

        if (! $token) {
            return [];
        }

        return $this->decodeToken($token);
    }

    /**
     * Decode a JWT token, caching the result per-request.
     *
     * Avoids decoding the same token multiple times when both user()
     * and getPayload() are called in the same request.
     */
    protected function decodeToken(string $token): array
    {
        $contextKey = "__auth.guards.{$this->name}.payload." . md5($token);

        return CoroutineContext::getOrSet($contextKey, fn () => $this->jwtManager->decode($token));
    }

    /**
     * Refresh the current JWT token.
     */
    public function refresh(): ?string
    {
        if (! $token = $this->parseToken()) {
            return null;
        }

        CoroutineContext::forget($this->getContextKeyForToken($token));

        return $this->jwtManager->refresh($token);
    }

    /**
     * Log a user into the application using their credentials without persisting.
     */
    public function once(array $credentials = []): bool
    {
        return $this->attempt($credentials, true);
    }

    /**
     * Log the given user ID into the application.
     */
    public function onceUsingId(mixed $id): AuthenticatableContract|bool
    {
        if ($user = $this->provider->retrieveById($id)) {
            $this->login($user);

            return true;
        }

        return false;
    }

    /**
     * Log the user out by invalidating the current token.
     */
    public function logout(): void
    {
        $token = $this->parseToken();

        CoroutineContext::forget($this->getContextKeyForToken($token));

        if ($token) {
            $this->jwtManager->invalidate($token);
        }
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        self::$nullUserSentinel ??= new stdClass();

        $cached = CoroutineContext::get($this->getContextKeyForToken($this->parseToken()));

        return $cached !== null && $cached !== self::$nullUserSentinel;
    }

    /**
     * Set the current user.
     */
    public function setUser(AuthenticatableContract $user): static
    {
        CoroutineContext::set($this->getContextKeyForToken($this->parseToken()), $user);

        return $this;
    }

    /**
     * Forget the current user.
     */
    public function forgetUser(): static
    {
        CoroutineContext::forget($this->getContextKeyForToken($this->parseToken()));

        return $this;
    }

    /**
     * Get the Context key for caching the authenticated user, keyed by token.
     */
    protected function getContextKeyForToken(?string $token): string
    {
        if ($token === null || $token === '') {
            return "__auth.guards.{$this->name}.user.default";
        }

        return "__auth.guards.{$this->name}.user." . md5($token);
    }
}
