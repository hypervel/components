<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Config\Repository as Config;
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
        $lastConfirmation = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $lastConfirmed = Date::now()->unix() - $lastConfirmation;
        $confirmed = $lastConfirmed < (int) $request->input(
            'seconds',
            $this->config->integer('auth.password_timeout', 900),
        );

        return response()->json([
            'confirmed' => $confirmed,
        ], headers: array_filter([
            'X-Retry-After' => $confirmed ? $lastConfirmed : null,
        ]));
    }
}
