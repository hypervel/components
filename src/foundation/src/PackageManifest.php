<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Exception;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Env;
use RuntimeException;

class PackageManifest
{
    /**
     * The filesystem instance.
     */
    public Filesystem $files;

    /**
     * The base path.
     */
    public string $basePath;

    /**
     * The vendor path.
     */
    public string $vendorPath;

    /**
     * The manifest path.
     */
    public ?string $manifestPath;

    /**
     * The loaded manifest array (filtered by runtime ignore list).
     */
    public ?array $manifest = null;

    /**
     * The raw manifest loaded from cache or built from installed.json.
     *
     * Stored separately from $manifest so the runtime ignore list
     * can be applied on every getManifest() call without re-reading
     * the cache file or re-scanning installed.json.
     */
    protected ?array $rawManifest = null;

    /**
     * Packages that should be ignored during discovery.
     *
     * Set at runtime (e.g., by testbench) to suppress discovery
     * without modifying composer.json dont-discover.
     *
     * @var array<int, string>
     */
    protected static array $packagesToIgnore = [];

    /**
     * Create a new package manifest instance.
     */
    public function __construct(Filesystem $files, string $basePath, ?string $manifestPath)
    {
        $this->files = $files;
        $this->basePath = $basePath;
        $this->manifestPath = $manifestPath;
        $this->vendorPath = Env::get('COMPOSER_VENDOR_DIR') ?: $basePath . '/vendor';
    }

    /**
     * Get all of the service provider class names for all packages.
     */
    public function providers(): array
    {
        return $this->config('providers');
    }

    /**
     * Get all of the aliases for all packages.
     */
    public function aliases(): array
    {
        return $this->config('aliases');
    }

    /**
     * Get all of the values for all packages for the given configuration name.
     */
    public function config(string $key): array
    {
        return (new Collection($this->getManifest()))
            ->flatMap(fn (array $configuration) => (array) ($configuration[$key] ?? []))
            ->filter()
            ->all();
    }

    /**
     * Get the cached version string for a package.
     *
     * Returns the version from the manifest cache, avoiding runtime
     * Composer API calls. Useful for feature gating and compatibility
     * checks in application and package code.
     */
    public function version(string $package): ?string
    {
        $manifest = $this->getManifest();

        return $manifest[$package]['version'] ?? null;
    }

    /**
     * Determine if the given package is installed.
     */
    public function hasPackage(string $package): bool
    {
        return array_key_exists($package, $this->getManifest());
    }

    /**
     * Determine if a package satisfies a version constraint.
     *
     * Uses Composer's semver constraint parser for full constraint
     * support (e.g., "^2.0", ">=1.5 <3.0", "~4.1").
     *
     * Requires `composer/semver` package. Add it to your project:
     * `composer require composer/semver`
     *
     * @throws RuntimeException if composer/semver is not installed
     */
    public function satisfies(string $package, string $constraint): bool
    {
        if (! class_exists(\Composer\Semver\VersionParser::class)) {
            throw new RuntimeException(
                'The composer/semver package is required to use version constraints. Install it with: composer require composer/semver'
            );
        }

        $version = $this->version($package);

        if ($version === null) {
            return false;
        }

        $parser = new \Composer\Semver\VersionParser;

        return $parser->parseConstraints($constraint)
            ->matches($parser->parseConstraints($version));
    }

    /**
     * Get the current package manifest.
     *
     * The raw manifest is cached from disk/build. The runtime ignore list
     * (from ignorePackageDiscoveriesFrom) is applied on every call, so
     * filtering works correctly even when the ignore list changes after
     * the manifest was first loaded (e.g., in test suites).
     */
    protected function getManifest(): array
    {
        if (is_null($this->rawManifest)) {
            if (! is_file($this->manifestPath)) {
                $this->build();
            }

            $this->rawManifest = is_file($this->manifestPath)
                ? $this->files->getRequire($this->manifestPath)
                : [];
        }

        $ignore = static::$packagesToIgnore;

        if (empty($ignore)) {
            return $this->rawManifest;
        }

        $ignoreAll = in_array('*', $ignore, true);

        return array_filter(
            $this->rawManifest,
            fn ($configuration, $package) => ! $ignoreAll && ! in_array($package, $ignore, true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Discover installed Hypervel package metadata.
     *
     * @param array<int, string> $baseIgnore
     */
    public static function discoverInstalledPackages(Filesystem $files, string $vendorPath, array $baseIgnore): array
    {
        $packages = [];

        if ($files->exists($path = $vendorPath . '/composer/installed.json')) {
            $installed = json_decode($files->get($path), true);

            if (is_array($installed)) {
                $installedPackages = $installed['packages'] ?? $installed;

                if (is_array($installedPackages)) {
                    $packages = $installedPackages;
                }
            }
        }

        $ignore = $baseIgnore;

        return (new Collection($packages))->filter(function (mixed $package): bool {
            return is_array($package) && is_string($package['name'] ?? null);
        })->mapWithKeys(function (array $package) use ($vendorPath) {
            $configuration = $package['extra']['hypervel'] ?? [];

            if (! is_array($configuration)) {
                $configuration = [];
            }

            return [static::formatPackageName($package['name'], $vendorPath) => [
                ...$configuration,
                'version' => $package['version'] ?? null,
            ]];
        })->each(function (array $configuration) use (&$ignore) {
            $ignore = array_merge($ignore, (array) ($configuration['dont-discover'] ?? []));
        })->reject(function (array $configuration, string $package) use ($ignore) {
            return in_array('*', $ignore, true) || in_array($package, $ignore, true);
        })->filter()->all();
    }

    /**
     * Build the manifest and write it to disk.
     */
    public function build(): void
    {
        $manifest = static::discoverInstalledPackages(
            $this->files,
            $this->vendorPath,
            $this->packagesToIgnore()
        );

        $this->write($manifest);

        $this->manifest = $manifest;
        $this->rawManifest = $manifest;
    }

    /**
     * Format the given package name.
     */
    protected function format(string $package): string
    {
        return static::formatPackageName($package, $this->vendorPath);
    }

    /**
     * Format the given package name with the given vendor path.
     */
    protected static function formatPackageName(string $package, string $vendorPath): string
    {
        return str_replace($vendorPath . '/', '', $package);
    }

    /**
     * Get the package names ignored by root composer metadata.
     *
     * @return array<int, string>
     */
    public static function packagesToIgnoreFromComposer(Filesystem $files, string $basePath): array
    {
        $ignore = static::rootHypervelExtra($files, $basePath, 'dont-discover');

        return is_array($ignore) ? $ignore : [];
    }

    /**
     * Get a root Composer extra.hypervel value.
     */
    public static function rootHypervelExtra(Filesystem $files, string $basePath, string $key): mixed
    {
        if (! $files->isFile($basePath . '/composer.json')) {
            return null;
        }

        $composer = json_decode($files->get(
            $basePath . '/composer.json'
        ), true);

        if (! is_array($composer)) {
            return null;
        }

        return $composer['extra']['hypervel'][$key] ?? null;
    }

    /**
     * Get the package names that should be ignored during build.
     *
     * Only includes project-level dont-discover from composer.json.
     * Runtime ignores (from ignorePackageDiscoveriesFrom) are applied
     * at read time in getManifest(), not at build time.
     */
    protected function packagesToIgnore(): array
    {
        return static::packagesToIgnoreFromComposer($this->files, $this->basePath);
    }

    /**
     * Set packages that should be ignored during discovery.
     *
     * Used by testbench to suppress package discovery at runtime
     * without modifying the project's composer.json.
     *
     * Boot or tests only. The list persists in a static property used during
     * package discovery; runtime use has no effect on already-discovered
     * providers.
     *
     * @param array<int, string> $packages
     */
    public static function ignorePackageDiscoveriesFrom(array $packages): void
    {
        static::$packagesToIgnore = $packages;
    }

    /**
     * Write the given manifest array to disk.
     *
     * @throws Exception
     */
    protected function write(array $manifest): void
    {
        if (! is_writable($dirname = dirname($this->manifestPath))) {
            throw new Exception("The {$dirname} directory must be present and writable.");
        }

        $this->files->replace(
            $this->manifestPath,
            '<?php return ' . var_export($manifest, true) . ';'
        );
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$packagesToIgnore = [];
    }
}
