<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\PasswordBroker;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Hypervel\Fortify\Contracts\RequestPasswordResetLinkViewResponse;
use Hypervel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\Http\Requests\SendPasswordResetLinkRequest;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Support\Facades\Password;
use Hypervel\Support\Str;

class PasswordResetLinkController extends Controller
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {
    }

    /**
     * Show the reset password link request view.
     */
    public function create(Request $request): RequestPasswordResetLinkViewResponse
    {
        return $this->container->make(RequestPasswordResetLinkViewResponse::class);
    }

    /**
     * Send a reset link to the given user.
     */
    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        if ($this->config->boolean('fortify.lowercase_usernames', false) && $request->has(Fortify::email())) {
            $request->merge([
                Fortify::email() => Str::lower((string) $request->{Fortify::email()}),
            ]);
        }

        $status = $this->broker()->sendResetLink($request->only(Fortify::email()));

        return $status === Password::RESET_LINK_SENT
            ? $this->container->make(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => $status])
            : $this->container->make(FailedPasswordResetLinkRequestResponse::class, ['status' => $status]);
    }

    /**
     * Get the broker to be used during password reset.
     */
    protected function broker(): PasswordBroker
    {
        return Password::broker();
    }
}
