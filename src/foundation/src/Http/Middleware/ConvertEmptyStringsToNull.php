<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConvertEmptyStringsToNull extends TransformsRequest
{
    /**
     * All of the registered skip callbacks.
     *
     * @var array<int, callable>
     */
    protected static array $skipCallbacks = [];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (static::$skipCallbacks as $callback) {
            if ($callback($request)) {
                return $next($request);
            }
        }

        return parent::handle($request, $next);
    }

    /**
     * Transform the given value.
     */
    protected function transform(string $key, mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    /**
     * Register a callback that instructs the middleware to be skipped.
     */
    public static function skipWhen(Closure $callback): void
    {
        static::$skipCallbacks[] = $callback;
    }

    /**
     * Flush the middleware's global state.
     */
    public static function flushState(): void
    {
        static::$skipCallbacks = [];
    }
}
