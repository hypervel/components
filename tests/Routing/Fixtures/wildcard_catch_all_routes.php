<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;

Route::get('/foo', function (): string {
    return 'Regular route';
});

Route::get('{slug}', function (): string {
    return 'Wildcard route';
});
