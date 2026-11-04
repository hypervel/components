<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Bootstrapper;

require_once __DIR__ . '/../bootstrap.php';

// The fixture application keeps upstream's namespaces, which the Testbench
// skeleton's own composer.json declares but Components' autoloader does not map.
/** @var ClassLoader $loader */
$loader = require __DIR__ . '/../../vendor/autoload.php';
$loader->addPsr4('App\\', __DIR__ . '/application/app/');
$loader->addPsr4('Database\Factories\\', __DIR__ . '/application/database/factories/');

// The analyzed application is this process's disposable Testbench runtime copy, so
// its resources become the fixture application's before it boots.
Bootstrapper::bootstrap();

$filesystem = new Filesystem;

foreach (['lang', 'resources/views'] as $directory) {
    $filesystem->deleteDirectory(BASE_PATH . '/' . $directory);
    $filesystem->copyDirectory(__DIR__ . '/application/' . $directory, BASE_PATH . '/' . $directory);
}

// Fixture config files replace the skeleton's files of the same name; the rest stay.
$filesystem->copyDirectory(__DIR__ . '/application/config', BASE_PATH . '/config');

// Testbench points the users provider at the shared workbench User model unless
// AUTH_MODEL names one, which would override the fixture auth configuration.
$_SERVER['AUTH_MODEL'] = $_ENV['AUTH_MODEL'] = 'App\User';

// PHPStan boots the analyzed application once per process when a test first loads
// the extension's bootstrap file. Booting it before any test runs keeps its error
// and exception handlers out of PHPUnit's per-test handler checks.
require_once __DIR__ . '/../../src/phpstan/bootstrap.php';
