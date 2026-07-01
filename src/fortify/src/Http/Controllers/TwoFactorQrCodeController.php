<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationUser;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;

class TwoFactorQrCodeController extends Controller
{
    /**
     * Get the SVG element for the user's two factor authentication QR code.
     *
     * @return array<never, never>|JsonResponse
     */
    public function show(Request $request): array|JsonResponse
    {
        /** @var Authenticatable&Model&TwoFactorAuthenticationUser $user */
        $user = $request->user();

        if (is_null($user->getAttribute('two_factor_secret'))) {
            return [];
        }

        return response()->json([
            'svg' => $user->twoFactorQrCodeSvg(),
            'url' => $user->twoFactorQrCodeUrl(),
        ]);
    }
}
