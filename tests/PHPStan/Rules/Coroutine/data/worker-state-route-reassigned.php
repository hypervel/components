<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerState;

use Hypervel\Config\Repository;
use Hypervel\Support\Facades\Route;

Route::get('/same-position', function (Repository $config): void {
    $config = new Repository;
});
