<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures\Middleware;

use Closure;
use Hypervel\Support\Facades\URL;

class UrlDefaultsMiddleware
{
    public function handle(mixed $request, Closure $next): mixed
    {
        URL::defaults([
            'locale' => 'en',
            'signed' => -12,
            'ratio' => +1.5,
            'enabled' => true,
            'disabled' => false,
            'user' => 42,
            'dynamic' => $request->timezone,
            'literalNull' => null,
            'unsupported' => ['nested' => 'value'],
            10 => 'numeric-key',
        ]);

        $unrelated = ['neighbor' => 'not-a-default'];

        URL::defaults([
            'secondary' => 'second',
            'computed' => strtoupper('computed'),
        ]);

        unset($unrelated);

        return $next($request);
    }
}
