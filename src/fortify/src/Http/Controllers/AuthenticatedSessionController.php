<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Contracts\Container\Container;
use Hypervel\Fortify\Actions\AttemptToAuthenticate;
use Hypervel\Fortify\Actions\CanonicalizeUsername;
use Hypervel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Hypervel\Fortify\Actions\PrepareAuthenticatedSession;
use Hypervel\Fortify\Contracts\LoginResponse;
use Hypervel\Fortify\Contracts\LoginViewResponse;
use Hypervel\Fortify\Contracts\LogoutResponse;
use Hypervel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Hypervel\Fortify\Features;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\Http\Requests\LoginRequest;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Routing\Pipeline;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {
    }

    /**
     * Show the login view.
     */
    public function create(Request $request): LoginViewResponse
    {
        return $this->container->make(LoginViewResponse::class);
    }

    /**
     * Attempt to authenticate a new session.
     */
    public function store(LoginRequest $request): mixed
    {
        return $this->loginPipeline($request)->then(
            fn (): LoginResponse => $this->container->make(LoginResponse::class),
        );
    }

    /**
     * Get the authentication pipeline instance.
     */
    protected function loginPipeline(LoginRequest $request): Pipeline
    {
        $customPipeline = Fortify::authenticateThroughCallback();
        $configuredPipeline = $this->config->get('fortify.pipelines.login');
        $limiter = $this->config->get('fortify.limiters.login');
        $lowercaseUsernames = $this->config->boolean('fortify.lowercase_usernames');

        if ($customPipeline !== null) {
            return (new Pipeline($this->container))->send($request)->through(array_filter($customPipeline($request)));
        }

        if (is_array($configuredPipeline)) {
            return (new Pipeline($this->container))->send($request)->through(array_filter($configuredPipeline));
        }

        return (new Pipeline($this->container))->send($request)->through(array_filter([
            $limiter ? null : EnsureLoginIsNotThrottled::class,
            $lowercaseUsernames ? CanonicalizeUsername::class : null,
            Features::enabled(Features::twoFactorAuthentication()) ? RedirectsIfTwoFactorAuthenticatable::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): LogoutResponse
    {
        Fortify::guard()->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->container->make(LogoutResponse::class);
    }
}
