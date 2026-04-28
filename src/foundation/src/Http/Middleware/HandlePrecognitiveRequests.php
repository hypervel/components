<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandlePrecognitiveRequests
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isAttemptingPrecognition()) {
            return $this->appendVaryHeader($request, $next($request));
        }

        $this->prepareForPrecognition($request);

        return tap($next($request), function (Response $response) use ($request) {
            $response->headers->set('Precognition', 'true');

            $this->appendVaryHeader($request, $response);
        });
    }

    /**
     * Prepare to handle a precognitive request.
     *
     * Sets two request attributes:
     * - `precognitive`: user-facing flag checked by `isPrecognitive()`
     * - `precognitive_dispatch`: internal flag checked by routing dispatchers
     *   to delegate to Precognition variants
     *
     * Using request attributes instead of container rebinding avoids race
     * conditions in Swoole's concurrent worker model.
     */
    protected function prepareForPrecognition(Request $request): void
    {
        $request->attributes->set('precognitive', true);
        $request->attributes->set('precognitive_dispatch', true);
    }

    /**
     * Append the appropriate "Vary" header to the given response.
     */
    protected function appendVaryHeader(Request $request, Response $response): Response
    {
        return tap($response, fn () => $response->headers->set('Vary', implode(', ', array_filter([
            $response->headers->get('Vary'),
            'Precognition',
        ]))));
    }
}
