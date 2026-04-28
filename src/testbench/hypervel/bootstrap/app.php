<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Configuration\Middleware;
use Hypervel\Testbench\Foundation\Bootstrap\SyncTestbenchCachedRoutes;

use function Hypervel\Testbench\default_skeleton_path;

$app = Application::configure(basePath: $APP_BASE_PATH ?? default_skeleton_path())
    ->withProviders()
    ->withMiddleware(function (Middleware $middleware) {
    })
    ->withExceptions(function (Exceptions $exceptions) {
    })
    ->create();

// Deferred until after boot because this skeleton uses Application::configure()
// (minimal builder) rather than Testbench\Application::create() (full bootstrap).
// Route files use facades (Route::get(...)) which aren't available until after
// the kernel bootstraps the app.
$app->booted(static function () use ($app): void {
    (new SyncTestbenchCachedRoutes)->bootstrap($app);
});

return $app;
