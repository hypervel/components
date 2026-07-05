<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use Composer\InstalledVersions;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Support\Env;
use RuntimeException;

class TestStateRegistrars
{
    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * Create a test-state registrar discoverer.
     */
    public function __construct(
        protected string $basePath,
        protected string $vendorPath,
        ?Filesystem $files = null
    ) {
        $this->files = $files ?? new Filesystem;
    }

    /**
     * Create a discoverer for the current Composer root install.
     */
    public static function forRootInstall(): static
    {
        $basePath = static::installedRootPath();
        $vendorPath = Env::get('COMPOSER_VENDOR_DIR');

        return new static(
            $basePath,
            is_string($vendorPath) && $vendorPath !== '' ? $vendorPath : $basePath . '/vendor'
        );
    }

    /**
     * Register discovered test-state registrars.
     */
    public function register(): void
    {
        foreach ($this->registrars() as $source => $classes) {
            foreach ($classes as $class) {
                $this->registerClass($source, $class);
            }
        }
    }

    /**
     * Get the Composer root package path.
     */
    protected static function installedRootPath(): string
    {
        $rootPackage = InstalledVersions::getRootPackage();
        $basePath = $rootPackage['install_path'] ?? getcwd();

        if (! is_string($basePath) || $basePath === '') {
            $currentPath = getcwd();
            $basePath = $currentPath === false ? '.' : $currentPath;
        }

        $realPath = realpath($basePath);

        return $realPath === false ? $basePath : $realPath;
    }

    /**
     * Get root test-state registrar classes.
     *
     * @return array<int|string, mixed>
     */
    protected function rootRegistrars(): array
    {
        $path = $this->basePath . '/composer.json';

        if (! $this->files->isFile($path)) {
            return [];
        }

        $composer = json_decode($this->files->get($path), true);

        if (! is_array($composer)) {
            return [];
        }

        return (array) ($composer['extra']['hypervel']['test-state'] ?? []);
    }

    /**
     * Get registrar classes keyed by their source package.
     *
     * @return array<string, array<int|string, mixed>>
     */
    protected function registrars(): array
    {
        $registrars = [];
        $baseIgnore = PackageManifest::packagesToIgnoreFromComposer($this->files, $this->basePath);

        foreach (PackageManifest::discoverInstalledPackages($this->files, $this->vendorPath, $baseIgnore) as $package => $configuration) {
            if (isset($configuration['test-state'])) {
                $registrars[$package] = (array) $configuration['test-state'];
            }
        }

        $rootRegistrars = $this->rootRegistrars();

        if ($rootRegistrars !== []) {
            $registrars['root'] = $rootRegistrars;
        }

        return $registrars;
    }

    /**
     * Register one registrar class.
     */
    protected function registerClass(string $source, mixed $class): void
    {
        if (! is_string($class) || $class === '') {
            throw new RuntimeException(
                "Test-state registrar declared by [{$source}] must be a class name string."
            );
        }

        if (! class_exists($class)) {
            throw new RuntimeException(
                "Test-state registrar [{$class}] declared by [{$source}] does not exist. Check that it is in normal autoload, not dependency autoload-dev."
            );
        }

        if (! method_exists($class, 'register')) {
            throw new RuntimeException(
                "Test-state registrar [{$class}] declared by [{$source}] must define a register method."
            );
        }

        $class::register();
    }
}
