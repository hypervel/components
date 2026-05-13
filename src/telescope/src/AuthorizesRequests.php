<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Closure;
use Hypervel\Http\Request;

trait AuthorizesRequests
{
    /**
     * The callback that should be used to authenticate Telescope users.
     */
    public static ?Closure $authUsing = null;

    /**
     * Register the Telescope authentication callback.
     *
     * Boot-only. The callback persists in a static property for the worker
     * lifetime and runs on every Telescope dashboard request across all
     * coroutines.
     */
    public static function auth(?Closure $callback): static
    {
        static::$authUsing = $callback;

        return new static;
    }

    /**
     * Determine if the given request can access the Telescope dashboard.
     */
    public static function check(Request $request): bool
    {
        return (static::$authUsing ?: function () {
            return app()->isLocal();
        })($request);
    }
}
