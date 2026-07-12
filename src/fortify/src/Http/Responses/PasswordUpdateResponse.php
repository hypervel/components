<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Responses;

use Hypervel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    public function toResponse(Request $request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 200)
            : back()->with('status', Fortify::PASSWORD_UPDATED);
    }
}
