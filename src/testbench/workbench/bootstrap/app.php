<?php

declare(strict_types=1);

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Configuration\Middleware;
use Workbench\App\Exceptions\ExceptionHandler as WorkbenchExceptionHandler;

use function Hypervel\Testbench\default_skeleton_path;

return Application::configure(basePath: $APP_BASE_PATH ?? default_skeleton_path())
    ->withProviders()
    ->withMiddleware(function (Middleware $middleware) {
    })
    ->withExceptions(function (Exceptions $exceptions) {
    })
    ->withBindings([
        ExceptionHandler::class => WorkbenchExceptionHandler::class,
    ])
    ->create();
