<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Route;

Route::get('/a', function (): void {
    CountingMiddleware::$seen = true;
    config(['app.name' => 'Hypervel']);

    collect([1])->each(function (): void {
        CountingMiddleware::$seen = true;
    });
    collect([1])->each(fn (): bool => CountingMiddleware::$seen = true);
});

Route::post('/b', action: fn (): bool => CountingMiddleware::$seen = true);

\Route::put('/c', function (): void {
    CountingMiddleware::$seen = true;
});

Route::middleware('web')->get('/d', function (): void {
    CountingMiddleware::$seen = true;
});

Route::middleware('web')->group(function (): void {
    CountingMiddleware::$seen = true;
});

Route::get('/f', function (): string {
    $later = function (): void {
        CountingMiddleware::$seen = true;
    };
    $after = fn (): bool => CountingMiddleware::$seen = true;
    tap(null, CountingMiddleware::$seen = true);

    return '';
});

Route::get('/g', function (ConfigRepository $config, ?Repository $optional = null): void {
    $config['app.name'] = 'Hypervel';
    $optional->set('app.name', 'Hypervel');
});

function registerRoutes(Router $router): void
{
    $router->patch('/e', function (): void {
        CountingMiddleware::$seen = true;
    });
}

collect([1])->each(function (): void {
    CountingMiddleware::$seen = true;
});
