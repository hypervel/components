<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class HttpRequestTransactionsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Configure a secondary connection with independent database sessions.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $directory = ParallelTesting::tempDir('HttpRequestTransactionsTest');
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists($directory);
        $filesystem->put($directory . '/secondary.sqlite', '');

        $app->make('config')->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => $directory . '/secondary.sqlite',
            'pool' => ['testing_enabled' => true, 'max_connections' => 2],
        ]);
    }

    /**
     * Remove the secondary database after connections have been released.
     */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory(ParallelTesting::tempDir('HttpRequestTransactionsTest'));
        }
    }

    #[DataProvider('transactionOutcomes')]
    public function testRequestCallbacksWaitForItsApplicationTransaction(bool $commit): void
    {
        $events = [];

        Route::get('/transaction', function () use ($commit, &$events): array {
            DB::beginTransaction();
            DB::afterCommit(function () use (&$events): void {
                $events[] = 'committed';
            });

            $this->assertSame([], $events);
            $this->assertTrue(DB::hasApplicableTransaction());

            $commit ? DB::commit() : DB::rollBack();

            return [];
        });

        $this->get('/transaction')->assertOk();

        $this->assertSame($commit ? ['committed'] : [], $events);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertFalse(DB::hasApplicableTransaction());
    }

    #[DataProvider('transactionOutcomes')]
    public function testRequestCallbacksFollowTheParentApplicationTransaction(bool $commit): void
    {
        $events = [];
        DB::beginTransaction();

        Route::get('/transaction', function () use (&$events): array {
            $this->assertTrue(DB::hasApplicableTransaction());

            DB::transaction(function () use (&$events): void {
                DB::afterCommit(function () use (&$events): void {
                    $events[] = 'committed';
                });
            });

            return [];
        });

        try {
            $this->get('/transaction')->assertOk();
            $this->assertSame([], $events);

            $commit ? DB::commit() : DB::rollBack();

            $this->assertSame($commit ? ['committed'] : [], $events);
        } finally {
            DB::rollBack(1);
        }
    }

    public function testRequestExceptionPreservesTheRetainedTransactionRecords(): void
    {
        $this->withoutExceptionHandling();
        $exception = new RuntimeException('The request failed.');
        $events = [];

        Route::get('/transaction', function () use ($exception, &$events): never {
            DB::beginTransaction();
            DB::afterRollBack(function () use (&$events): void {
                $events[] = 'rolled back';
            });

            throw $exception;
        });

        try {
            try {
                $this->get('/transaction');
                $this->fail('The request exception was not propagated.');
            } catch (RuntimeException $caught) {
                $this->assertSame($exception, $caught);
            }

            $this->assertSame(2, DB::transactionLevel());
            $this->assertTrue(DB::hasApplicableTransaction());
        } finally {
            DB::rollBack(1);
        }

        $this->assertSame(['rolled back'], $events);
    }

    #[DataProvider('transactionOutcomes')]
    public function testPooledRequestCallbacksWaitForTheOuterTransaction(bool $commit): void
    {
        $events = [];

        Route::get('/transaction', function () use ($commit, &$events): array {
            $connection = DB::connection('secondary');
            $connection->beginTransaction();
            $connection->transaction(function () use ($connection, &$events): void {
                $connection->afterCommit(function () use (&$events): void {
                    $events[] = 'committed';
                });
            });

            $this->assertSame([], $events);
            $commit ? $connection->commit() : $connection->rollBack();

            return [];
        });

        $this->get('/transaction')->assertOk();

        $this->assertSame($commit ? ['committed'] : [], $events);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertFalse(DB::hasApplicableTransaction());
        $this->assertCount(1, $this->app->make(DatabaseTransactionsManager::class)->getPendingTransactions());
    }

    /**
     * Supply both transaction outcomes.
     */
    public static function transactionOutcomes(): array
    {
        return ['commit' => [true], 'rollback' => [false]];
    }

    public function testPooledRequestDoesNotCommitItsParentsIndependentSession(): void
    {
        $events = [];
        $parent = DB::connection('secondary');
        $parent->beginTransaction();
        $parent->afterCommit(function () use (&$events): void {
            $events[] = 'parent';
        });

        Route::get('/transaction', function () use ($parent, &$events): array {
            $connection = DB::connection('secondary');
            $this->assertNotSame($parent->getPdo(), $connection->getPdo());
            $connection->transaction(function () use ($connection, &$events): void {
                $connection->afterCommit(function () use (&$events): void {
                    $events[] = 'request';
                });
            });

            return [];
        });

        try {
            $this->get('/transaction')->assertOk();

            $this->assertSame(['request'], $events);
            $this->assertSame(1, $parent->transactionLevel());
            $this->assertTrue($parent->hasApplicableTransaction());

            $parent->commit();
            $this->assertSame(['request', 'parent'], $events);
        } finally {
            $parent->rollBack();
        }
    }
}
