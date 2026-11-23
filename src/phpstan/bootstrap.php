<?php

declare(strict_types=1);

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\PHPStan\ApplicationResolver;
use Hypervel\PHPStan\Support\BootstrapErrorHandler;
use Hypervel\PHPStan\Support\EnvironmentSnapshot;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Composer;
use Hypervel\Testbench\Bootstrapper;

// The result cache compares environment variables as they were before booting loads the environment file.
EnvironmentSnapshot::capture();

try {
    $packageApplication = false;
    $applicationPath = getcwd() . '/bootstrap/app.php';
    if (file_exists($applicationPath)) { // Applications and Local Dev
        $app = require $applicationPath;
    } else {
        // Composer's root package path also covers custom vendor directories.
        $applicationPath = Composer::rootPackage()['install_path'] . '/bootstrap/app.php';
        if (file_exists($applicationPath)) {
            $app = require $applicationPath;
        } elseif (class_exists(Bootstrapper::class)) { // Packages
            $app = ApplicationResolver::resolve();
            $packageApplication = true;
        } else {
            // Upstream continues without an application, which silently drops every
            // boot-derived type instead of reporting the missing package dependency.
            throw new RuntimeException('No bootstrap/app.php was found. To analyze a package, install hypervel/testbench as a dev dependency.');
        }
    }

    if (! $app instanceof Application) {
        throw new UnexpectedValueException("The application bootstrap file [{$applicationPath}] must return the Hypervel application.");
    }

    // Testbench's package application loads no environment file, only its YAML env.
    $environmentFile = $packageApplication ? null : $app->environmentFilePath();

    $app->make(Kernel::class)->bootstrap();

    RuntimeInputs::captureBoot($app, $environmentFile, $packageApplication);
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
