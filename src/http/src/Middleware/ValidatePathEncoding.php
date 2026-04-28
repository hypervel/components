<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Http\Exceptions\MalformedUrlException;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidatePathEncoding
{
    /**
     * Validate that the incoming request has a valid UTF-8 encoded path.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $decodedPath = rawurldecode($request->path());

        if (! mb_check_encoding($decodedPath, 'UTF-8')) {
            throw new MalformedUrlException;
        }

        return $next($request);
    }
}
