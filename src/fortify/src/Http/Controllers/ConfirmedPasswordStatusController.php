<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Auth\PasswordConfirmation;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Support\Facades\Date;

class ConfirmedPasswordStatusController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * Get the password confirmation status.
     */
    public function show(Request $request): JsonResponse
    {
        $guard = Fortify::guardName();
        $lastConfirmation = (int) $request->session()->get(PasswordConfirmation::sessionKey($guard), 0);
        $lastConfirmed = Date::now()->unix() - $lastConfirmation;
        $confirmed = $lastConfirmed < PasswordConfirmation::timeout($this->config, $guard, $request->input('seconds'));

        return response()->json([
            'confirmed' => $confirmed,
        ], headers: array_filter([
            'X-Retry-After' => $confirmed ? $lastConfirmed : null,
        ]));
    }
}
