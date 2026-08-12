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
     * Get the password confirmation status.
     */
    public function show(Request $request): JsonResponse
    {
        $config = app(Config::class);
        $guard = Fortify::guardName();
        $lastConfirmation = (int) $request->session()->get(PasswordConfirmation::sessionKey($guard), 0);
        $lastConfirmed = Date::now()->unix() - $lastConfirmation;
        $seconds = $request->has('seconds') ? $request->integer('seconds') : null;
        $confirmed = $lastConfirmed < PasswordConfirmation::timeout($config, $guard, $seconds);

        return response()->json([
            'confirmed' => $confirmed,
        ], headers: array_filter([
            'X-Retry-After' => $confirmed ? $lastConfirmed : null,
        ]));
    }
}
