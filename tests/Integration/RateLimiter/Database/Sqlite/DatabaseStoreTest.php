<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\RateLimiter\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Integration\RateLimiter\Database\DatabaseStoreTestCase;

#[RequiresDatabase('sqlite')]
#[WithMigration]
class DatabaseStoreTest extends DatabaseStoreTestCase
{
    protected static string $databaseDirectory;

    protected static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $filesystem = new Filesystem;
        static::$databaseDirectory = ParallelTesting::tempDir('RateLimiterDatabaseStoreTest');
        $filesystem->deleteDirectory(static::$databaseDirectory);
        $filesystem->ensureDirectoryExists(static::$databaseDirectory);

        static::$databasePath = static::$databaseDirectory . '/database.sqlite';
        touch(static::$databasePath);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(static::$databaseDirectory);

        parent::tearDownAfterClass();
    }

    // @TODO Remove these overrides when the first tagged Swoole release containing
    // https://github.com/swoole/swoole-src/pull/6140 is the minimum supported version.
    public function testConcurrentFirstUseAdmitsExactlyTheConfiguredCapacity(): void
    {
        $this->markTestSkipped('Requires the Swoole AIO scheduler fix from PR #6140.');
    }

    public function testConcurrentExistingStateDoesNotLoseUpdates(): void
    {
        $this->markTestSkipped('Requires the Swoole AIO scheduler fix from PR #6140.');
    }

    public function testConcurrentSlidingWindowFirstUseAdmitsExactlyTheConfiguredCapacity(): void
    {
        $this->markTestSkipped('Requires the Swoole AIO scheduler fix from PR #6140.');
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $connection = $config->string('database.default');

        // This worker-scoped path intentionally supersedes Testbench's earlier
        // parallel-database rewrite and must exist before its database probe.
        $config->set("database.connections.{$connection}.database", static::$databasePath);
    }
}
