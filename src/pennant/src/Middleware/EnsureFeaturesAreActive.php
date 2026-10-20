<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Middleware;

use BackedEnum;
use Closure;
use Hypervel\Http\Request;
use Hypervel\Pennant\Feature;
use UnitEnum;

use function Hypervel\Pennant\enum_value;

class EnsureFeaturesAreActive
{
    /**
     * The callback that generates responses for failed feature checks.
     */
    protected static ?Closure $respondUsing = null;

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next, BackedEnum|UnitEnum|string ...$features): mixed
    {
        $features = array_map(enum_value(...), $features);

        Feature::loadMissing($features);

        if (Feature::someAreInactive($features)) {
            $error = config()->boolean('app.debug')
                ? 'Required features [' . implode(', ', $features) . '] not enabled.'
                : '';

            return static::$respondUsing
                ? call_user_func(static::$respondUsing, $request, $features)
                : abort(400, $error);
        }

        return $next($request);
    }

    /**
     * Specify the features for the middleware.
     */
    public static function using(BackedEnum|UnitEnum|string ...$features): string
    {
        return static::class . ':' . implode(',', array_map(enum_value(...), $features));
    }

    /**
     * Specify a callback that should be used to generate responses for failed feature checks.
     *
     * Boot-only. The callback persists in a static property for the worker lifetime
     * and affects every subsequent request.
     */
    public static function whenInactive(?Closure $callback): void
    {
        static::$respondUsing = $callback;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$respondUsing = null;
    }
}
