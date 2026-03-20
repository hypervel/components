<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Configuration\Middleware;

use function Hypervel\Testbench\default_skeleton_path;

return Application::configure(basePath: $APP_BASE_PATH ?? default_skeleton_path())
    ->withProviders()
    ->withMiddleware(function (Middleware $middleware) {
    })
    ->withExceptions(function (Exceptions $exceptions) {
    })
    ->create();
