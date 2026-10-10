<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class StorePartialReloadsMiddleware extends Middleware
{
    /**
     * Determine if the request is a partial reload of the component that was rendered.
     */
    protected function isPartialReload(Request $request, Response $response): bool
    {
        return false;
    }
}
