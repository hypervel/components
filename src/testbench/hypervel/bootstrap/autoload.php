<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;

// Cache commands pass the application's autoloader explicitly. A runtime copy links the
// package's vendor directory beside the application.
$autoloadPaths = array_filter([
    getenv('HYPERVEL_AUTOLOAD_PATH') ?: null,
    dirname(__DIR__) . '/vendor/autoload.php',
    getcwd() . '/vendor/autoload.php',
    is_string(getenv('TESTBENCH_WORKING_PATH'))
        ? (string) getenv('TESTBENCH_WORKING_PATH') . '/vendor/autoload.php'
        : null,
]);

foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require $autoloadPath;
        break;
    }
}

if (! class_exists(Application::class)) {
    fwrite(STDERR, "Unable to locate Composer autoloader for the Hypervel testbench workbench.\n");
    exit(1);
}
