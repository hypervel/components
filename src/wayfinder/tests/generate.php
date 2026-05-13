<?php

declare(strict_types=1);

/*
 * Bootstrap script invoked by vitest's globalSetup (tests/build.ts).
 *
 * Boots a Hypervel testbench application, loads the Wayfinder fixture
 * routes from tests/Wayfinder/Fixtures/routes.php, and runs
 * wayfinder:generate against the output path passed as $argv[1].
 *
 * Why a custom bootstrap: Hypervel's testbench skeleton doesn't auto-load
 * package-test route files, so we register the fixture routes explicitly
 * after the kernel bootstraps the container.
 */

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;
use Hypervel\Tests\Wayfinder\Fixtures\FixtureServiceProvider;
use Hypervel\Wayfinder\WayfinderServiceProvider;

require __DIR__ . '/../../../vendor/autoload.php';

$componentsRoot = realpath(__DIR__ . '/../../..');
$outputPath = $argv[1] ?? __DIR__ . '/.generated';
$fixtureRoutes = $componentsRoot . '/tests/Wayfinder/Fixtures/routes.php';

// Clone the testbench skeleton to a disposable temp dir and point BASE_PATH
// at the clone, so framework writes (compiled views, package manifest cache,
// log files) hit the clone rather than the committed source tree. Cleanup
// happens automatically via register_shutdown_function inside Bootstrapper.
Bootstrapper::bootstrap();

$app = Application::create(
    basePath: BASE_PATH,
    options: ['extra' => ['providers' => [
        WayfinderServiceProvider::class,
        FixtureServiceProvider::class,
    ]]],
);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

require $fixtureRoutes;

exit($kernel->call('wayfinder:generate', [
    '--path' => $outputPath,
    '--with-form' => true,
]));
