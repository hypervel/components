<?php

declare(strict_types=1);

use Hypervel\Http\JsonResponse;
use Hypervel\Support\Facades\Route;

Route::query('/search', function (): JsonResponse {
    return response()->json([
        'method' => request()->method(),
        'term' => request()->query('term'),
        'filter' => request()->input('filter'),
    ]);
});
