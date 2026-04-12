<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Watcher\Driver\ScanFileDriver;

class Option
{
    /**
     * @param WatchPath[] $watchPaths
     */
    public function __construct(
        protected string $driver = ScanFileDriver::class,
        protected array $watchPaths = [],
        protected int $scanInterval = 2000,
    ) {
    }

    /**
     * Create an Option from a watcher config array.
     *
     * @param string $basePath Absolute base path for directory detection (typically base_path())
     * @param string[] $extraPaths Additional watch paths from CLI flags
     */
    public static function fromConfig(array $config, string $basePath, array $extraPaths = []): static
    {
        $rawPaths = array_unique(array_merge($config['watch'] ?? [], $extraPaths));

        $watchPaths = array_map(
            fn (string $entry) => self::parseEntry($entry, $basePath),
            array_values($rawPaths),
        );

        return new static(
            driver: $config['driver'] ?? ScanFileDriver::class,
            watchPaths: $watchPaths,
            scanInterval: (int) ($config['scan_interval'] ?? 2000),
        );
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
        $wildcardPos = $matches[0][1];
        $baseDir = rtrim(substr($glob, 0, $wildcardPos), '/');

        return new WatchPath(
            path: $baseDir ?: '.',
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
            fn (WatchPath $p) => $p->type === WatchPathType::Directory,
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
            fn (WatchPath $p) => $p->type === WatchPathType::File,
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
        return $this->scanInterval > 0 ? $this->scanInterval : 2000;
    }

    /**
     * Get the scan interval in seconds.
     */
    public function getScanIntervalSeconds(): float
    {
        return $this->getScanInterval() / 1000;
    }
}
