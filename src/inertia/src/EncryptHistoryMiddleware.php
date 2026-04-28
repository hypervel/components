<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EncryptHistoryMiddleware
{
    /**
     * Handle the incoming request and enable history encryption. This middleware
     * enables encryption of the browser history state, providing additional
     * security for sensitive data in Inertia responses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::encryptHistory();

        return $next($request);
    }
}
