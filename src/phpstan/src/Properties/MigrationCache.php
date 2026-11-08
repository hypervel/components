<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Composer\InstalledVersions;
use OutOfBoundsException;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use SplFileInfo;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function getmypid;
use function glob;
use function hash;
use function hash_file;
use function implode;
use function is_array;
use function is_file;
use function rename;
use function serialize;
use function sort;
use function sprintf;
use function str_ends_with;
use function unlink;
use function unserialize;

use const LOCK_EX;
use const LOCK_UN;
use const PHP_VERSION;

#[AutowiredService]
final class MigrationCache
{
    private const string CACHE_PREFIX = 'hypervel_migrations_';

    /** Bump when the serialized entry, SchemaConnection/SchemaTable/SchemaColumn or the aggregation logic changes. */
    private const string CACHE_FORMAT_VERSION = '4';

    /**
     * Create a new migration cache instance.
     */
    public function __construct(
        private ConnectionConfigResolver $connectionConfigResolver,
        #[AutowiredParameter(ref: '%tmpDir%')]
        private string $cacheDirectory,
        #[AutowiredParameter(ref: '%enableMigrationCache%')]
        private bool $enabled = false,
    ) {
    }

    /**
     * Get the schema aggregated from the given inputs, with the PHP files besides the migrations it was read
     * from that are not one table's sources, aggregating and storing it when it is not cached.
     *
     * Migrations read connection names from config keys and declarations from PHP files that are known
     * only once they are aggregated, so an entry records those keys' states and the files' contents, and
     * is used only while they are the same.
     *
     * @param SplFileInfo[] $migrationFiles
     * @param SplFileInfo[] $schemaFiles
     * @param array<string, mixed> $configuration the normalized configuration the aggregation reads
     * @param callable(): array{connections: array<string, SchemaConnection>, sources: array<string, true>} $callback
     *
     * @return array{connections: array<string, SchemaConnection>, sources: array<string, true>}
     */
    public function remember(array $migrationFiles, array $schemaFiles, array $configuration, callable $callback): array
    {
        if (! $this->enabled) {
            return $callback();
        }

        $fingerprint = $this->generateFingerprint($migrationFiles, $schemaFiles, $configuration);
        $cachePath = $this->getCachePath($fingerprint);

        $cached = file_exists($cachePath) ? $this->readFromCache($cachePath) : null;

        if ($cached !== null) {
            return $cached;
        }

        $lockHandle = $this->acquireExclusiveLock();

        if ($lockHandle === false) {
            return $callback();
        }

        try {
            $cached = file_exists($cachePath) ? $this->readFromCache($cachePath) : null;

            if ($cached !== null) {
                return $cached;
            }

            $schema = $callback();
            $sources = $schema['sources'];

            foreach ($schema['connections'] as $connection) {
                foreach ($connection->tables as $table) {
                    $sources += $table->sources;
                }
            }

            $sourceHashes = [];

            foreach (array_keys($sources) as $source) {
                $sourceHashes[$source] = self::hashSource($source);
            }

            $tempPath = sprintf('%s.tmp.%d', $cachePath, getmypid());

            file_put_contents($tempPath, serialize([
                'configuredNames' => $this->connectionConfigResolver->getConfiguredNames(),
                'sourceHashes' => $sourceHashes,
                ...$schema,
            ]));
            rename($tempPath, $cachePath);

            $this->cleanupOldCacheFiles($fingerprint);

            return $schema;
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    /**
     * Read a cached schema, or return null when it is missing or the config keys or PHP files it read have changed.
     *
     * @return null|array{connections: array<string, SchemaConnection>, sources: array<string, true>}
     */
    private function readFromCache(string $path): ?array
    {
        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        /** @var array{configuredNames: array<string, array{bool, null|false|string}>, sourceHashes: array<string, false|string>, connections: array<string, SchemaConnection>, sources: array<string, true>}|false $data */
        $data = @unserialize($content);

        if (! is_array($data)) {
            return null;
        }

        foreach ($data['configuredNames'] as $key => $state) {
            if ($this->connectionConfigResolver->getConfiguredName($key) !== $state) {
                return null;
            }
        }

        foreach ($data['sourceHashes'] as $source => $hash) {
            if (self::hashSource($source) !== $hash) {
                return null;
            }
        }

        return ['connections' => $data['connections'], 'sources' => $data['sources']];
    }

    /**
     * Get the content hash of a PHP file the aggregation read, or false when it no longer exists.
     */
    private static function hashSource(string $path): false|string
    {
        return is_file($path) ? hash_file('xxh128', $path) : false;
    }

    /**
     * Delete the cached schemas of earlier inputs.
     */
    private function cleanupOldCacheFiles(string $currentFingerprint): void
    {
        $currentCacheFile = sprintf('%s%s.cache', self::CACHE_PREFIX, $currentFingerprint);
        $files = glob(sprintf('%s/%s*.cache', $this->cacheDirectory, self::CACHE_PREFIX)) ?: [];

        foreach (array_filter($files, static fn (string $file): bool => ! str_ends_with($file, $currentCacheFile)) as $file) {
            @unlink($file);
        }
    }

    /**
     * Generate the fingerprint of everything the aggregated schema depends on.
     *
     * @param SplFileInfo[] $migrationFiles
     * @param SplFileInfo[] $schemaFiles
     * @param array<string, mixed> $configuration
     */
    private function generateFingerprint(array $migrationFiles, array $schemaFiles, array $configuration): string
    {
        // The modification time has whole-second resolution, so two edits within one second
        // would reuse a stale schema without the content digest.
        $metadata = array_merge(
            array_map(static fn (SplFileInfo $file): string => sprintf('M:%s:%d:%s', $file->getPathname(), $file->getMTime(), hash_file('xxh128', $file->getPathname())), $migrationFiles),
            array_map(static fn (SplFileInfo $file): string => sprintf('S:%s:%d:%s', $file->getPathname(), $file->getMTime(), hash_file('xxh128', $file->getPathname())), $schemaFiles),
        );

        sort($metadata);

        return hash('xxh128', implode('|', [
            self::CACHE_FORMAT_VERSION,
            PHP_VERSION,
            self::packageVersion('hypervel/phpstan'),
            self::packageVersion('hypervel/schema-parser'),
            serialize($configuration),
            ...$metadata,
        ]));
    }

    /**
     * Get the installed version and reference of a package.
     */
    private static function packageVersion(string $package): string
    {
        try {
            return (InstalledVersions::getVersion($package) ?? 'unknown')
                . '@' . (InstalledVersions::getReference($package) ?? 'unknown');
        } catch (OutOfBoundsException) {
            return 'unknown';
        }
    }

    /**
     * Get the cache file of a fingerprint.
     */
    private function getCachePath(string $fingerprint): string
    {
        return sprintf('%s/%s%s.cache', $this->cacheDirectory, self::CACHE_PREFIX, $fingerprint);
    }

    /**
     * Get the file that serializes cache writes.
     */
    private function getLockPath(): string
    {
        return sprintf('%s/hypervel_migrations.lock', $this->cacheDirectory);
    }

    /**
     * Acquire the exclusive cache write lock.
     *
     * @return false|resource
     */
    private function acquireExclusiveLock(): mixed
    {
        $handle = @fopen($this->getLockPath(), 'c');

        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    /**
     * Release the cache write lock.
     *
     * @param resource $handle
     */
    private function releaseLock(mixed $handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
