<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Responses;

use Hypervel\Fortify\Contracts\FailedPasswordConfirmationResponse as FailedPasswordConfirmationResponseContract;
use Hypervel\Http\Request;
use Hypervel\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class FailedPasswordConfirmationResponse implements FailedPasswordConfirmationResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @throws ValidationException
     */
    public function toResponse(Request $request): Response
    {
        $message = __('The provided password was incorrect.');

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([
                'password' => [$message],
            ]);
        }

        return back()->withErrors(['password' => $message]);
    }
}
