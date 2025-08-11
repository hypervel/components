<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Middleware;

use Closure;
use Hypervel\Horizon\Exceptions\ForbiddenException;
use Hypervel\Horizon\Horizon;

class Authenticate
{
    /**
     * Handle the incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param Closure $next
     * @return null|\Illuminate\Http\Response
     */
    public function handle($request, $next)
    {
        if (! Horizon::check($request)) {
            throw ForbiddenException::make();
        }

        return $next($request);
    }
}
