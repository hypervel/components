<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Contracts\PasswordUpdateResponse;
use Hypervel\Fortify\Contracts\UpdatesUserPasswords;
use Hypervel\Fortify\Events\PasswordUpdatedViaController;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Support\Facades\Password;

class PasswordController extends Controller
{
    use DispatchesEvents;

    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request, UpdatesUserPasswords $updater): PasswordUpdateResponse
    {
        /** @var Authenticatable&CanResetPassword $user */
        $user = $request->user();

        $updater->update($user, $request->all());

        $this->broker()->deleteToken($user);

        $this->dispatchIfListening(
            $this->events,
            PasswordUpdatedViaController::class,
            fn (): PasswordUpdatedViaController => new PasswordUpdatedViaController($user),
        );

        return $this->container->make(PasswordUpdateResponse::class);
    }

    /**
     * Get the broker to be used to delete any existing password reset tokens.
     */
    protected function broker(): PasswordBroker
    {
        return Password::broker(Fortify::passwordBrokerName());
    }
}
