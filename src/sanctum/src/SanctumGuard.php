<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\HttpServer\Contracts\RequestInterface;
use Hypervel\Auth\Guards\GuardHelpers;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard as GuardContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Sanctum\Events\TokenAuthenticated;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;

class SanctumGuard implements GuardContract
{
    use GuardHelpers;
    use Macroable;

    /**
     * The currently authenticated user.
     */
    protected ?Authenticatable $user = null;

    /**
     * Create a new guard instance.
     */
    public function __construct(
        protected string $name,
        protected UserProvider $provider,
        protected RequestInterface $request,
        protected ?Dispatcher $events = null,
        protected ?int $expiration = null
    ) {
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable
    {
        // Check context cache first
        if (Context::has($contextKey = $this->getContextKey())) {
            return Context::get($contextKey);
        }

        // Check stateful guards first (like 'web')
        $authFactory = Container::getInstance()->make(AuthFactory::class);
        foreach (Arr::wrap(config('sanctum.guard', 'web')) as $guard) {
            if ($guard !== $this->name && $authFactory->guard($guard)->check()) {
                $user = $authFactory->guard($guard)->user();
                if ($this->supportsTokens($user)) {
                    /** @var \Hypervel\Contracts\Auth\Authenticatable&\Hypervel\Sanctum\Contracts\HasApiTokens $tokenUser */
                    $tokenUser = $user;
                    $user = $tokenUser->withAccessToken(new TransientToken());
                }
                Context::set($contextKey, $user);
                return $user;
            }
        }

        // Check for token authentication
        if ($token = $this->getTokenFromRequest()) {
            $model = Sanctum::$personalAccessTokenModel;
            $accessToken = $model::findToken($token);

            if ($this->isValidAccessToken($accessToken)) {
                $tokenable = $model::findTokenable($accessToken);

                if ($this->supportsTokens($tokenable)) {
                    /** @var \Hypervel\Contracts\Auth\Authenticatable&\Hypervel\Sanctum\Contracts\HasApiTokens $tokenable */
                    $user = $tokenable->withAccessToken($accessToken);

                    // Dispatch event if event dispatcher is available
                    if ($this->events) {
                        $this->events->dispatch(new TokenAuthenticated($accessToken));
                    }

                    Context::set($contextKey, $user);
                    return $user;
                }
            }
        }

        Context::set($contextKey, null);
        return null;
    }

    /**
     * Get the context key for caching.
     */
    protected function getContextKey(): string
    {
        $token = $this->getTokenFromRequest();
        $suffix = $token ? md5($token) : 'default';
        return "__auth.guards.{$this->name}.result.{$suffix}";
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

        if (is_callable(Sanctum::$accessTokenRetrievalCallback)) {
            return (string) (Sanctum::$accessTokenRetrievalCallback)($this->request);
        }

        $token = $this->getBearerToken();

        return $this->isValidBearerToken($token) ? $token : null;
    }

    /**
     * Get the bearer token from the request headers.
     */
    protected function getBearerToken(): ?string
    {
        $header = $this->request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Check for token in request input as fallback
        if ($this->request->has('token')) {
            return $this->request->input('token');
        }

        return null;
    }

    /**
     * Determine if the bearer token is in the correct format.
     */
    protected function isValidBearerToken(?string $token = null): bool
    {
        if (! is_null($token) && str_contains($token, '|')) {
            $model = new (Sanctum::$personalAccessTokenModel);

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
     * Set the current user.
     */
    public function setUser(Authenticatable $user): void
    {
        Context::set($this->getContextKey(), $user);
    }

    /**
     * Attempt to authenticate (not supported for token-based auth).
     */
    public function attempt(array $credentials = [], bool $login = true): bool
    {
        return false;
    }

    /**
     * Validate a user's credentials (not supported for token-based auth).
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }
}
