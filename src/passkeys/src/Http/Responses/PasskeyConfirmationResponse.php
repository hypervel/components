<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Http\Responses;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasskeyConfirmationResponse implements PasskeyConfirmationResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse([
                'redirect' => redirect()->intended()->getTargetUrl(),
            ], 200);
        }

        return redirect()->intended();
    }
}
