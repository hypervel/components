<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Actions\GenerateNewRecoveryCodes;
use Hypervel\Fortify\Contracts\RecoveryCodesGeneratedResponse;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;

class RecoveryCodeController extends Controller
{
    /**
     * Get the two factor authentication recovery codes for authenticated user.
     *
     * @return array<never, never>|JsonResponse
     */
    public function index(Request $request): array|JsonResponse
    {
        /** @var Authenticatable&Model $user */
        $user = $request->user();
        $secret = $user->getAttribute('two_factor_secret');
        $recoveryCodes = $user->getAttribute('two_factor_recovery_codes');

        if (! is_string($secret) || $secret === '' || ! is_string($recoveryCodes) || $recoveryCodes === '') {
            return [];
        }

        return response()->json(json_decode(
            Fortify::currentEncrypter()->decrypt($recoveryCodes),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Generate a fresh set of two factor authentication recovery codes.
     */
    public function store(Request $request, GenerateNewRecoveryCodes $generate): RecoveryCodesGeneratedResponse
    {
        /** @var Authenticatable&Model $user */
        $user = $request->user();

        $generate($user);

        return app(RecoveryCodesGeneratedResponse::class);
    }
}
