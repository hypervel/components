<?php

declare(strict_types=1);

namespace Hypervel\Auth\Middleware;

use Closure;
use Hypervel\Auth\PasswordConfirmation;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

class RequirePassword
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ResponseFactory $responseFactory,
        protected UrlGenerator $urlGenerator,
        protected AuthFactory $auth,
        protected Repository $config,
    ) {
    }

    /**
     * Specify the redirect route and timeout for the middleware.
     */
    public static function using(?string $redirectToRoute = null, string|int|null $passwordTimeoutSeconds = null): string
    {
        return static::class . ':' . implode(',', func_get_args());
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null, string|int|null $passwordTimeoutSeconds = null): Response
    {
        if ($this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            if ($request->expectsJson()) {
                return $this->responseFactory->json([
                    'message' => 'Password confirmation required.',
                ], 423);
            }

            return $this->responseFactory->redirectGuest(
                $this->urlGenerator->route($redirectToRoute ?: 'password.confirm')
            );
        }

        return $next($request);
    }

    /**
     * Determine if the confirmation timeout has expired.
     *
     * The confirmation timestamp and timeout are scoped to the current
     * guard, so confirming a password under one guard never satisfies
     * password confirmation under another.
     */
    protected function shouldConfirmPassword(Request $request, string|int|null $passwordTimeoutSeconds = null): bool
    {
        $guard = $this->auth->getDefaultDriver();
        $confirmedAt = Date::now()->unix() - $request->session()->get(PasswordConfirmation::sessionKey($guard), 0);

        return $confirmedAt > PasswordConfirmation::timeout($this->config, $guard, $passwordTimeoutSeconds);
    }
}
