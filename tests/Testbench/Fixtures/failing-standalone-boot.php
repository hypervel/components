<?php

declare(strict_types=1);

/*
 * Boots a standalone Testbench application whose provider fails during boot,
 * leaving the exception uncaught so the process reports it.
 */

use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;
use Hypervel\Tests\Testbench\Fixtures\Providers\FailingBootServiceProvider;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Bootstrapper::bootstrap();

Application::create(
    basePath: BASE_PATH,
    options: ['extra' => ['providers' => [FailingBootServiceProvider::class]]],
);
