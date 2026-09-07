<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Exception;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Env;
use Hypervel\Support\Json;
use RuntimeException;
use UnexpectedValueException;

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
        if (in_array('*', $baseIgnore, true)) {
            return [];
        }

        $path = $vendorPath . '/composer/installed.json';

        if (! $files->exists($path)) {
            return [];
        }

        $installed = Json::decode($files->get($path));

        if (! is_array($installed)) {
            throw new UnexpectedValueException("Composer metadata [{$path}] must contain an array.");
        }

        $installedPackages = array_key_exists('packages', $installed)
            ? $installed['packages']
            : $installed;

        if (! is_array($installedPackages)) {
            throw new UnexpectedValueException("Composer metadata [{$path}] member [packages] must contain an array.");
        }

        $packages = [];
        $ignore = $baseIgnore;

        foreach ($installedPackages as $index => $package) {
            $location = "package [{$index}] in [{$path}]";

            if (! is_array($package)) {
                throw new UnexpectedValueException("Composer metadata {$location} must contain an array.");
            }

            $name = static::packageName($package, $location, $vendorPath);

            if (in_array($name, $baseIgnore, true)) {
                continue;
            }

            $version = $package['version'] ?? null;

            if (! is_null($version) && ! is_string($version)) {
                throw new UnexpectedValueException(
                    "Composer metadata package [{$name}] in [{$path}] member [version] must be a string or null."
                );
            }

            $configuration = static::hypervelExtra($package, $location);

            $packages[$name] = [
                ...$configuration,
                'version' => $version,
            ];
        }

        foreach ($packages as $configuration) {
            $ignore = array_merge($ignore, (array) ($configuration['dont-discover'] ?? []));
        }

        $ignoreAll = in_array('*', $ignore, true);

        return array_filter(
            $packages,
            fn (int|string $package): bool => ! $ignoreAll
                && ! in_array((string) $package, $ignore, true),
            ARRAY_FILTER_USE_KEY
        );
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
     * Get the formatted package name from Composer metadata.
     */
    protected static function packageName(array $package, string $location, string $vendorPath): string
    {
        $name = $package['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new UnexpectedValueException(
                "Composer metadata {$location} member [name] must be a non-empty string."
            );
        }

        $formatted = static::formatPackageName($name, $vendorPath);

        if ($formatted === '') {
            throw new UnexpectedValueException("Composer metadata {$location} has an empty formatted package name.");
        }

        return $formatted;
    }

    /**
     * Get the Hypervel configuration from Composer metadata.
     */
    protected static function hypervelExtra(array $package, string $location): array
    {
        $extra = $package['extra'] ?? null;

        if (! is_array($extra) || ! array_key_exists('hypervel', $extra)) {
            return [];
        }

        if (! is_array($extra['hypervel'])) {
            throw new UnexpectedValueException(
                "Composer metadata {$location} member [extra.hypervel] must contain an array."
            );
        }

        return $extra['hypervel'];
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
        $path = $basePath . '/composer.json';

        if (! $files->isFile($path)) {
            return null;
        }

        $composer = Json::decode($files->get($path));

        if (! is_array($composer)) {
            throw new UnexpectedValueException("Composer metadata [{$path}] must contain an array.");
        }

        $hypervel = static::hypervelExtra($composer, "root package in [{$path}]");

        return $hypervel[$key] ?? null;
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
