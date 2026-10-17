<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Middleware;

use Closure;
use GuzzleHttp\Psr7\Header;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReorderJsonAccept
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accept = $request->header('Accept');
        if (is_string($accept) && str_contains($accept, ',')) {
            $accept = Header::splitList($accept);
        }

        if (! is_array($accept)) {
            return $next($request);
        }

        usort($accept, fn (string $a, string $b): int => (stripos($b, 'application/json') !== false) <=> (stripos($a, 'application/json') !== false));
        $request->headers->set('Accept', implode(', ', $accept));

        return $next($request);
    }
}
