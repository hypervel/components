<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;

class TwoFactorSecretKeyController extends Controller
{
    /**
     * Get the current user's two factor authentication setup / secret key.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Authenticatable&Model $user */
        $user = $request->user();
        $secret = $user->getAttribute('two_factor_secret');

        if (! is_string($secret) || $secret === '') {
            abort(404, 'Two factor authentication has not been enabled.');
        }

        return response()->json([
            'secretKey' => Fortify::currentEncrypter()->decrypt($secret),
        ]);
    }
}
