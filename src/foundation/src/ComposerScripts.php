<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Hypervel\Concurrency\ProcessDriver;
use Hypervel\Encryption\EncryptionServiceProvider;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Throwable;

class ComposerScripts
{
    /**
     * Handle the post-install Composer event.
     */
    public static function postInstall(Event $event): void
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir').'/autoload.php';

        static::clearCompiled();
    }

    /**
     * Handle the post-update Composer event.
     */
    public static function postUpdate(Event $event): void
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir').'/autoload.php';

        static::clearCompiled();
    }

    /**
     * Handle the post-autoload-dump Composer event.
     */
    public static function postAutoloadDump(Event $event): void
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir').'/autoload.php';

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
            require_once $event->getComposer()->getConfig()->get('vendor-dir').'/autoload.php';

            $hypervel = new Application(getcwd());

            $hypervel->bootstrapWith([
                LoadEnvironmentVariables::class,
                LoadConfiguration::class,
            ]);

            // Ensure we can encrypt our serializable closure...
            (new EncryptionServiceProvider($hypervel))->register();

            $name = $event->getOperation()->getPackage()->getName();
            $eventName = "composer_package.{$name}:pre_uninstall";

            $hypervel->make(ProcessDriver::class)->run(
                static fn () => app()['events']->dispatch($eventName)
            );
        } catch (Throwable $e) {
            // Ignore any errors to allow the package removal to complete...
            $event->getIO()->write('There was an error dispatching or handling the ['.($eventName ?? 'unknown').'] event. Continuing with package removal...');
            $event->getIO()->writeError('Exception message: '.$e->getMessage(), verbosity: IOInterface::VERBOSE); // @phpstan-ignore class.notFound (Composer exists if this is running)
        }
    }

    /**
     * Clear the cached Hypervel bootstrapping files.
     */
    protected static function clearCompiled(): void
    {
        $hypervel = new Application(getcwd());

        if (is_file($configPath = $hypervel->getCachedConfigPath())) {
            @unlink($configPath);
        }

        if (is_file($packagesPath = $hypervel->getCachedPackagesPath())) {
            @unlink($packagesPath);
        }
    }
}
