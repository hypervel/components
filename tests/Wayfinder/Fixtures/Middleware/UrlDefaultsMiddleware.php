<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures\Middleware;

use Hypervel\Support\Facades\URL;

class UrlDefaultsMiddleware
{
    public function handle($request, $next)
    {
        URL::defaults([
            'locale' => 'en',
        ]);

        return $next($request);
    }
}
