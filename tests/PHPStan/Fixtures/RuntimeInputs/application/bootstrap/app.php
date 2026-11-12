<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;
use RuntimeInputs\Providers\FixtureServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php')
    ->withProviders([FixtureServiceProvider::class])
    ->create();
