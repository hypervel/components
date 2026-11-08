<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\PHPStan\Properties\MigrationCache;
use Hypervel\PHPStan\Properties\SchemaConnection;
use Hypervel\PHPStan\Properties\SchemaTable;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\PHPStan\TestCase;
use SplFileInfo;

use function clearstatcache;
use function file_put_contents;
use function glob;
use function mkdir;
use function touch;
use function unlink;

class MigrationCacheTest extends TestCase
{
    protected string $cacheDirectory;

    protected Repository $config;

    protected ConnectionConfigResolver $resolver;

    protected MigrationCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDirectory = ParallelTesting::tempDir('PHPStanMigrationCacheTest');
        (new Filesystem)->deleteDirectory($this->cacheDirectory);
        mkdir($this->cacheDirectory, 0777, true);

        $container = new Container;
        $this->config = new Repository;
        $container->instance('config', $this->config);

        $this->resolver = new ConnectionConfigResolver;
        $this->resolver->setContainer($container);
        $this->cache = new MigrationCache($this->resolver, $this->cacheDirectory, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->cacheDirectory);

        parent::tearDown();
    }

    public function testRememberExecutesCallbackOnFirstCall(): void
    {
        $migrationFiles = [new SplFileInfo($this->createTempFile('mig1.php'))];
        $schemaFiles = [];

        $called = false;
        $result = $this->cache->remember($migrationFiles, $schemaFiles, [], static function () use (&$called) {
            $called = true;

            return self::schema(['users' => new SchemaConnection('users', 'mysql')]);
        });

        $this->assertTrue($called);
        $this->assertArrayHasKey('users', $result['connections']);
        $this->assertSame('users', $result['connections']['users']->name);
    }

    public function testRememberUsesCacheOnSecondCall(): void
    {
        $migrationFiles = [new SplFileInfo($this->createTempFile('mig1.php'))];
        $schemaFiles = [];

        $this->cache->remember($migrationFiles, $schemaFiles, [], static fn () => self::schema(['users' => new SchemaConnection('users', 'mysql')]));

        $called = false;
        $result = $this->cache->remember($migrationFiles, $schemaFiles, [], static function () use (&$called) {
            $called = true;

            return self::schema([]);
        });

        $this->assertFalse($called, 'Callback should not be called on cache hit');
        $this->assertArrayHasKey('users', $result['connections']);
    }

    public function testFingerprintChangesOnMtimeChange(): void
    {
        $file1 = $this->createTempFile('mig1.php');
        $migrationFiles = [new SplFileInfo($file1)];
        $schemaFiles = [];

        $this->cache->remember($migrationFiles, $schemaFiles, [], static fn () => self::schema(['users' => new SchemaConnection('users', 'mysql')]));

        touch($file1, $migrationFiles[0]->getMTime() + 1);
        clearstatcache(true, $file1);

        $called = false;
        $result = $this->cache->remember($migrationFiles, $schemaFiles, [], static function () use (&$called) {
            $called = true;

            return self::schema(['posts' => new SchemaConnection('posts', 'mysql')]);
        });

        $this->assertTrue($called, 'Callback should be called when file changed');
        $this->assertArrayHasKey('posts', $result['connections']);
        $this->assertArrayNotHasKey('users', $result['connections']);
    }

    /**
     * The modification time has whole-second resolution, so the content decides an edit within the same second.
     */
    public function testFingerprintChangesOnSameSecondContentChange(): void
    {
        $file1 = $this->createTempFile('mig1.php');
        $migrationFiles = [new SplFileInfo($file1)];
        $modified = $migrationFiles[0]->getMTime();

        $this->cache->remember($migrationFiles, [], [], static fn () => self::schema(['users' => new SchemaConnection('users', 'mysql')]));

        file_put_contents($file1, '<?php // changed');
        touch($file1, $modified);
        clearstatcache(true, $file1);

        $called = false;
        $this->cache->remember($migrationFiles, [], [], static function () use (&$called) {
            $called = true;

            return self::schema([]);
        });

        $this->assertTrue($called, 'Callback should be called when the content changed within the same second');
    }

    public function testDisabledCacheAlwaysExecutesCallback(): void
    {
        $cache = new MigrationCache($this->resolver, $this->cacheDirectory, false);
        $migrationFiles = [new SplFileInfo($this->createTempFile('mig1.php'))];

        $cache->remember($migrationFiles, [], [], static fn () => self::schema(['users' => new SchemaConnection('users', 'mysql')]));

        $called = false;
        $cache->remember($migrationFiles, [], [], static function () use (&$called) {
            $called = true;

            return self::schema(['users' => new SchemaConnection('users', 'mysql')]);
        });

        $this->assertTrue($called, 'Callback should be called when cache is disabled');
    }

    public function testOldCacheFilesAreCleanedUp(): void
    {
        $file1 = $this->createTempFile('mig1.php');
        $migrationFiles = [new SplFileInfo($file1)];
        $schemaFiles = [];

        $this->cache->remember($migrationFiles, $schemaFiles, [], static fn () => self::schema(['v1' => new SchemaConnection('v1', 'mysql')]));

        $files = glob($this->cacheDirectory . '/hypervel_migrations_*.cache');
        $this->assertCount(1, $files);
        $firstCacheFile = $files[0];

        touch($file1, $migrationFiles[0]->getMTime() + 1);
        clearstatcache(true, $file1);

        $this->cache->remember($migrationFiles, $schemaFiles, [], static fn () => self::schema(['v2' => new SchemaConnection('v2', 'mysql')]));

        $files = glob($this->cacheDirectory . '/hypervel_migrations_*.cache');
        $this->assertCount(1, $files, 'Should only be one cache file');
        $this->assertNotEquals($firstCacheFile, $files[0], 'New cache file should have different name');
    }

    /**
     * Migrations read connection names from config keys that only aggregating them finds, so a
     * changed value, or a key becoming present, invalidates the entry.
     */
    public function testChangedConfigKeysTheMigrationsReadInvalidateTheEntry(): void
    {
        $migrationFiles = [new SplFileInfo($this->createTempFile('mig1.php'))];
        $calls = 0;
        $aggregate = function () use (&$calls): array {
            ++$calls;
            $this->resolver->getConfiguredName('analytics.connection');

            return self::schema(['users' => new SchemaConnection('users', 'mysql')]);
        };

        $this->cache->remember($migrationFiles, [], [], $aggregate);
        $this->cache->remember($migrationFiles, [], [], $aggregate);
        $this->assertSame(1, $calls);

        $this->config->set('analytics.connection', null);
        $this->cache->remember($migrationFiles, [], [], $aggregate);
        $this->assertSame(2, $calls, 'A key that became present can no longer select a default');

        $this->config->set('analytics.connection', 'pgsql');
        $this->cache->remember($migrationFiles, [], [], $aggregate);
        $this->cache->remember($migrationFiles, [], [], $aggregate);
        $this->assertSame(3, $calls);
    }

    /**
     * The schema's config dependencies are declared from the consumed keys, so a hit in a new
     * analysis must consume the keys the migrations read as aggregating them did.
     */
    public function testCacheHitConsumesTheConfigKeysTheMigrationsRead(): void
    {
        $migrationFiles = [new SplFileInfo($this->createTempFile('mig1.php'))];

        $this->cache->remember($migrationFiles, [], [], function (): array {
            $this->resolver->getConfiguredName('analytics.connection');

            return self::schema(['users' => new SchemaConnection('users', 'mysql')]);
        });

        $container = new Container;
        $container->instance('config', $this->config);
        $resolver = new ConnectionConfigResolver;
        $resolver->setContainer($container);

        (new MigrationCache($resolver, $this->cacheDirectory, true))->remember($migrationFiles, [], [], function (): never {
            $this->fail('The cached schema should be used');
        });

        $this->assertSame(['analytics.connection'], $resolver->getConsumedKeys());
    }

    /**
     * The PHP files the migrations read, such as one declaring a constant, invalidate the entry when they
     * change or are deleted, and a hit returns them so their dependencies are declared again.
     */
    public function testChangedSourcesTheMigrationsReadInvalidateTheEntry(): void
    {
        $migrationFiles = [new SplFileInfo($this->createTempFile('mig1.php'))];
        $source = $this->createTempFile('Constants.php');
        $tableSource = $this->createTempFile('Author.php');
        $calls = 0;
        $aggregate = static function () use (&$calls, $source, $tableSource): array {
            ++$calls;
            $connection = new SchemaConnection('mysql', 'mysql');
            $connection->tables['users'] = new SchemaTable('users');
            $connection->tables['users']->sources = [$tableSource => true];

            return self::schema(['mysql' => $connection], [$source => true]);
        };

        $this->cache->remember($migrationFiles, [], [], $aggregate);
        $this->assertSame([$source => true], $this->cache->remember($migrationFiles, [], [], $aggregate)['sources']);
        $this->assertSame(1, $calls);

        file_put_contents($source, '<?php // changed');
        $this->cache->remember($migrationFiles, [], [], $aggregate);
        $this->assertSame(2, $calls);

        unlink($tableSource);
        $this->cache->remember($migrationFiles, [], [], $aggregate);
        $this->assertSame(3, $calls);
    }

    /**
     * Get an aggregation result with the given connections and sources.
     *
     * @param array<string, SchemaConnection> $connections
     * @param array<string, true> $sources
     * @return array{connections: array<string, SchemaConnection>, sources: array<string, true>}
     */
    private static function schema(array $connections, array $sources = []): array
    {
        return ['connections' => $connections, 'sources' => $sources];
    }

    private function createTempFile(string $name): string
    {
        $path = $this->cacheDirectory . '/' . $name;
        touch($path);

        return $path;
    }
}
