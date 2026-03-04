<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hypervel\Http\Request;
use Hypervel\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BroadcastController
{
    /**
     * Authenticate the request for channel access.
     */
    public function authenticate(Request $request): mixed
    {
        return Broadcast::auth($request);
    }

    /**
     * Authenticate the current user.
     *
     * See: https://pusher.com/docs/channels/server_api/authenticating-users/#user-authentication.
     *
     * @throws AccessDeniedHttpException
     */
    public function authenticateUser(Request $request): array
    {
        return Broadcast::resolveAuthenticatedUser($request) ?? throw new AccessDeniedHttpException();
    }
}
