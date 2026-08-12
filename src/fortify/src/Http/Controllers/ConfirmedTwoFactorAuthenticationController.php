<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Hypervel\Fortify\Contracts\TwoFactorConfirmedResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;

class ConfirmedTwoFactorAuthenticationController extends Controller
{
    /**
     * Confirm two factor authentication for the user.
     */
    public function store(Request $request, ConfirmTwoFactorAuthentication $confirm): TwoFactorConfirmedResponse
    {
        /** @var Authenticatable&Model $user */
        $user = $request->user();

        $confirm($user, (string) $request->input('code'));

        return app(TwoFactorConfirmedResponse::class);
    }
}
