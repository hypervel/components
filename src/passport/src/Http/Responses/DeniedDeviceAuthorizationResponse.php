<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Responses;

use Hypervel\Http\Request;
use Hypervel\Passport\Contracts\DeniedDeviceAuthorizationResponse as DeniedDeviceAuthorizationResponseContract;
use Symfony\Component\HttpFoundation\Response;

class DeniedDeviceAuthorizationResponse implements DeniedDeviceAuthorizationResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        return to_route('passport.device')
            ->with('status', 'authorization-denied');
    }
}
