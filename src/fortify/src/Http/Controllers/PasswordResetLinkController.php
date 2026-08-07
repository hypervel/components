<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\PasswordBroker;
use Hypervel\Contracts\Config\Repository as Config;
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
    /**
     * Show the reset password link request view.
     */
    public function create(Request $request): RequestPasswordResetLinkViewResponse
    {
        return app(RequestPasswordResetLinkViewResponse::class);
    }

    /**
     * Send a reset link to the given user.
     */
    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        $config = app(Config::class);

        if ($config->boolean('fortify.lowercase_usernames') && $request->has(Fortify::email())) {
            $request->merge([
                Fortify::email() => Str::lower((string) $request->{Fortify::email()}),
            ]);
        }

        $status = $this->broker()->sendResetLink($request->only(Fortify::email()));

        return $status === Password::RESET_LINK_SENT
            ? app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => $status])
            : app(FailedPasswordResetLinkRequestResponse::class, ['status' => $status]);
    }

    /**
     * Get the broker to be used during password reset.
     */
    protected function broker(): PasswordBroker
    {
        return Password::broker();
    }
}
