<?php

declare(strict_types=1);

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Bootstrapper;

require_once __DIR__ . '/../bootstrap.php';

// The analyzed application is this process's disposable Testbench runtime copy, so
// its resources become the fixture application's before it boots.
Bootstrapper::bootstrap();

$filesystem = new Filesystem;

foreach (['lang', 'resources/views'] as $directory) {
    $filesystem->deleteDirectory(BASE_PATH . '/' . $directory);
    $filesystem->copyDirectory(__DIR__ . '/application/' . $directory, BASE_PATH . '/' . $directory);
}

// PHPStan boots the analyzed application once per process when a test first loads
// the extension's bootstrap file. Booting it before any test runs keeps its error
// and exception handlers out of PHPUnit's per-test handler checks.
require_once __DIR__ . '/../../src/phpstan/bootstrap.php';
