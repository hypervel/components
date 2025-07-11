<?php

declare(strict_types=1);

use Hypervel\Sanctum\Http\Controllers\CsrfCookieController;
use Hypervel\Support\Facades\Route;

Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'], [
    'as' => 'sanctum.csrf-cookie',
    'middleware' => ['web']
]);