<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Http\Responses;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Hypervel\Passkeys\Passkeys;
use Symfony\Component\HttpFoundation\Response;

class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        $redirect = Passkeys::redirectTo($request);

        if ($request->wantsJson()) {
            return new JsonResponse([
                'redirect' => redirect()->intended($redirect)->getTargetUrl(),
            ], 200);
        }

        return redirect()->intended($redirect);
    }
}
