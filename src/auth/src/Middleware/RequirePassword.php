<?php

declare(strict_types=1);

namespace Hypervel\Auth\Middleware;

use Closure;
use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

class RequirePassword
{
    /**
     * The password timeout in seconds.
     */
    protected int $passwordTimeout;

    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ResponseFactory $responseFactory,
        protected UrlGenerator $urlGenerator,
        ?int $passwordTimeout = null,
    ) {
        $this->passwordTimeout = $passwordTimeout ?: 10800;
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
     */
    protected function shouldConfirmPassword(Request $request, string|int|null $passwordTimeoutSeconds = null): bool
    {
        $confirmedAt = Date::now()->unix() - $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt > ($passwordTimeoutSeconds ?? $this->passwordTimeout);
    }
}
