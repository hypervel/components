<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Inertia\Middleware;

/**
 * Renders through a root view that has a `</body>` tag, so the devtools id script tag has
 * somewhere to be injected.
 */
class DevToolsRootViewMiddleware extends Middleware
{
    protected string $rootView = 'devtools-app';
}
