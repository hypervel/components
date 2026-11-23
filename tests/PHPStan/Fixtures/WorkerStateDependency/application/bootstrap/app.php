<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;
use Hypervel\HttpServer\Events\RequestHandled;
use WorkerStateDependency\ReportListener;

return Application::configure(basePath: dirname(__DIR__))
    ->booted(static function (Application $app): void {
        $app->make('events')->listen(RequestHandled::class, ReportListener::class);
    })
    ->create();
