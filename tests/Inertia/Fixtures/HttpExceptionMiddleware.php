<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Inertia\Middleware;

class HttpExceptionMiddleware extends Middleware
{
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'appName' => 'My App',
        ]);
    }
}
