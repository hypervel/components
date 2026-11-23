<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository as Config;

class RoutePath
{
    /**
     * Get the route path for the given route name.
     */
    public static function for(string $routeName, string $default): string
    {
        return Container::getInstance()->make(Config::class)->nullableString('fortify.paths.' . $routeName, null) ?? $default;
    }
}
