<?php

declare(strict_types=1);

namespace Hypervel\Routing\Controllers;

use Closure;

interface HasMiddleware
{
    /**
     * Get the middleware that should be assigned to the controller.
     *
     * @return array<int, Closure|Middleware|string>
     */
    public static function middleware(): array;
}
