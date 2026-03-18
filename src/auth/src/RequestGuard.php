<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Context\Context;
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
        self::$nullUserSentinel ??= new stdClass();

        $contextKey = $this->getContextKey();
        $cached = Context::get($contextKey);

        if ($cached === self::$nullUserSentinel) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $user = call_user_func(
            $this->callback,
            $this->app->make('request'),
            $this->getProvider()
        );

        Context::set($contextKey, $user ?? self::$nullUserSentinel);

        return $user;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(#[SensitiveParameter] array $credentials = []): bool
    {
        return ! is_null(call_user_func(
            $this->callback,
            $credentials['request'],
            $this->getProvider()
        ));
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        self::$nullUserSentinel ??= new stdClass();

        $cached = Context::get($this->getContextKey());

        return $cached !== null && $cached !== self::$nullUserSentinel;
    }

    /**
     * Set the current user.
     */
    public function setUser(AuthenticatableContract $user): static
    {
        Context::set($this->getContextKey(), $user);

        return $this;
    }

    /**
     * Forget the current user.
     */
    public function forgetUser(): static
    {
        Context::forget($this->getContextKey());

        return $this;
    }

    /**
     * Get the Context key for caching the authenticated user.
     */
    protected function getContextKey(): string
    {
        return "__auth.guards.{$this->name}.user";
    }
}
