<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\Traits\Macroable;
use stdClass;

class TokenGuard implements Guard
{
    use GuardHelpers;
    use Macroable;

    /**
     * Sentinel value indicating "user was resolved but not found".
     */
    private static object $nullUserSentinel;

    /**
     * Create a new authentication guard.
     *
     * @param string $name the name of the guard for Context keying
     * @param string $inputKey the name of the query string item from the request containing the API token
     * @param string $storageKey the name of the token "column" in persistent storage
     * @param bool $hash indicates if the API token is hashed in storage
     */
    public function __construct(
        protected string $name,
        UserProvider $provider,
        protected Container $app,
        protected string $inputKey = 'api_token',
        protected string $storageKey = 'api_token',
        protected bool $hash = false,
    ) {
        $this->provider = $provider;
    }

    /**
     * Get the currently authenticated user.
     *
     * Uses coroutine Context to cache the resolved user per-request,
     * keyed by token fingerprint so different tokens don't collide.
     * A sentinel value caches "no user found" so repeated calls
     * don't trigger redundant provider lookups.
     */
    public function user(): ?AuthenticatableContract
    {
        self::$nullUserSentinel ??= new stdClass();

        $token = $this->getTokenForRequest();
        $contextKey = $this->getContextKeyForToken($token);
        $cached = CoroutineContext::get($contextKey);

        if ($cached === self::$nullUserSentinel) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $user = null;

        if ($token !== null && $token !== '') {
            $user = $this->provider->retrieveByCredentials([
                $this->storageKey => $this->hash ? hash('sha256', $token) : $token,
            ]);
        }

        CoroutineContext::set($contextKey, $user ?? self::$nullUserSentinel);

        return $user;
    }

    /**
     * Get the token for the current request.
     */
    public function getTokenForRequest(): ?string
    {
        $request = $this->app->make('request');

        $token = $request->query($this->inputKey);

        if (empty($token)) {
            $token = $request->input($this->inputKey);
        }

        if (empty($token)) {
            $token = $request->bearerToken();
        }

        if (empty($token)) {
            $token = $request->getPassword();
        }

        return $token;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        if (empty($credentials[$this->inputKey])) {
            return false;
        }

        $credentials = [$this->storageKey => $credentials[$this->inputKey]];

        return (bool) $this->provider->retrieveByCredentials($credentials);
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        self::$nullUserSentinel ??= new stdClass();

        $cached = CoroutineContext::get($this->getContextKeyForToken($this->getTokenForRequest()));

        return $cached !== null && $cached !== self::$nullUserSentinel;
    }

    /**
     * Set the current user.
     */
    public function setUser(AuthenticatableContract $user): static
    {
        CoroutineContext::set($this->getContextKeyForToken($this->getTokenForRequest()), $user);

        return $this;
    }

    /**
     * Forget the current user.
     */
    public function forgetUser(): static
    {
        CoroutineContext::forget($this->getContextKeyForToken($this->getTokenForRequest()));

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
