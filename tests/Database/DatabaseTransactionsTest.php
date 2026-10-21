<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Exception;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Foundation\Testing\DatabaseTransactionsManager as TestingTransactionsManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Throwable;

class DatabaseTransactionsTest extends TestCase
{
    /**
     * Setup the database schema.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'second_connection');

        $db->setAsGlobal();

        $this->createSchema();
    }

    protected function createSchema(): void
    {
        foreach (['default', 'second_connection'] as $connection) {
            $this->schema($connection)->create('users', function ($table) {
                $table->increments('id');
                $table->string('name')->nullable();
                $table->string('value')->nullable();
            });
        }
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        foreach (['default', 'second_connection'] as $connection) {
            $this->schema($connection)->drop('users');
        }

        parent::tearDown();
    }

    public function testTransactionIsRecordedAndCommitted(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $transactionManager->expects('begin')->with('default', 1);
        $transactionManager->expects('commit')->with('default', 1, 0);

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->transaction(function () {
            $this->connection()->table('users')->where(['name' => 'zain'])->update([
                'value' => 2,
            ]);
        });
    }

    public function testTransactionIsRecordedAndCommittedUsingTheSeparateMethods(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $transactionManager->expects('begin')->with('default', 1);
        $transactionManager->expects('commit')->with('default', 1, 0);

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->beginTransaction();
        $this->connection()->table('users')->where(['name' => 'zain'])->update([
            'value' => 2,
        ]);
        $this->connection()->commit();
    }

    public function testNestedTransactionIsRecordedAndCommitted(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $transactionManager->expects('begin')->with('default', 1);
        $transactionManager->expects('begin')->with('default', 2);
        $transactionManager->expects('commit')->with('default', 2, 1);
        $transactionManager->expects('commit')->with('default', 1, 0);

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->transaction(function () {
            $this->connection()->table('users')->where(['name' => 'zain'])->update([
                'value' => 2,
            ]);

            $this->connection()->transaction(function () {
                $this->connection()->table('users')->where(['name' => 'zain'])->update([
                    'value' => 2,
                ]);
            });
        });
    }

    public function testNestedTransactionIsRecordeForDifferentConnectionsdAndCommitted(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $transactionManager->expects('begin')->with('default', 1);
        $transactionManager->expects('begin')->with('second_connection', 1);
        $transactionManager->expects('begin')->with('second_connection', 2);
        $transactionManager->expects('commit')->with('default', 1, 0);
        $transactionManager->expects('commit')->with('second_connection', 2, 1);
        $transactionManager->expects('commit')->with('second_connection', 1, 0);

        $this->connection()->setTransactionManager($transactionManager);
        $this->connection('second_connection')->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->transaction(function () {
            $this->connection()->table('users')->where(['name' => 'zain'])->update([
                'value' => 2,
            ]);

            $this->connection('second_connection')->transaction(function () {
                $this->connection('second_connection')->table('users')->where(['name' => 'zain'])->update([
                    'value' => 2,
                ]);

                $this->connection('second_connection')->transaction(function () {
                    $this->connection('second_connection')->table('users')->where(['name' => 'zain'])->update([
                        'value' => 2,
                    ]);
                });
            });
        });
    }

    public function testTransactionIsRolledBack(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $transactionManager->expects('begin')->with('default', 1);
        $transactionManager->expects('rollback')->with('default', 0);
        $transactionManager->shouldNotReceive('commit');

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        try {
            $this->connection()->transaction(function () {
                $this->connection()->table('users')->where(['name' => 'zain'])->update([
                    'value' => 2,
                ]);

                throw new Exception;
            });
        } catch (Throwable) {
        }
    }

    public function testTransactionIsRolledBackUsingSeparateMethods(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $transactionManager->expects('begin')->with('default', 1);
        $transactionManager->expects('rollback')->with('default', 0);
        $transactionManager->shouldNotReceive('commit');

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->beginTransaction();

        $this->connection()->table('users')->where(['name' => 'zain'])->update([
            'value' => 2,
        ]);

        $this->connection()->rollBack();
    }

    public function testNestedTransactionsAreRolledBack(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $transactionManager->expects('begin')->with('default', 1);
        $transactionManager->expects('begin')->with('default', 2);
        $transactionManager->expects('rollback')->with('default', 1);
        $transactionManager->expects('rollback')->with('default', 0);
        $transactionManager->shouldNotReceive('commit');

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        try {
            $this->connection()->transaction(function () {
                $this->connection()->table('users')->where(['name' => 'zain'])->update([
                    'value' => 2,
                ]);

                $this->connection()->transaction(function () {
                    $this->connection()->table('users')->where(['name' => 'zain'])->update([
                        'value' => 2,
                    ]);

                    throw new Exception;
                });
            });
        } catch (Throwable) {
        }
    }

    public function testAfterCommitRegistersTheCurrentConnectionName(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $callback = static function (): void {
        };

        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->with($callback, 'second_connection');

        $this->connection('second_connection')->setTransactionManager($transactionManager);
        $this->connection('second_connection')->afterCommit($callback);
    }

    public function testApplicableTransactionsAreLimitedToTheirOwningConnection(): void
    {
        $manager = new DatabaseTransactionsManager;
        $first = $this->connection()->setTransactionManager($manager);
        $second = $this->connection('second_connection')->setTransactionManager($manager);

        $this->assertFalse($first->hasApplicableTransaction());
        $this->assertFalse($second->hasApplicableTransaction());

        $first->beginTransaction();

        try {
            $this->assertTrue($first->hasApplicableTransaction());
            $this->assertFalse($second->hasApplicableTransaction());
            $this->assertTrue($manager->hasApplicableTransaction('default'));
            $this->assertFalse($manager->hasApplicableTransaction('second_connection'));
        } finally {
            $first->rollBack();
        }

        $this->assertFalse($first->hasApplicableTransaction());
        $this->assertFalse($manager->hasApplicableTransaction());
    }

    public function testApplicableTransactionsExcludeTestWrappersButIncludeApplicationTransactions(): void
    {
        $manager = new TestingTransactionsManager(['default', 'second_connection']);
        $first = $this->connection();
        $second = $this->connection('second_connection');
        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with('default')->andReturn($first);
        $database->shouldReceive('connection')->once()->with('second_connection')->andReturn($second);
        $manager->beginTransactions($database);

        try {
            $this->assertFalse($first->hasApplicableTransaction());
            $this->assertFalse($second->hasApplicableTransaction());
            $this->assertFalse($manager->hasApplicableTransaction());

            $first->beginTransaction();
            $called = false;
            $first->afterCommitOrNow(function () use (&$called): void {
                $called = true;
            });

            $this->assertTrue($first->hasApplicableTransaction());
            $this->assertFalse($second->hasApplicableTransaction());
            $this->assertFalse($called);
            $first->commit();
            $this->assertTrue($called);
            $this->assertFalse($first->hasApplicableTransaction());
        } finally {
            $first->rollBack(0);
            $second->rollBack(0);
        }
    }

    public function testAnOpenTransactionWithoutAManagerStillCounts(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->assertTrue($connection->hasApplicableTransaction());
        } finally {
            $connection->rollBack();
        }

        $this->assertFalse($connection->hasApplicableTransaction());
    }

    public function testAfterCommitOrNowRunsImmediatelyWithoutAManagerOrTransaction(): void
    {
        $called = false;

        $this->connection()->afterCommitOrNow(function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testAfterCommitOrNowDelegatesToTheTransactionManager(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $callback = static function (): void {
        };

        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->with($callback, 'second_connection');

        $this->connection('second_connection')->setTransactionManager($transactionManager);
        $this->connection('second_connection')->afterCommitOrNow($callback);
    }

    public function testAfterCommitOrNowRequiresAManagerDuringATransaction(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIs('Transactions Manager has not been set.');

            $connection->afterCommitOrNow(static function (): void {
            });
        } finally {
            $connection->rollBack();
        }
    }

    public function testAfterRollbackRegistersTheCurrentConnectionName(): void
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager);
        $callback = static function (): void {
        };

        $transactionManager->shouldReceive('addCallbackForRollback')
            ->once()
            ->with($callback, 'second_connection');

        $this->connection('second_connection')->setTransactionManager($transactionManager);
        $this->connection('second_connection')->afterRollBack($callback);
    }

    public function testAfterCommitRequiresATransactionManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Transactions Manager has not been set.');

        $this->connection()->afterCommit(static function (): void {
        });
    }

    public function testAfterRollbackRequiresATransactionManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Transactions Manager has not been set.');

        $this->connection()->afterRollBack(static function (): void {
        });
    }

    /**
     * Get a schema builder instance.
     */
    protected function schema(string $connection = 'default'): \Hypervel\Database\Schema\Builder
    {
        return $this->connection($connection)->getSchemaBuilder();
    }

    public function connection(string $name = 'default'): \Hypervel\Database\Connection
    {
        return DB::connection($name);
    }
}
