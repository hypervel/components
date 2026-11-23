<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;
use Hypervel\Tests\PHPStan\Fixtures\Config\ConfigFixtureServiceProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\OverlappingConfigServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([ConfigFixtureServiceProvider::class, OverlappingConfigServiceProvider::class])
    ->create();
