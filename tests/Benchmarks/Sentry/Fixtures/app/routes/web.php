<?php

declare(strict_types=1);

use App\Http\Controllers\BenchController;
use Hypervel\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/plain', [BenchController::class, 'plain']);
Route::get('/work', [BenchController::class, 'work']);
