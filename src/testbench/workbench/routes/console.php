<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('workbench:inspire', function () {
    /* @phpstan-ignore-next-line */
    $this->comment('What is essential is invisible to the eye.');
})->purpose('Display an inspiring quote');
