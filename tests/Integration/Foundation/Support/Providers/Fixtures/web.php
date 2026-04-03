<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;

Route::get('/{user}', fn () => response('', 404));
