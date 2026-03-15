<?php

declare(strict_types=1);

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Foundation\Application;
use Workbench\App\Exceptions\ExceptionHandler as WorkbenchExceptionHandler;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders()
    ->withBindings([
        ExceptionHandler::class => WorkbenchExceptionHandler::class,
    ])
    ->create();
