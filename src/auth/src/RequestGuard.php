<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\Traits\Macroable;
use SensitiveParameter;
use stdClass;

class RequestGuard implements Guard
{
    use GuardHelpers;
    use Macroable;

    /**
     * Sentinel value indicating "user was resolved but not found".
     */
    private static object $nullUserSentinel;

    /**
     * The guard callback.
     *
     * @var callable
     */
    protected $callback;

    /**
     * Create a new authentication guard.
     *
     * The $name parameter is a Hypervel addition for coroutine-safe Context
     * keying. Each named guard needs a unique Context key so multiple
     * RequestGuard instances don't collide.
     */
    public function __construct(
        protected string $name,
        callable $callback,
        protected Container $app,
        ?UserProvider $provider = null,
    ) {
        $this->callback = $callback;
        $this->provider = $provider;
    }

    /**
     * Get the currently authenticated user.
     *
     * Uses coroutine Context to cache the resolved user per-request,
     * since this guard is a process-global singleton. A sentinel value
     * is used to cache "no user found" so repeated calls don't trigger
     * redundant provider lookups.
     */
    public function user(): ?AuthenticatableContract
    {
        self::$nullUserSentinel ??= new stdClass;

        /** @var null|AuthenticatableContract $explicitUser */
        $explicitUser = CoroutineContext::get($this->getExplicitUserContextKey());

        if ($explicitUser !== null) {
            return $explicitUser;
        }

        $contextKey = $this->getResolvedUserContextKey();
        $cached = CoroutineContext::get($contextKey);

        if ($cached === self::$nullUserSentinel) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $user = ($this->callback)(
            $this->app->make('request'),
            $this->getProvider()
        );

        CoroutineContext::set($contextKey, $user ?? self::$nullUserSentinel);

        return $user;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(#[SensitiveParameter] array $credentials = []): bool
    {
        return ! is_null(($this->callback)(
            $credentials['request'],
            $this->getProvider()
        ));
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        self::$nullUserSentinel ??= new stdClass;

        if (CoroutineContext::has($this->getExplicitUserContextKey())) {
            return true;
        }

        $cached = CoroutineContext::get($this->getResolvedUserContextKey());

        return $cached !== null && $cached !== self::$nullUserSentinel;
    }

    /**
     * Set the current user.
     */
    public function setUser(AuthenticatableContract $user): static
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
        CoroutineContext::forget($this->getResolvedUserContextKey());

        return $this;
    }

    /**
     * Get durable authentication Context keys.
     *
     * Per-request resolver caches must not cross a request boundary.
     *
     * @return array<int, string>
     */
    public function getAuthContextKeys(): array
    {
        return [$this->getExplicitUserContextKey()];
    }

    /**
     * Get the Context key for an explicitly assigned user.
     */
    protected function getExplicitUserContextKey(): string
    {
        return "__auth.guards.{$this->name}.user.explicit";
    }

    /**
     * Get the Context key for caching the resolved user.
     */
    protected function getResolvedUserContextKey(): string
    {
        return "__auth.guards.{$this->name}.user.resolved";
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
