<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class WithoutPreviousLocationMiddleware extends Middleware
{
    /**
     * Determine if the visit should be stored as the previous location.
     */
    public function shouldStoreCurrentUrl(Request $request, Response $response): bool
    {
        return false;
    }
}
