<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Responses;

use Hypervel\Fortify\Contracts\TwoFactorEnabledResponse as TwoFactorEnabledResponseContract;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorEnabledResponse implements TwoFactorEnabledResponseContract
{
    public function toResponse(Request $request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 200)
            : back()->with('status', Fortify::TWO_FACTOR_AUTHENTICATION_ENABLED);
    }
}
