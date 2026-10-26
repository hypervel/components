<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Integration\Queue\Fixtures\OutboxClaimTestCase;

#[RequiresDatabase('sqlite')]
class OutboxClaimTest extends OutboxClaimTestCase
{
    protected static string $databaseDirectory;

    /**
     * Create a file-backed database shared by independent claimant sessions.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::$databaseDirectory = ParallelTesting::tempDir('OutboxClaimTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory(static::$databaseDirectory);
        $filesystem->ensureDirectoryExists(static::$databaseDirectory);
        touch(static::$databaseDirectory . '/database.sqlite');
    }

    /**
     * Remove the file-backed database after its applications have shut down.
     */
    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(static::$databaseDirectory);

        parent::tearDownAfterClass();
    }

    /**
     * Use independent sessions instead of SQLite's shared in-memory connection.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $database = $config->string('database.default');

        if ($config->string("database.connections.{$database}.driver") === 'sqlite') {
            $config->set("database.connections.{$database}.database", static::$databaseDirectory . '/database.sqlite');
        }
    }
}
