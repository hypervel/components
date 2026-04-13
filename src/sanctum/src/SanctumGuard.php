<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Auth\GuardHelpers;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard as GuardContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Sanctum\Events\TokenAuthenticated;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;
use stdClass;

/**
 * Sanctum authentication guard.
 *
 * Implements GuardContract directly instead of using Laravel's RequestGuard
 * wrapper. This is intentional: RequestGuard stores user state on $this->user
 * which is process-global and unsafe under Swoole. This guard uses coroutine
 * Context for per-request user caching, keyed by token fingerprint.
 *
 * Token lookup and tokenable resolution are delegated to PersonalAccessToken,
 * which owns all cache logic (token caching, tokenable caching, last_used_at
 * write throttling). This keeps caching co-located with the model rather than
 * split across the guard and model.
 */
class SanctumGuard implements GuardContract
{
    use GuardHelpers;
    use Macroable;

    /**
     * Sentinel value indicating "user was resolved but not found".
     */
    private static object $nullUserSentinel;

    /**
     * Create a new guard instance.
     */
    public function __construct(
        protected string $name,
        UserProvider $provider,
        protected Container $app,
        protected ?Dispatcher $events = null,
        protected ?int $expiration = null,
    ) {
        $this->provider = $provider;
    }

    /**
     * Get the currently authenticated user.
     *
     * Uses coroutine Context to cache the resolved user per-request,
     * keyed by token fingerprint. A sentinel value caches "no user
     * found" so repeated calls don't trigger redundant lookups.
     */
    public function user(): ?Authenticatable
    {
        self::$nullUserSentinel ??= new stdClass;

        $token = $this->getTokenFromRequest();
        $contextKey = $this->getContextKeyForToken($token);
        $cached = CoroutineContext::get($contextKey);

        if ($cached === self::$nullUserSentinel) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        // Check stateful guards first (like 'web')
        $authFactory = $this->app->make('auth');
        foreach (Arr::wrap(config('sanctum.guard', 'web')) as $guard) {
            if ($guard !== $this->name && $authFactory->guard($guard)->check()) {
                $user = $authFactory->guard($guard)->user();
                if ($this->supportsTokens($user)) {
                    /** @var Authenticatable&\Hypervel\Sanctum\Contracts\HasApiTokens $tokenUser */
                    $tokenUser = $user;
                    $user = $tokenUser->withAccessToken(new TransientToken);
                }
                CoroutineContext::set($contextKey, $user ?? self::$nullUserSentinel);

                return $user;
            }
        }

        // Check for token authentication
        if ($token) {
            $model = Sanctum::$personalAccessTokenModel;
            $accessToken = $model::findToken($token);

            if ($this->isValidAccessToken($accessToken)) {
                $tokenable = $model::findTokenable($accessToken);

                if ($this->supportsTokens($tokenable)) {
                    /** @var Authenticatable&\Hypervel\Sanctum\Contracts\HasApiTokens $tokenable */
                    $user = $tokenable->withAccessToken($accessToken);

                    if ($this->events?->hasListeners(TokenAuthenticated::class)) {
                        $this->events->dispatch(new TokenAuthenticated($accessToken));
                    }

                    CoroutineContext::set($contextKey, $user);

                    return $user;
                }
            }
        }

        CoroutineContext::set($contextKey, self::$nullUserSentinel);

        return null;
    }

    /**
     * Determine if the tokenable model supports API tokens.
     */
    protected function supportsTokens(?Authenticatable $tokenable = null): bool
    {
        return $tokenable && in_array(HasApiTokens::class, class_uses_recursive(
            get_class($tokenable)
        ));
    }

    /**
     * Get the token from the request.
     */
    protected function getTokenFromRequest(): ?string
    {
        // Prevent nullable request
        if (! RequestContext::has()) {
            return null;
        }

        $request = $this->app->make('request');

        if (is_callable(Sanctum::$accessTokenRetrievalCallback)) {
            return (string) (Sanctum::$accessTokenRetrievalCallback)($request);
        }

        $token = $this->getBearerToken($request);

        return $this->isValidBearerToken($token) ? $token : null;
    }

    /**
     * Get the bearer token from the request headers.
     */
    protected function getBearerToken(mixed $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Check for token in request input as fallback
        if ($request->has('token')) {
            return $request->input('token');
        }

        return null;
    }

    /**
     * Determine if the bearer token is in the correct format.
     */
    protected function isValidBearerToken(?string $token = null): bool
    {
        if (! is_null($token) && str_contains($token, '|')) {
            $model = new (Sanctum::$personalAccessTokenModel)();

            // @phpstan-ignore function.alreadyNarrowedType (custom token models may not extend Model)
            if (method_exists($model, 'getKeyType') && $model->getKeyType() === 'int') {
                [$id, $token] = explode('|', $token, 2);

                return ctype_digit($id) && ! empty($token);
            }
        }

        return ! empty($token);
    }

    /**
     * Determine if the provided access token is valid.
     */
    protected function isValidAccessToken(?PersonalAccessToken $accessToken): bool
    {
        if (! $accessToken) {
            return false;
        }

        $isValid
            = (! $this->expiration || $accessToken->getAttribute('created_at')->gt(now()->subMinutes($this->expiration)))
            && (! $accessToken->getAttribute('expires_at') || ! $accessToken->getAttribute('expires_at')->isPast())
            && $this->hasValidProvider($accessToken->getAttribute('tokenable'));

        if (is_callable(Sanctum::$accessTokenAuthenticationCallback)) {
            $isValid = (bool) (Sanctum::$accessTokenAuthenticationCallback)($accessToken, $isValid);
        }

        return $isValid;
    }

    /**
     * Determine if the tokenable model matches the provider's model type.
     */
    protected function hasValidProvider(?Authenticatable $tokenable): bool
    {
        if (! method_exists($this->provider, 'getModel')) {
            return true;
        }

        $model = $this->provider->getModel();

        return $tokenable instanceof $model;
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        self::$nullUserSentinel ??= new stdClass;

        $cached = CoroutineContext::get($this->getContextKeyForToken($this->getTokenFromRequest()));

        return $cached !== null && $cached !== self::$nullUserSentinel;
    }

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): static
    {
        CoroutineContext::set($this->getContextKeyForToken($this->getTokenFromRequest()), $user);

        return $this;
    }

    /**
     * Forget the current user.
     */
    public function forgetUser(): static
    {
        CoroutineContext::forget($this->getContextKeyForToken($this->getTokenFromRequest()));

        return $this;
    }

    /**
     * Validate a user's credentials (not supported for token-based auth).
     */
    public function validate(array $credentials = []): bool
    {
        return false;
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
