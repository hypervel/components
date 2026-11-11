<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use Dotenv\Parser\Parser as DotenvParser;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\SQLiteDatabase;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\PackageManifest;
use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\RuntimeInputsResultCacheValueExtension;
use Hypervel\Support\Composer;
use Hypervel\Testbench\Bootstrap\LoadConfiguration as TestbenchLoadConfiguration;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Config as TestbenchConfig;
use Hypervel\View\FileViewFinder;
use LogicException;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionClass;

use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function class_exists;
use function dirname;
use function explode;
use function file_get_contents;
use function get_declared_classes;
use function get_declared_interfaces;
use function get_declared_traits;
use function get_included_files;
use function is_file;
use function is_string;
use function json_decode;
use function preg_match;
use function realpath;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * The inputs the booted application was built from, declared as one dependency by everything
 * that reads the booted application.
 *
 * Bindings, macros, drivers, commands and registrations come from code the boot ran, which no
 * analyzed file references, so the files the boot loaded and the environment variables it can
 * read are tracked instead. A Testbench package boots from a disposable runtime copy, so copied
 * paths are tracked through their skeleton source.
 *
 * @internal
 */
#[AutowiredService]
class RuntimeInputs
{
    /**
     * The application the analyzer booted.
     */
    protected static ?Application $application = null;

    /**
     * The files loaded once the application had booted.
     *
     * @var list<string>
     */
    protected static array $includedFiles = [];

    /**
     * The lowercased names of the classes, interfaces and traits loaded once the application had booted.
     *
     * Analysis loads more classes, which would make keys differ between processes.
     *
     * @var array<string, true>
     */
    protected static array $classes = [];

    /**
     * The files declaring the classes, interfaces and traits loaded once the application had booted.
     *
     * @var array<string, true>
     */
    protected static array $classFiles = [];

    /**
     * The environment file an application boot selects from, or null for a Testbench package,
     * whose application loads no environment file.
     */
    protected static ?string $environmentFile = null;

    /**
     * Whether the analyzer booted a Testbench application for a package rather than an application's own.
     */
    protected static bool $packageApplication = false;

    /**
     * The value-dependency key, built on first use.
     */
    private ?string $key = null;

    /**
     * The value-dependency key of the view directories' file names, built on first use.
     */
    private ?string $viewsKey = null;

    /**
     * The owned runtime copy and the skeleton it was made from, or false when there is none.
     *
     * @var null|array{string, string}|false
     */
    private array|false|null $runtimeCopy = null;

    /**
     * The tracked path for each runtime path.
     *
     * @var array<string, string>
     */
    private array $sourcePaths = [];

    /**
     * The tracked source files of each class below a base class.
     *
     * @var array<string, list<string>>
     */
    private array $classSources = [];

    /**
     * Create a new runtime inputs instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private DeclarationDependencyTracker $declarationDependencyTracker,
        private EnvironmentVariableScanner $environmentVariableScanner,
        private ConfigSourceResolver $configSourceResolver,
        private RuntimeInputsResultCacheValueExtension $valueExtension,
        #[AutowiredParameter(ref: '%tmpDir%')]
        private string $tmpDir,
    ) {
    }

    /**
     * Capture what the boot loaded.
     *
     * Called by the analyzer's bootstrap file once the application has booted, before
     * analysis loads anything else.
     */
    public static function captureBoot(Application $app, ?string $environmentFile, bool $packageApplication): void
    {
        static::$application = $app;
        static::$includedFiles = get_included_files();
        static::$environmentFile = $environmentFile;
        static::$packageApplication = $packageApplication;

        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $class) {
            static::$classes[strtolower($class)] = true;
            $file = (new ReflectionClass($class))->getFileName();

            if ($file !== false) {
                static::$classFiles[$file] = true;
            }
        }
    }

    /**
     * Determine whether the analyzer booted a Testbench application for a package.
     */
    public function isPackageApplication(): bool
    {
        return static::$packageApplication;
    }

    /**
     * Declare that the analysis of the current file, or what a class declares, depends on the booted application.
     *
     * @param ClassReflection|(DependencyTracker&Scope) $dependent
     */
    public function track(Scope|ClassReflection $dependent): void
    {
        if ($dependent instanceof ClassReflection) {
            $this->declarationDependencyTracker->trackValueDependency($dependent, RuntimeInputsResultCacheValueExtension::class, $this->key());
        } else {
            $dependent->trackValueDependency(RuntimeInputsResultCacheValueExtension::class, $this->key());
        }
    }

    /**
     * Declare that the dependent depends on what a class the booted application selected declares.
     *
     * Resolving a binding can load its class after boot, outside the runtime inputs, and a
     * declared type is read without loading its class at all.
     *
     * @param ClassReflection|(DependencyTracker&Scope) $dependent
     */
    public function trackClass(Scope|ClassReflection $dependent, string $className): void
    {
        if ($dependent instanceof ClassReflection) {
            $this->declarationDependencyTracker->trackClassDependency($dependent, $className);
        } else {
            $dependent->trackClassDependency($className);
        }
    }

    /**
     * Declare that the dependent depends on the declarations and code of a class the booted application selected and the analysis runs.
     *
     * Method bodies and property defaults are not declarations, so the files declaring the class and its
     * parents below the base class, with their traits and the constants their defaults read, are tracked too.
     *
     * @param ClassReflection|(DependencyTracker&Scope) $dependent
     */
    public function trackSources(Scope|ClassReflection $dependent, string $className, string $baseClass): void
    {
        $this->trackClass($dependent, $className);

        $files = $this->classSources[$className . "\0" . $baseClass] ??= array_map(
            $this->sourcePath(...),
            SourceFiles::forInstance($this->reflectionProvider, $this->reflectionProvider->getClass($className), $baseClass),
        );

        foreach ($files as $file) {
            if ($dependent instanceof ClassReflection) {
                $this->declarationDependencyTracker->trackFileDependency($dependent, $file);
            } else {
                $dependent->trackFileDependency($file);
            }
        }
    }

    /**
     * Declare that the dependent depends on which views exist.
     *
     * Only the names of the files in the booted finder's directories matter, so editing a
     * template does not invalidate it. The finder's directories come from the boot.
     *
     * @param ClassReflection|(DependencyTracker&Scope) $dependent
     */
    public function trackViews(Scope|ClassReflection $dependent): void
    {
        $this->track($dependent);

        $this->viewsKey ??= $this->createViewsKey();

        if ($dependent instanceof ClassReflection) {
            $this->declarationDependencyTracker->trackValueDependency($dependent, RuntimeInputsResultCacheValueExtension::class, $this->viewsKey);
        } else {
            $dependent->trackValueDependency(RuntimeInputsResultCacheValueExtension::class, $this->viewsKey);
        }
    }

    /**
     * Get the path to track for a file the analysis reads.
     *
     * A file in the runtime copy is tracked through its skeleton source while the two are
     * identical. A file the copy changed or added has no known source and keeps its runtime
     * path, which disappears with the copy, so it is analyzed again on every run.
     */
    public function sourcePath(string $path): string
    {
        return $this->mapRuntimePath($path, 'file', RuntimeInputsResultCacheValueExtension::fileDigest(...));
    }

    /**
     * Get the directory to track for files in it the analysis reads, as sourcePath() does for a file.
     */
    public function sourceDirectory(string $directory, string $pattern = '*'): string
    {
        return $this->mapRuntimePath(
            $directory,
            "directory\t" . $pattern,
            static fn (string $path): string => RuntimeInputsResultCacheValueExtension::directoryDigest($path, $pattern),
        );
    }

    /**
     * Get the value-dependency key, built once per process.
     */
    private function key(): string
    {
        return $this->key ??= $this->createKey();
    }

    /**
     * Create the value-dependency key of the names of the files in the booted view finder's directories.
     */
    private function createViewsKey(): string
    {
        $finder = $this->application()->make('view')->getFinder();
        $directories = $finder->getPaths();

        if ($finder instanceof FileViewFinder) {
            foreach ($finder->getHints() as $paths) {
                $directories = [...$directories, ...$paths];
            }
        }

        $listings = [];

        foreach ($directories as $directory) {
            $listings[] = $this->mapRuntimePath(realpath($directory) ?: $directory, 'listing', RuntimeInputsResultCacheValueExtension::listingDigest(...));
        }

        return $this->valueExtension->createKey(listings: $listings);
    }

    /**
     * Create the value-dependency key from what the boot loaded.
     */
    private function createKey(): string
    {
        $app = $this->application();
        $analyzerPath = (string) realpath(dirname(__DIR__, 2));
        $tmpPath = realpath($this->tmpDir) ?: $this->tmpDir;
        $rootPath = (string) realpath(Composer::rootPackage()['install_path']);
        $vendorPath = realpath(Composer::vendorPath($rootPath)) ?: Composer::vendorPath($rootPath);
        $pathPackages = $this->pathPackageDirectories($vendorPath);
        [$providerPackages, $providerFiles] = $this->providerSources($app, $vendorPath);

        $files = [];
        $scannedFiles = [];

        foreach (static::$includedFiles as $file) {
            // PHPStan's own files. Its compiled container, in its temporary directory, embeds the
            // run's environment, such as the restart's random PHPRC path, whenever it is recompiled.
            if (str_starts_with($file, 'phar://') || str_starts_with($file, $analyzerPath . '/') || str_starts_with($file, $tmpPath . '/')) {
                continue;
            }

            $package = str_starts_with($file, $vendorPath . '/') ? $this->packageDirectory($file, $vendorPath) : null;

            // A versioned install changes Composer's installed metadata, tracked below, so its files
            // are not hashed. Its scripts, such as framework config files and helpers, still read
            // environment variables, and so can the registration code of a package whose provider
            // the boot ran. Its other classes read the environment at run time, if at all.
            if ($package !== null && ! isset($pathPackages[$package])) {
                if (! isset(static::$classFiles[$file]) || isset($providerPackages[$package]) || isset($providerFiles[$file])) {
                    $scannedFiles[] = $file;
                }

                continue;
            }

            $files[] = $file;
            $scannedFiles[] = $file;
        }

        foreach (array_keys([$vendorPath => true, $app->make(PackageManifest::class)->vendorPath => true]) as $vendor) {
            $files[] = $vendor . '/composer/installed.json';
            $files[] = $vendor . '/composer/installed.php';
        }

        $files[] = $app->basePath('composer.json');
        $files[] = $app->bootstrapPath('providers.php');
        $files = array_merge($files, $this->environmentFiles($app), $this->testbenchConfigurationFiles());

        $outputs = [];

        foreach ([$app->getCachedConfigPath(), $app->getCachedPackagesPath(), $app->getCachedRoutesPath(), $app->getCachedEventsPath()] as $output) {
            $outputs[realpath($output) ?: $output] = true;
        }

        $trackedFiles = [];

        foreach ([...$files, ...array_keys($outputs)] as $file) {
            $file = realpath($file) ?: $file;
            $source = $this->sourcePath($file);

            // The boot writes these caches into the runtime copy from the inputs tracked here.
            if ($source === $file && isset($outputs[$file]) && $this->isInRuntimeCopy($file)) {
                continue;
            }

            $trackedFiles[] = $source;
        }

        $directories = [];

        // The boot reads the configuration files only while configuration is not cached.
        foreach ($app->configurationIsCached() ? [] : $this->configSourceResolver->getBootedConfigDirectories() as $directory) {
            $directories[$this->sourceDirectory($directory, ConfigSourceResolver::CONFIG_FILE_PATTERN)] = ConfigSourceResolver::CONFIG_FILE_PATTERN;
        }

        $variables = $this->environmentVariableScanner->scan($scannedFiles, static::$classes);

        return $this->valueExtension->createKey(
            files: $trackedFiles,
            presenceFiles: $this->presenceFiles($app),
            directories: $directories,
            variables: [...$variables['names'], ...$this->interpolatedVariables($app)],
            variablePatterns: $variables['patterns'],
        );
    }

    /**
     * Get the directories of the path-repository packages Composer mirrored into the vendor directory rather than linked.
     *
     * @return array<string, true>
     */
    private function pathPackageDirectories(string $vendorPath): array
    {
        $installed = json_decode((string) file_get_contents($vendorPath . '/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        $directories = [];

        foreach ($installed['packages'] as $package) {
            if (($package['dist']['type'] ?? null) === 'path') {
                $directories[(string) realpath($vendorPath . '/composer/' . $package['install-path'])] = true;
            }
        }

        return $directories;
    }

    /**
     * Get the vendor package directories that supply a loaded provider, and the files of every
     * loaded provider with its parents and traits.
     *
     * @return array{array<string, true>, array<string, true>}
     */
    private function providerSources(Application $app, string $vendorPath): array
    {
        $pending = array_keys($app->getLoadedProviders());
        $packages = [];
        $files = [];
        $seen = [];

        foreach ($pending as $provider) {
            $file = (string) (new ReflectionClass($provider))->getFileName();

            if (str_starts_with($file, $vendorPath . '/')) {
                $packages[$this->packageDirectory($file, $vendorPath)] = true;
            }
        }

        while (($class = array_pop($pending)) !== null) {
            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;
            $reflection = new ReflectionClass($class);
            $files[(string) $reflection->getFileName()] = true;
            $parent = $reflection->getParentClass();
            $pending = [...$pending, ...$reflection->getTraitNames(), ...($parent === false ? [] : [$parent->getName()])];
        }

        return [$packages, $files];
    }

    /**
     * Get the directory of the vendor package a vendor file belongs to.
     *
     * Composer installs every package two levels below the vendor directory.
     */
    private function packageDirectory(string $file, string $vendorPath): string
    {
        $segments = explode('/', substr($file, strlen($vendorPath) + 1), 3);

        return $vendorPath . '/' . $segments[0] . '/' . ($segments[1] ?? '');
    }

    /**
     * Determine whether the boot loaded an environment file.
     *
     * A Testbench package's application loads none, and LoadEnvironmentVariables skips the
     * file while configuration is cached, so the tracked configuration cache decides instead.
     */
    private function loadsEnvironmentFile(Application $app): bool
    {
        return static::$environmentFile !== null && ! $app->configurationIsCached();
    }

    /**
     * Get the environment files an application boot can load.
     *
     * LoadEnvironmentVariables loads the file named for the incoming APP_ENV when it exists,
     * else the configured file.
     *
     * @return list<string>
     */
    private function environmentFiles(Application $app): array
    {
        if (! $this->loadsEnvironmentFile($app)) {
            return [];
        }

        $files = [static::$environmentFile, $app->environmentFilePath()];
        $environment = EnvironmentSnapshot::get('APP_ENV');

        if ($environment !== null && $environment !== '') {
            $files[] = static::$environmentFile . '.' . $environment;
        }

        return $files;
    }

    /**
     * Get the variables the loaded environment file interpolates, such as DEPLOY_QUEUE in QUEUE_CONNECTION=${DEPLOY_QUEUE}.
     *
     * @return list<string>
     */
    private function interpolatedVariables(Application $app): array
    {
        if (! $this->loadsEnvironmentFile($app)) {
            return [];
        }

        $file = $app->environmentFilePath();

        if (! is_file($file)) {
            return [];
        }

        $variables = [];

        foreach ((new DotenvParser)->parse((string) file_get_contents($file)) as $entry) {
            if ($entry->getValue()->isEmpty()) {
                continue;
            }

            $value = $entry->getValue()->get();

            foreach ($value->getVars() as $position) {
                // The pattern dotenv's Resolver replaces.
                if (preg_match('/\A\${([a-zA-Z0-9_.]+)}/', substr($value->getChars(), $position), $matches) === 1) {
                    $variables[] = $matches[1];
                }
            }
        }

        return $variables;
    }

    /**
     * Get the Testbench configuration files a package boot reads.
     *
     * The loaded configuration names its directory: an earlier bootstrap in the process
     * may have loaded it from elsewhere than the analyzer asked for.
     *
     * @return list<string>
     */
    private function testbenchConfigurationFiles(): array
    {
        if (! static::$packageApplication) {
            return [];
        }

        $configuration = Bootstrapper::getConfiguration();
        $path = $configuration instanceof TestbenchConfig ? $configuration->getConfigurationPath() : null;

        return $path === null ? [] : [$path . '/testbench.yaml', $path . '/testbench.yaml.example', $path . '/testbench.yaml.dist'];
    }

    /**
     * Get the files whose presence the boot checks.
     *
     * Testbench's configuration loader makes the testing connection the default when the
     * configured SQLite database file is missing, so the file matters whatever the default
     * is now. Only whether it exists matters; the database is never read.
     *
     * @return list<string>
     */
    private function presenceFiles(Application $app): array
    {
        if (! $app->make(LoadConfiguration::class) instanceof TestbenchLoadConfiguration) {
            return [];
        }

        $database = $app->make('config')->get('database.connections.sqlite.database');

        if (! is_string($database) || SQLiteDatabase::isInMemory($database) || SQLiteDatabase::isUri($database)) {
            return [];
        }

        // The copy is taken just before the boot checks the file, so the skeleton's file decides.
        $runtimeCopy = $this->runtimeCopy();

        if ($runtimeCopy !== false && str_starts_with($database, $runtimeCopy[0] . '/')) {
            return [$runtimeCopy[1] . substr($database, strlen($runtimeCopy[0]))];
        }

        return [$database];
    }

    /**
     * Map a path in the runtime copy to its skeleton source when both have the same digest.
     *
     * @param string $comparison what the digest compares, which separates the memoized results
     * @param callable(string): string $digest
     */
    private function mapRuntimePath(string $path, string $comparison, callable $digest): string
    {
        $runtimeCopy = $this->runtimeCopy();

        if ($runtimeCopy === false || ! str_starts_with($path, $runtimeCopy[0] . '/')) {
            return $path;
        }

        $source = $runtimeCopy[1] . substr($path, strlen($runtimeCopy[0]));

        return $this->sourcePaths[$comparison . "\0" . $path] ??= $digest($path) === $digest($source) ? $source : $path;
    }

    /**
     * Determine whether a path is in the owned runtime copy.
     */
    private function isInRuntimeCopy(string $path): bool
    {
        $runtimeCopy = $this->runtimeCopy();

        return $runtimeCopy !== false && str_starts_with($path, $runtimeCopy[0] . '/');
    }

    /**
     * Get the realpaths of the runtime copy this process owns and of the skeleton it was made from.
     *
     * @return array{string, string}|false
     */
    private function runtimeCopy(): array|false
    {
        if ($this->runtimeCopy !== null) {
            return $this->runtimeCopy;
        }

        if (! class_exists(Bootstrapper::class)) {
            return $this->runtimeCopy = false;
        }

        $basePath = $this->application()->basePath();
        $sourcePath = Bootstrapper::runtimeSourcePath();

        if ($sourcePath === null || ! Bootstrapper::ownsRuntimePath($basePath)) {
            return $this->runtimeCopy = false;
        }

        return $this->runtimeCopy = [(string) realpath($basePath), (string) realpath($sourcePath)];
    }

    /**
     * Get the booted application.
     */
    private function application(): Application
    {
        return static::$application ?? throw new LogicException('The analyzer bootstrap file did not capture the booted application.');
    }
}
