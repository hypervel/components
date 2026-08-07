<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Responses;

use Hypervel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Http\Request;
use Hypervel\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LockoutResponse implements LockoutResponseContract
{
    /**
     * Create a new response instance.
     */
    public function __construct(
        protected LoginRateLimiter $limiter,
    ) {
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @throws ValidationException
     */
    public function toResponse(Request $request): Response
    {
        $seconds = $this->limiter->availableIn($request);

        throw ValidationException::withMessages([
            Fortify::username() => [
                trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ],
        ])->status(429);
    }
}
