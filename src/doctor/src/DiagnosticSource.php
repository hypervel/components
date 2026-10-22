<?php

declare(strict_types=1);

namespace Hypervel\Doctor;

use Composer\InstalledVersions;
use ReflectionClass;
use Throwable;

class DiagnosticSource
{
    /**
     * @var array<class-string, self>
     */
    private static array $sources = [];

    /**
     * Create a new diagnostic source instance.
     */
    public function __construct(
        public readonly ?string $package,
        public readonly ?string $file,
        public readonly ?string $relativeFile,
        public readonly bool $application,
    ) {
    }

    /**
     * Resolve the source metadata for the given diagnostic class.
     *
     * @param class-string $class
     */
    public static function resolve(string $class): self
    {
        if (! array_key_exists($class, self::$sources)) {
            self::$sources[$class] = self::resolveSource($class);
        }

        return self::$sources[$class];
    }

    /**
     * Resolve the source metadata for the given diagnostic instance.
     */
    public static function for(Diagnostic $diagnostic): self
    {
        $source = self::resolve($diagnostic::class);

        // A diagnostic may override package() to claim a different source...
        if ($diagnostic->package() !== $source->package) {
            return new self(
                package: $diagnostic->package(),
                file: $source->file,
                relativeFile: $source->relativeFile,
                application: $diagnostic->package() === null,
            );
        }

        return $source;
    }

    /**
     * Get the user-facing source label.
     */
    public function label(): string
    {
        return $this->package ?? 'application';
    }

    /**
     * Resolve the source metadata for the given diagnostic class.
     *
     * @param class-string $class
     */
    protected static function resolveSource(string $class): self
    {
        $file = self::classFile($class);

        if ($file === null) {
            return new self(
                package: null,
                file: null,
                relativeFile: null,
                application: true,
            );
        }

        $package = self::installedPackageForFile($file);
        $path = $package !== null ? self::packagePath($package) : null;

        return new self(
            package: $package,
            file: $file,
            relativeFile: $path !== null ? self::relativePath($path, $file) : null,
            application: self::isApplicationSource($class, $package),
        );
    }

    /**
     * Resolve the file path for the given class.
     *
     * @param class-string $class
     */
    protected static function classFile(string $class): ?string
    {
        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        if ($file === false) {
            return null;
        }

        $realPath = realpath($file);

        return $realPath === false ? null : $realPath;
    }

    /**
     * Determine whether a diagnostic class belongs to the application.
     *
     * @param class-string $class
     */
    protected static function isApplicationSource(string $class, ?string $package): bool
    {
        if (str_starts_with($class, __NAMESPACE__ . '\Diagnostics\\')) {
            return false;
        }

        $root = self::rootPackageName();

        return $root !== null && $package === $root;
    }

    /**
     * Find the installed Composer package that contains a file.
     */
    protected static function installedPackageForFile(string $file): ?string
    {
        $match = null;
        $matchLength = -1;

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            try {
                $path = InstalledVersions::getInstallPath($package);
            } catch (Throwable) {
                continue;
            }

            if (! is_string($path)) {
                continue;
            }

            $realPath = realpath($path);

            if ($realPath === false || ! self::contains($realPath, $file)) {
                continue;
            }

            $length = strlen($realPath);

            if ($length > $matchLength) {
                $match = $package;
                $matchLength = $length;
            }
        }

        return $match;
    }

    /**
     * Get the installation path for a Composer package.
     */
    protected static function packagePath(string $package): ?string
    {
        try {
            $path = InstalledVersions::getInstallPath($package);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($path)) {
            return null;
        }

        $realPath = realpath($path);

        return $realPath === false ? null : $realPath;
    }

    /**
     * Get the root Composer package name.
     */
    protected static function rootPackageName(): ?string
    {
        try {
            return InstalledVersions::getRootPackage()['name'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the file path relative to its package root.
     */
    protected static function relativePath(string $directory, string $file): string
    {
        if ($file === $directory) {
            return basename($file);
        }

        return substr($file, strlen($directory) + 1);
    }

    /**
     * Determine whether a directory contains a file.
     */
    protected static function contains(string $directory, string $file): bool
    {
        return $file === $directory || str_starts_with($file, $directory . DIRECTORY_SEPARATOR);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$sources = [];
    }
}
