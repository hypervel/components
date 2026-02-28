<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Http\Request as RequestContract;
use Hypervel\Support\Environment;

trait AuthorizesRequests
{
    /**
     * The callback that should be used to authenticate Telescope users.
     */
    public static ?Closure $authUsing = null;

    /**
     * Register the Telescope authentication callback.
     */
    public static function auth(?Closure $callback): static
    {
        static::$authUsing = $callback;

        return new static();
    }

    /**
     * Determine if the given request can access the Telescope dashboard.
     */
    public static function check(RequestContract $request): bool
    {
        return (static::$authUsing ?: function () {
            return Container::getInstance()
                ->make(Environment::class)
                ->isLocal();
        })($request);
    }
}
