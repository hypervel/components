<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\WithConfig;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('database.connections.second', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => false])]
class EloquentTransactionWithAfterCommitUsingRefreshDatabaseOnMultipleConnectionsTest extends EloquentTransactionWithAfterCommitUsingRefreshDatabaseTest
{
    protected function connectionsToTransact(): array
    {
        return [null, 'second'];
    }

    protected function afterRefreshingDatabase(): void
    {
        $this->artisan('migrate', ['--database' => 'second']);
    }

    public function testAfterCommitCallbacksAreCalledCorrectlyWhenNoAppTransaction(): void
    {
        $called = false;

        DB::afterCommit(function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testAfterCommitCallbacksAreCalledWithWrappingTransactionsCorrectly(): void
    {
        $calls = [];

        DB::transaction(function () use (&$calls) {
            DB::afterCommit(function () use (&$calls) {
                $calls[] = 'first transaction callback';
            });

            DB::connection('second')->transaction(function () use (&$calls) {
                DB::connection('second')->afterCommit(function () use (&$calls) {
                    $calls[] = 'second transaction callback';
                });
            });
        });

        $this->assertEquals([
            'second transaction callback',
            'first transaction callback',
        ], $calls);
    }
}
