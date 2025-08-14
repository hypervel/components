<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Middleware;

use Closure;
use Hypervel\Horizon\Exceptions\ForbiddenException;
use Hypervel\Horizon\Horizon;
use Hypervel\Http\Request;
use Hypervel\Http\Response;

class Authenticate
{
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): ?Response
    {
        if (! Horizon::check($request)) {
            throw ForbiddenException::make();
        }

        return $next($request);
    }
}
