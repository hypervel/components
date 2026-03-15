<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;

class TrimStrings extends TransformsRequest
{
    /**
     * The attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected array $except = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * The globally ignored attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected static array $neverTrim = [];

    /**
     * All of the registered skip callbacks.
     *
     * @var array<int, callable>
     */
    protected static array $skipCallbacks = [];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
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
        $except = array_merge($this->except, static::$neverTrim);

        if ($this->shouldSkip($key, $except) || ! is_string($value)) {
            return $value;
        }

        return Str::trim($value);
    }

    /**
     * Determine if the given key should be skipped.
     */
    protected function shouldSkip(string $key, array $except): bool
    {
        return Str::is($except, $key);
    }

    /**
     * Indicate that the given attributes should never be trimmed.
     */
    public static function except(array|string $attributes): void
    {
        static::$neverTrim = array_values(array_unique(
            array_merge(static::$neverTrim, Arr::wrap($attributes))
        ));
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
        static::$neverTrim = [];

        static::$skipCallbacks = [];
    }
}
