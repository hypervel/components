<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;

Route::get('/dashboard', function () {
    return 'workbench::dashboard';
})->middleware(['auth'])->name('dashboard');
