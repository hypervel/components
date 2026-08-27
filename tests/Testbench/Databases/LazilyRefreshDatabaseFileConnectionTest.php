<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Override;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

#[ResetRefreshDatabaseState]
#[WithConfig('database.default', 'testing')]
#[WithConfig('database.connections.testing.pool.testing_enabled', false)]
class LazilyRefreshDatabaseFileConnectionTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithHypervelMigrations;

    protected static string $databaseDirectory;

    protected static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $filesystem = new Filesystem;
        static::$databaseDirectory = ParallelTesting::tempDir('LazilyRefreshDatabaseFileConnectionTest');
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

    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.connections.testing.database', static::$databasePath);
    }

    #[Test]
    public function itRunsTheTriggeringStatementInsideTheLazyTransaction(): void
    {
        $now = CarbonImmutable::now();

        DB::table('users')->insert([
            'name' => 'Orchestra',
            'email' => 'lazy-refresh@example.com',
            'password' => 'secret',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame(
            1,
            DB::table('users')->where('email', 'lazy-refresh@example.com')->count(),
        );
    }

    #[Test]
    #[Depends('itRunsTheTriggeringStatementInsideTheLazyTransaction')]
    public function itRollsBackTheStatementThatTriggeredLazyRefresh(): void
    {
        $this->assertSame(
            0,
            DB::table('users')->where('email', 'lazy-refresh@example.com')->count(),
        );
    }
}
