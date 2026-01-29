<?php

declare(strict_types=1);

namespace Illuminate\Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Attributes\WithConfig;

use function Orchestra\Testbench\artisan;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('database.connections.second', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => false])]
class EloquentTransactionWithAfterCommitUsingRefreshDatabaseOnMultipleConnectionsTest extends EloquentTransactionWithAfterCommitUsingRefreshDatabaseTest
{
    protected function connectionsToTransact()
    {
        return [null, 'second'];
    }

    protected function afterRefreshingDatabase()
    {
        artisan($this, 'migrate', ['--database' => 'second']);
    }

    public function testAfterCommitCallbacksAreCalledCorrectlyWhenNoAppTransaction()
    {
        $called = false;

        DB::afterCommit(function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testAfterCommitCallbacksAreCalledWithWrappingTransactionsCorrectly()
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
