<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Hypervel\Concurrency\ProcessDriver;
use Hypervel\Encryption\EncryptionServiceProvider;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use RuntimeException;
use Throwable;

class ComposerScripts
{
    /**
     * Handle the post-install Composer event.
     */
    public static function postInstall(Event $event): void
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir') . '/autoload.php';

        static::clearCompiled();
    }

    /**
     * Handle the post-update Composer event.
     */
    public static function postUpdate(Event $event): void
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir') . '/autoload.php';

        static::clearCompiled();
    }

    /**
     * Handle the post-autoload-dump Composer event.
     */
    public static function postAutoloadDump(Event $event): void
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir') . '/autoload.php';

        static::clearCompiled();
    }

    /**
     * Handle the pre-package-uninstall Composer event.
     */
    public static function prePackageUninstall(PackageEvent $event): void
    {
        // Package uninstall events are only applicable when uninstalling packages in dev environments...
        if (! $event->isDevMode()) {
            return;
        }

        $eventName = null;
        try {
            require_once $event->getComposer()->getConfig()->get('vendor-dir') . '/autoload.php';

            $hypervel = new Application(getcwd());

            $hypervel->bootstrapWith([
                LoadEnvironmentVariables::class,
                LoadConfiguration::class,
            ]);

            // Ensure we can encrypt our serializable closure...
            (new EncryptionServiceProvider($hypervel))->register();

            /** @var UninstallOperation $operation */
            $operation = $event->getOperation();
            $name = $operation->getPackage()->getName();
            $eventName = "composer_package.{$name}:pre_uninstall";

            $hypervel->make(ProcessDriver::class)->run(
                static fn (): mixed => app()->make('events')->dispatch($eventName)
            );
        } catch (Throwable $e) {
            // Ignore any errors to allow the package removal to complete...
            $event->getIO()->write('There was an error dispatching or handling the [' . ($eventName ?? 'unknown') . '] event. Continuing with package removal...');
            $event->getIO()->writeError('Exception message: ' . $e->getMessage(), verbosity: IOInterface::VERBOSE);
        }
    }

    /**
     * Clear the cached Hypervel bootstrapping files.
     */
    protected static function clearCompiled(): void
    {
        $hypervel = new Application(getcwd());

        if (is_file($configPath = $hypervel->getCachedConfigPath())
            && ! @unlink($configPath)) {
            // Another process may have removed the file after the first check.
            clearstatcache(false, $configPath);

            if (is_file($configPath)) {
                throw new RuntimeException("Unable to delete the configuration cache file [{$configPath}].");
            }
        }

        if (is_file($packagesPath = $hypervel->getCachedPackagesPath())
            && ! @unlink($packagesPath)) {
            // Another process may have removed the file after the first check.
            clearstatcache(false, $packagesPath);

            if (is_file($packagesPath)) {
                throw new RuntimeException("Unable to delete the compiled packages file [{$packagesPath}].");
            }
        }
    }
}
