<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Passport\Contracts\DeviceUserCodeViewResponse;

class DeviceUserCodeController
{
    /**
     * Show the form for entering the user code.
     */
    public function __invoke(
        Request $request,
        DeviceUserCodeViewResponse $viewResponse
    ): RedirectResponse|DeviceUserCodeViewResponse {
        if ($userCode = $request->query('user_code')) {
            return to_route('passport.device.authorizations.authorize', [
                'user_code' => $userCode,
            ]);
        }

        return $viewResponse->withParameters([
            'request' => $request,
        ]);
    }
}
