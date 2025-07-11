<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Controllers;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class CsrfCookieController
{
    /**
     * Return empty response to trigger storage of the CSRF cookie in the browser.
     */
    public function show(RequestInterface $request, ResponseInterface $response): PsrResponseInterface
    {
        if ($request->hasHeader('Accept') && str_contains($request->header('Accept'), 'application/json')) {
            return $response->json([])->withStatus(204);
        }

        return $response->withStatus(204);
    }
}