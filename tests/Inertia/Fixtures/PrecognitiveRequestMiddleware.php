<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrecognitiveRequestMiddleware
{
    /**
     * Mark requests with the Precognition header as precognitive.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('Precognition') === 'true') {
            $request->attributes->set('precognitive', true);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
