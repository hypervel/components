<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Http\Request;

class PrefersJsonResponses
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $accept = $request->headers->get('Accept');

        if ($this->acceptHeaderIsBroad($accept)) {
            if ($accept !== null) {
                $request->headers->set('X-Original-Accept', $accept);
            }

            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }

    /**
     * Determine if the given "Accept" header value is broad enough to be treated as JSON.
     *
     * The header is broad when it's missing or every media-type listed is wildcard ("*\/*" or "application/*").
     */
    protected function acceptHeaderIsBroad(?string $accept): bool
    {
        if ($accept === null || trim($accept) === '') {
            return true;
        }

        foreach (explode(',', $accept) as $value) {
            $value = strtolower(trim($value));

            if ($value === '') {
                continue;
            }

            $position = strpos($value, ';');

            if ($position !== false) {
                $value = trim(substr($value, 0, $position));
            }

            if (! in_array($value, ['*/*', 'application/*'], true)) {
                return false;
            }
        }

        return true;
    }
}
