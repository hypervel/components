<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Responses;

use Hypervel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuccessfulPasswordResetLinkRequestResponse implements SuccessfulPasswordResetLinkRequestResponseContract
{
    /**
     * Create a new response instance.
     */
    public function __construct(
        protected string $status,
    ) {
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => trans($this->status)], 200)
            : back()->with('status', trans($this->status));
    }
}
