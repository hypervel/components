<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Controllers;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Psr\Http\Message\ResponseInterface;

class CsrfCookieController
{
    /**
     * Return an empty response with the CSRF cookie.
     */
    public function __invoke(Request $request, Response $response): ResponseInterface
    {
        if ($request->expectsJson()) {
            return $response->json([])->withStatus(204);
        }

        return $response->html('')->withStatus(204);
    }
}
