<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Middleware;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Guards\SessionGuard;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Session\Session;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthenticateSession implements MiddlewareInterface
{
    public function __construct(
        protected AuthFactory $auth,
        protected Session $session
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @throws AuthenticationException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $this->session->isStarted()) {
            return $handler->handle($request);
        }

        $guards = Collection::make(Arr::wrap(config('sanctum.guard')))
            ->mapWithKeys(fn ($guard) => [$guard => $this->auth->guard($guard)])
            ->filter(fn ($guard) => $guard instanceof SessionGuard);

        // Get the authenticated user from the guards
        $user = null;
        foreach ($guards as $guard) {
            if ($guard->check()) {
                $user = $guard->user();
                break;
            }
        }

        if (! $user) {
            return $handler->handle($request);
        }

        $shouldLogout = $guards->filter(
            fn ($guard, $driver) => $this->session->has('password_hash_' . $driver)
        )->filter(
            fn ($guard, $driver) => $this->session->get('password_hash_' . $driver)
                                    !== $user->getAuthPassword()
        );

        if ($shouldLogout->isNotEmpty()) {
            $shouldLogout->each(function ($guard) {
                if (method_exists($guard, 'logout')) {
                    $guard->logout();
                }
            });

            $this->session->flush();

            throw new AuthenticationException('Unauthenticated.', [...$shouldLogout->keys()->all(), 'sanctum']);
        }

        // Store password hash after successful request
        $response = $handler->handle($request);

        if (! is_null($guard = $this->getFirstGuardWithUser($guards->keys()))) {
            $this->storePasswordHashInSession($guard);
        }

        return $response;
    }

    /**
     * Get the first authentication guard that has a user.
     */
    protected function getFirstGuardWithUser(Collection $guards): ?string
    {
        return $guards->first(function ($guard) {
            $guardInstance = $this->auth->guard($guard);

            return method_exists($guardInstance, 'hasUser')
                   && $guardInstance->hasUser();
        });
    }

    /**
     * Store the user's current password hash in the session.
     */
    protected function storePasswordHashInSession(string $guard): void
    {
        $this->session->put([
            "password_hash_{$guard}" => $this->auth->guard($guard)->user()->getAuthPassword(),
        ]);
    }
}
