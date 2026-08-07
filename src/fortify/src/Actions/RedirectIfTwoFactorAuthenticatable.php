<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Closure;
use Hypervel\Auth\Events\Failed;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationUser;
use Hypervel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Fortify\TwoFactorAuthenticatable;
use Hypervel\Http\Request;
use Hypervel\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfTwoFactorAuthenticatable implements RedirectsIfTwoFactorAuthenticatable
{
    use DispatchesEvents;

    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected LoginRateLimiter $limiter,
        protected readonly Config $config,
    ) {
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->validateCredentials($request);

        if (! in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true)) {
            return $next($request);
        }

        /** @var Authenticatable&Model&TwoFactorAuthenticationUser $user */
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }

    /**
     * Attempt to validate the incoming credentials.
     *
     * @throws ValidationException
     */
    protected function validateCredentials(Request $request): Authenticatable&Model
    {
        if (Fortify::authenticateUsingCallback() instanceof Closure) {
            $callback = Fortify::authenticateUsingCallback();
            $user = $callback($request);

            if (! $user instanceof Authenticatable || ! $user instanceof Model) {
                $this->fireFailedEvent($request);

                $this->throwFailedAuthenticationException($request);
            }

            return $user;
        }

        $provider = $this->provider();
        $user = $provider->retrieveByCredentials($request->only(Fortify::username(), 'password'));

        $password = $request->input('password');

        if (! $user instanceof Authenticatable || ! $user instanceof Model || ! $provider->validateCredentials($user, ['password' => $password])) {
            $this->fireFailedEvent($request, $user);

            $this->throwFailedAuthenticationException($request);
        }

        if ($this->config->boolean('hashing.rehash_on_login')) {
            $provider->rehashPasswordIfRequired($user, ['password' => $password]);
        }

        return $user;
    }

    /**
     * Get the current guard's user provider.
     */
    protected function provider(): UserProvider
    {
        $guard = Fortify::guard();

        if (! method_exists($guard, 'getProvider')) {
            throw new RuntimeException('Fortify password authentication requires a guard with a user provider.');
        }

        $provider = $guard->getProvider(); /* @phpstan-ignore method.notFound (getProvider() is on GuardHelpers, not the guard contract) */

        if (! $provider instanceof UserProvider) {
            throw new RuntimeException('Fortify password authentication requires a guard with a user provider.');
        }

        return $provider;
    }

    /**
     * Throw a failed authentication validation exception.
     *
     * @throws ValidationException
     */
    protected function throwFailedAuthenticationException(Request $request): never
    {
        $this->limiter->increment($request);

        throw ValidationException::withMessages([
            Fortify::username() => [trans('auth.failed')],
        ]);
    }

    /**
     * Fire the failed authentication attempt event with the given arguments.
     */
    protected function fireFailedEvent(Request $request, ?Authenticatable $user = null): void
    {
        $this->dispatchIfListening(
            Failed::class,
            static fn (): Failed => new Failed(Fortify::guardName(), $user, [
                Fortify::username() => $request->{Fortify::username()},
                'password' => $request->input('password'),
            ]),
        );
    }

    /**
     * Get the two factor authentication enabled response.
     */
    protected function twoFactorChallengeResponse(Request $request, Authenticatable&Model $user): Response
    {
        $request->session()->put([
            'login.id' => $user->getKey(),
            'login.remember' => $request->boolean('remember'),
            'login.guard' => Fortify::guardName(),
        ]);

        $this->dispatchIfListening(
            TwoFactorAuthenticationChallenged::class,
            static fn (): TwoFactorAuthenticationChallenged => new TwoFactorAuthenticationChallenged($user),
        );

        return $request->wantsJson()
            ? response()->json(['two_factor' => true])
            : redirect()->route('two-factor.login');
    }
}
