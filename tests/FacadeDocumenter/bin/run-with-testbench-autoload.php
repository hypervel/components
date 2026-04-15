<?php

declare(strict_types=1);

/**
 * Subprocess entry point for FacadeDocumenter tests.
 *
 * The documenter's own autoload covers the monorepo's vendor classes, but
 * test fixtures live under the testbench runtime copy (BASE_PATH/app/*) which
 * is not known to the monorepo's composer autoloader. This wrapper registers
 * an App\ psr-4 mapping pointed at the active testbench runtime before
 * handing control to the documenter.
 */

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/../../../vendor/autoload.php';

$testbenchBasePath = getenv('TESTBENCH_BASE_PATH');

if ($testbenchBasePath === false || $testbenchBasePath === '') {
    fwrite(STDERR, "run-with-testbench-autoload.php requires TESTBENCH_BASE_PATH env var.\n");
    exit(1);
}

$loader->addPsr4('App\\', $testbenchBasePath . '/app/');

require __DIR__ . '/../../../src/facade-documenter/facade.php';
