<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;

class CsrfCookieController
{
    /**
     * Return an empty response simply to trigger the storage of the CSRF cookie in the browser.
     */
    public function show(Request $request): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(status: 204);
        }

        return new Response(status: 204);
    }
}
