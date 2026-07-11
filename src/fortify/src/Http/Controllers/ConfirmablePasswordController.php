<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Actions\ConfirmPassword;
use Hypervel\Fortify\Contracts\ConfirmPasswordViewResponse;
use Hypervel\Fortify\Contracts\FailedPasswordConfirmationResponse;
use Hypervel\Fortify\Contracts\PasswordConfirmedResponse;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;

class ConfirmablePasswordController extends Controller
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * Show the confirm password view.
     */
    public function show(Request $request): ConfirmPasswordViewResponse
    {
        return $this->container->make(ConfirmPasswordViewResponse::class);
    }

    /**
     * Confirm the user's password.
     */
    public function store(Request $request): Responsable
    {
        $guardName = Fortify::guardName();

        /** @var Authenticatable&Model $user */
        $user = $request->user();

        $confirmed = $this->container->make(ConfirmPassword::class)(
            Fortify::guard(),
            $user,
            $request->input('password'),
        );

        if ($confirmed) {
            $request->session()->passwordConfirmed($guardName);
        }

        return $confirmed
            ? $this->container->make(PasswordConfirmedResponse::class)
            : $this->container->make(FailedPasswordConfirmationResponse::class);
    }
}
