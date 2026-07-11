<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Actions\CompletePasswordReset;
use Hypervel\Fortify\Contracts\FailedPasswordResetResponse;
use Hypervel\Fortify\Contracts\PasswordResetResponse;
use Hypervel\Fortify\Contracts\ResetPasswordViewResponse;
use Hypervel\Fortify\Contracts\ResetsUserPasswords;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Support\Facades\Password;
use Hypervel\Support\Str;
use RuntimeException;

class NewPasswordController extends Controller
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {
    }

    /**
     * Show the new password view.
     */
    public function create(Request $request): ResetPasswordViewResponse
    {
        return $this->container->make(ResetPasswordViewResponse::class);
    }

    /**
     * Reset the user's password.
     */
    public function store(Request $request): Responsable
    {
        if ($this->config->boolean('fortify.lowercase_usernames', false) && $request->has(Fortify::email())) {
            $request->merge([
                Fortify::email() => Str::lower((string) $request->{Fortify::email()}),
            ]);
        }

        $request->validate([
            'token' => 'required',
            Fortify::email() => 'required|email',
            'password' => 'required',
        ]);

        $status = $this->broker()->reset(
            $request->only(Fortify::email(), 'password', 'password_confirmation', 'token'),
            function ($user) use ($request): void {
                if (! $user instanceof Authenticatable || ! $user instanceof CanResetPassword || ! $user instanceof Model) {
                    throw new RuntimeException('Fortify password resets require an authenticatable Eloquent model.');
                }

                $this->container->make(ResetsUserPasswords::class)->reset($user, $request->all());
                $this->container->make(CompletePasswordReset::class)(Fortify::guard(), $user);
            },
        );

        return $status === Password::PASSWORD_RESET
            ? $this->container->make(PasswordResetResponse::class, ['status' => $status])
            : $this->container->make(FailedPasswordResetResponse::class, ['status' => $status]);
    }

    /**
     * Get the broker to be used during password reset.
     */
    protected function broker(): PasswordBroker
    {
        return Password::broker();
    }
}
