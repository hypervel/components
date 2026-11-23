<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;
use Hypervel\Tests\PHPStan\Fixtures\Bootstrap\BootstrapFixtureServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([BootstrapFixtureServiceProvider::class])
    ->create();
