<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Http\Responses;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasskeyDeletedResponse implements PasskeyDeletedResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['status' => 'passkey-deleted'], 200);
        }

        return back()->with('status', 'passkey-deleted');
    }
}
