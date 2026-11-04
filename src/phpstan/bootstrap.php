<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\PHPStan\ApplicationResolver;
use Hypervel\PHPStan\Support\BootstrapErrorHandler;
use Hypervel\Testbench\Bootstrapper;

try {
    $applicationPath = getcwd() . '/bootstrap/app.php';
    if (file_exists($applicationPath)) {
        $app = require $applicationPath;
    } else {
        // Composer's root package path also covers custom vendor directories.
        $applicationPath = InstalledVersions::getRootPackage()['install_path'] . '/bootstrap/app.php';
        if (file_exists($applicationPath)) {
            $app = require $applicationPath;
        } elseif (class_exists(Bootstrapper::class)) {
            $app = ApplicationResolver::resolve();
        } else {
            // Upstream continues without an application, which silently drops every
            // boot-derived type instead of reporting the missing package dependency.
            throw new RuntimeException('No bootstrap/app.php was found. To analyze a package, install hypervel/testbench as a dev dependency.');
        }
    }

    if (! $app instanceof Application) {
        throw new UnexpectedValueException("The application bootstrap file [{$applicationPath}] must return the Hypervel application.");
    }

    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $throwable) {
    $argv = $_SERVER['argv'] ?? [];
    $decorated = null;

    if (in_array('--no-ansi', $argv, true)) {
        $decorated = false;
    } elseif (in_array('--ansi', $argv, true)) {
        $decorated = true;
    }

    (new BootstrapErrorHandler(decorated: $decorated))->handle($throwable);
    exit(1);
}
