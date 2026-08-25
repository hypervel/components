<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Watcher\Driver\ScanFileDriver;
use InvalidArgumentException;

class Option
{
    protected const string DEFAULT_DRIVER = ScanFileDriver::class;

    protected const int DEFAULT_SCAN_INTERVAL = 2000;

    /**
     * @param WatchPath[] $watchPaths
     */
    public function __construct(
        protected string $driver = self::DEFAULT_DRIVER,
        protected array $watchPaths = [],
        protected int $scanInterval = self::DEFAULT_SCAN_INTERVAL,
    ) {
        if ($this->scanInterval <= 0) {
            throw new InvalidArgumentException('The watcher scan interval must be greater than 0.');
        }
    }

    /**
     * Create an Option from a watcher config array.
     *
     * @param string $basePath Absolute base path for directory detection (typically base_path())
     * @param string[] $extraPaths Additional watch paths from CLI flags
     */
    public static function fromConfig(array $config, string $basePath, array $extraPaths = []): static
    {
        $rawPaths = array_merge($config['watch'] ?? [], $extraPaths);

        if ($rawPaths === []) {
            throw new InvalidArgumentException('The watcher requires at least one watch path.');
        }

        $normalizedPaths = [];
        foreach ($rawPaths as $rawPath) {
            $normalizedPaths[self::normalizeEntry($rawPath)] = true;
        }

        $watchPaths = array_map(
            fn (string $entry) => self::parseEntry($entry, $basePath),
            array_keys($normalizedPaths),
        );

        return new static(
            driver: $config['driver'] ?? self::DEFAULT_DRIVER,
            watchPaths: $watchPaths,
            scanInterval: $config['scan_interval'] ?? self::DEFAULT_SCAN_INTERVAL,
        );
    }

    /**
     * Normalize a watch config entry.
     */
    protected static function normalizeEntry(string $entry): string
    {
        if ($entry === '') {
            throw new InvalidArgumentException('Watcher paths must not be empty.');
        }

        if (str_starts_with($entry, '/')) {
            throw new InvalidArgumentException('Watcher paths must be relative to the application base path.');
        }

        $segments = [];
        foreach (explode('/', $entry) as $segment) {
            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $segments === [] ? '.' : implode('/', $segments);
    }

    /**
     * Parse a single watch config entry into a WatchPath.
     */
    protected static function parseEntry(string $entry, string $basePath): WatchPath
    {
        if (preg_match('/[*?{\[]/', $entry)) {
            return self::parseGlob($entry);
        }

        if (is_dir($basePath . '/' . $entry)) {
            return new WatchPath($entry, WatchPathType::Directory);
        }

        return new WatchPath($entry, WatchPathType::File);
    }

    /**
     * Parse a glob pattern into a WatchPath with base directory and pattern.
     */
    protected static function parseGlob(string $glob): WatchPath
    {
        preg_match('/[*?{\[]/', $glob, $matches, PREG_OFFSET_CAPTURE);
        $wildcardPosition = $matches[0][1];
        $prefix = substr($glob, 0, $wildcardPosition);
        $slashPosition = strrpos($prefix, '/');
        $baseDirectory = $slashPosition === false ? '.' : substr($prefix, 0, $slashPosition);

        return new WatchPath(
            path: $baseDirectory,
            type: WatchPathType::Directory,
            pattern: $glob,
        );
    }

    /**
     * Get all watch paths.
     *
     * @return WatchPath[]
     */
    public function getWatchPaths(): array
    {
        return $this->watchPaths;
    }

    /**
     * Get watch paths that are directories.
     *
     * @return WatchPath[]
     */
    public function getDirectoryPaths(): array
    {
        return array_values(array_filter(
            $this->watchPaths,
            fn (WatchPath $watchPath) => $watchPath->type === WatchPathType::Directory,
        ));
    }

    /**
     * Get watch paths that are individual files.
     *
     * @return WatchPath[]
     */
    public function getFilePaths(): array
    {
        return array_values(array_filter(
            $this->watchPaths,
            fn (WatchPath $watchPath) => $watchPath->type === WatchPathType::File,
        ));
    }

    /**
     * Get the watcher driver class name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get the scan interval in milliseconds.
     */
    public function getScanInterval(): int
    {
        return $this->scanInterval;
    }

    /**
     * Get the scan interval in seconds.
     */
    public function getScanIntervalSeconds(): float
    {
        return $this->getScanInterval() / 1000;
    }
}
