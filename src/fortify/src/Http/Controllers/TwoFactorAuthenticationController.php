<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Actions\DisableTwoFactorAuthentication;
use Hypervel\Fortify\Actions\EnableTwoFactorAuthentication;
use Hypervel\Fortify\Contracts\TwoFactorDisabledResponse;
use Hypervel\Fortify\Contracts\TwoFactorEnabledResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;

class TwoFactorAuthenticationController extends Controller
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * Enable two factor authentication for the user.
     */
    public function store(Request $request, EnableTwoFactorAuthentication $enable): TwoFactorEnabledResponse
    {
        /** @var Authenticatable&Model $user */
        $user = $request->user();

        $enable($user, $request->boolean('force', false));

        return $this->container->make(TwoFactorEnabledResponse::class);
    }

    /**
     * Disable two factor authentication for the user.
     */
    public function destroy(Request $request, DisableTwoFactorAuthentication $disable): TwoFactorDisabledResponse
    {
        /** @var Authenticatable&Model $user */
        $user = $request->user();

        $disable($user);

        return $this->container->make(TwoFactorDisabledResponse::class);
    }
}
