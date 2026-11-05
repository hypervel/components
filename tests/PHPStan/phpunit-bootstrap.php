<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Hypervel\Console\Application;
use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\ClosureCommand;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Tests\PHPStan\Fixtures\Config\ConfigFixtureServiceProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\OverlappingConfigServiceProvider;
use Hypervel\Tests\PHPStan\Fixtures\Console\WithArgumentCommand;
use Hypervel\Tests\PHPStan\Fixtures\Console\WithoutArgumentCommand;

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

foreach (['app/Console/Commands', 'lang', 'resources/views'] as $directory) {
    $filesystem->deleteDirectory(BASE_PATH . '/' . $directory);
    $filesystem->copyDirectory(__DIR__ . '/application/' . $directory, BASE_PATH . '/' . $directory);
}

// Fixture config files replace the skeleton's files of the same name; the rest stay.
$filesystem->copyDirectory(__DIR__ . '/application/config', BASE_PATH . '/config');
$filesystem->copyDirectory(__DIR__ . '/Fixtures/Config/application', BASE_PATH . '/config');

// Registered only as a provider would, in this order, which the command lookup tests rely on.
Application::starting(static function (Application $artisan): void {
    $artisan->resolveCommands([WithoutArgumentCommand::class, WithArgumentCommand::class]);
    $artisan->resolve(new ClosureCommand('lookup:closure', static function (): void {}));
});

// Testbench points the users provider at the shared workbench User model unless
// AUTH_MODEL names one, which would override the fixture auth configuration.
$_SERVER['AUTH_MODEL'] = $_ENV['AUTH_MODEL'] = 'App\User';

// Boot the analyzed application before any test, so its error and exception handlers
// stay out of PHPUnit's per-test handler checks.
require_once __DIR__ . '/../../src/phpstan/bootstrap.php';

// Package configuration sources, which the config analysis reads from the loaded providers.
Container::getInstance()->register(ConfigFixtureServiceProvider::class);
Container::getInstance()->register(OverlappingConfigServiceProvider::class);
