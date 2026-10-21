<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;

use function Hypervel\Testbench\join_paths;

Route::middleware('web')
    ->group(join_paths(__DIR__, 'web.php'));
