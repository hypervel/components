<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Inertia\Middleware;

class SsrExceptMiddleware extends Middleware
{
    /**
     * The paths that should be excluded from server-side rendering.
     *
     * @var array<int, string>
     */
    protected array $withoutSsr = [
        'admin/*',
        'nova/*',
    ];
}
