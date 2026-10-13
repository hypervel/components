<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Route;
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

    #[Test]
    public function itKeepsTheWrapperStartedByTheFirstHttpRequest(): void
    {
        $events = [];

        Route::post('/lazy-transaction', function () use (&$events): array {
            DB::transaction(function () use (&$events): void {
                DB::table('users')->insert([
                    'name' => 'Hypervel',
                    'email' => 'lazy-request@example.com',
                    'password' => 'secret',
                ]);

                DB::afterCommit(function () use (&$events): void {
                    $events[] = 'committed';
                });

                $this->assertSame([], $events);
            });

            return [];
        });

        Route::get('/lazy-transaction', fn (): array => [
            'users' => DB::table('users')->where('email', 'lazy-request@example.com')->count(),
            'transaction_level' => DB::transactionLevel(),
            'applicable_transaction' => DB::hasApplicableTransaction(),
        ]);

        $this->post('/lazy-transaction')->assertOk();
        $this->assertSame(['committed'], $events);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertCount(1, $this->app->make('db.transactions')->getPendingTransactions());
        $this->assertFalse(DB::hasApplicableTransaction());

        $this->get('/lazy-transaction')->assertOk()->assertExactJson([
            'users' => 1,
            'transaction_level' => 1,
            'applicable_transaction' => false,
        ]);
    }

    #[Test]
    #[Depends('itKeepsTheWrapperStartedByTheFirstHttpRequest')]
    public function itRollsBackTheWrapperStartedByTheHttpRequest(): void
    {
        $this->assertSame(0, DB::table('users')->where('email', 'lazy-request@example.com')->count());
    }
}
