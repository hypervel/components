<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Closure;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Support\Str;

class CanonicalizeUsername
{
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->has(Fortify::username())) {
            $request->merge([
                Fortify::username() => Str::lower((string) $request->{Fortify::username()}),
            ]);
        }

        return $next($request);
    }
}
