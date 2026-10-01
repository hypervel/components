<?php

declare(strict_types=1);

use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Route;
use Hypervel\Workbench\Http\Controllers\WorkbenchController;

Route::group([
    'prefix' => '_workbench',
    'middleware' => 'web',
], static function (Router $router): void {
    $router->get(
        '/',
        [WorkbenchController::class, 'start']
    )->name('workbench.start');

    $router->get(
        '/login/{userId}/{guard?}',
        [WorkbenchController::class, 'login']
    )->name('workbench.login');

    $router->get(
        '/logout/{guard?}',
        [WorkbenchController::class, 'logout']
    )->name('workbench.logout');

    $router->get(
        '/user/{guard?}',
        [WorkbenchController::class, 'user']
    )->name('workbench.user');
});

// An unmatched "/" never reaches the web group, where CatchDefaultRoute applies the
// start and welcome settings. The parameter constraint matches nothing, so this
// fallback only answers "/". Routes for "/" and earlier fallbacks take precedence.
Route::get('{workbenchRoot?}', static fn (): never => abort(404))
    ->where('workbenchRoot', '(?!)')
    ->middleware('web')
    ->fallback();
