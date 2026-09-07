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
            'octalLower' => 0o12,
            'octalUpper' => 0o12,
            'separated' => 1_000,
            'positiveOctal' => +0o12,
            'negativeOctal' => -0o12,
            'legacyOctal' => 012,
            'hexadecimal' => 0x10,
            'binary' => 0b10,
            'decimal' => 10,
            'user' => 42,
            'dynamic' => $request->timezone,
            'literalNull' => null,
            'unsupported' => ['nested' => 'value'],
            10 => 'numeric-key',
        ]);

        // The defaults parser must not harvest a neighboring array assignment.
        $unrelated = ['neighbor' => 'not-a-default'];

        URL::defaults([
            'secondary' => 'second',
            'computed' => strtoupper('computed'),
        ]);

        return $next($request);
    }
}
