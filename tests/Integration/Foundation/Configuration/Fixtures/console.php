<?php

declare(strict_types=1);

use Hypervel\Foundation\Inspiring;
use Hypervel\Support\Facades\Artisan;

Artisan::command('test:inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();
