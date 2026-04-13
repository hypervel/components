<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;

Route::get('/foo', function () {
    return 'Regular route';
});

Route::get('{slug}', function () {
    return 'Wildcard route';
});
